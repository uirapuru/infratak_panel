# Infratak Panel — Deployment Architecture

> Opis przeznaczony jako kontekst dla ChatGPT.  
> Opisuje **cały proces** od świeżego repozytorium do działającego serwera z panelem admina dostępnym przez HTTPS.

---

## 1. Technologie i narzędzia

| Warstwa | Technologia |
|---|---|
| Aplikacja | PHP 8.4 / Symfony, API Platform 4, EasyAdmin |
| Baza danych | MariaDB 11.4 |
| Kolejka wiadomości | RabbitMQ 3.13 |
| Web serwer | Nginx 1.27-alpine |
| Konteneryzacja | Docker Compose v2 (`docker compose`) |
| Budowanie/Deploy | GNU Make (`Makefile`, `Makefile.infra`) |
| Chmura | AWS EC2 (eu-central-1), Route 53 |
| Cloud-init | `user-data.sh` — bootstrap Dockera na nowej maszynie |
| TLS | Certbot / Let's Encrypt (standalone), fallback self-signed |
| Transfer plików | rsync + scp przez SSH |

---

## 2. Struktura repozytorum — kluczowe pliki

```
Makefile            # Orchestracja deploy i operacje na serwerze
Makefile.infra      # Lifecycle infrastruktury AWS
user-data.sh        # Cloud-init — instaluje Docker na nowej EC2
compose.prod.yml    # Definicja wszystkich serwisów produkcyjnych
.env.deploy         # GITIGNORED — sekrety produkcyjne (generowane automatycznie)
.env.deploy.example # Szablon do .env.deploy z placeholderami
.env.infra          # GITIGNORED — dane do AWS (region, zone ID, klucze)
.env.infra.example  # Szablon do .env.infra
docker/
  php/
    Dockerfile        # PHP 8.4-fpm + rozszerzenia (pdo_mysql, amqp, intl, zip)
    entrypoint.sh     # composer install + opcache warm-up przy starcie kontenera
  nginx/
    conf.d/
      landing.conf    # Nginx: http→https redirect, ACME, TLS, landing role
      admin.conf      # Nginx: /admin only, basic auth, rate limiting
      rate_limit.conf # Definicja stref rate-limitingu (http context)
    .htpasswd         # GITIGNORED — login:bcrypt_hash dla basic auth admina
```

---

## 3. Serwisy Docker Compose (compose.prod.yml)

Wszystkie serwisy PHP ładują sekrety przez `env_file: .env.deploy`.
Kontenery PHP/worker montują też profil AWS z `./var/share/.aws:/root/.aws:ro` (używany przez AWS SDK przy `AWS_SDK_LOAD_CONFIG=1`).

```
landing_php    php:8.4-fpm  APP_ROLE=landing  ← obsługuje stronę publiczną
landing_nginx  nginx:1.27   :80, :443         ← http→https, TLS, proxy /admin → admin_nginx
admin_php      php:8.4-fpm  APP_ROLE=admin    ← obsługuje panel EasyAdmin
admin_nginx    nginx:1.27   :8081             ← basic auth, rate limit, tylko /admin
worker_provisioning  php:8.4-fpm  messenger:consume provisioning
worker_projection    php:8.4-fpm  messenger:consume projection
mariadb        mariadb:11.4                   ← healthcheck przed startem PHP
rabbitmq       rabbitmq:3.13-management       ← healthcheck przed startem PHP
```

### Podział ról (landing vs. admin)

- `landing_nginx` nasłuchuje na `:80` i `:443`. Ruch `/admin` proxy'uje do `admin_nginx`.
- `admin_nginx` nasłuchuje na `:8081`. Wymaga HTTP Basic Auth (`docker/nginx/.htpasswd`).
- Instancja PHP aplikacji jest budowana raz z jednego `Dockerfile`, ale `APP_ROLE` w `environment:` zmienia zachowanie runtime Symfony.

---

## 4. Pliki środowiskowe

### `.env.deploy` (produkcyjne sekrety — GITIGNORED)

Generowany automatycznie przez `make deploy-prepare-env`. Zawiera:

```ini
MARIADB_DATABASE=app
MARIADB_USER=app
MARIADB_PASSWORD=<losowy 40-znakowy token>
MARIADB_ROOT_PASSWORD=<losowy 40-znakowy token>
RABBITMQ_DEFAULT_USER=guest
RABBITMQ_DEFAULT_PASS=<losowy 40-znakowy token>
APP_SECRET=<losowy 40-znakowy token>
ADMIN_BASIC_AUTH_USER=admin
ADMIN_BASIC_AUTH_PASSWORD=<losowy 40-znakowy token>
DATABASE_URL=mysql://app:<pass>@mariadb:3306/app?serverVersion=11.4.2-MariaDB&charset=utf8mb4
MESSENGER_PROVISIONING_TRANSPORT_DSN=amqp://guest:<pass>@rabbitmq:5672/%2f/messages
MESSENGER_PROJECTION_TRANSPORT_DSN=amqp://guest:<pass>@rabbitmq:5672/%2f/messages
AWS_REGION=eu-central-1
...
```

**Polityka rotacji sekretów:**
- **Stanowe** (MariaDB, RabbitMQ) — generowane raz przy első deploy, nigdy nie rotowane automatycznie (zmiana wymagałaby migracji danych).
- **Bezstanowe** (APP_SECRET, ADMIN_BASIC_AUTH_PASSWORD) — można wymusić rotację przez `make deploy-rotate-secrets`.

**AWS credentials/profile:**
- `deploy-prepare-env` przygotowuje profil AWS w `var/share/.aws`.
- Źródło credentials: najpierw `.env.infra` (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, opcjonalnie `AWS_SESSION_TOKEN`), a jeśli brak — fallback do lokalnego `~/.aws`.
- `AWS_PROFILE` jest ustawiany w `.env.deploy`, a kontenery używają go przez AWS SDK.

### `.env.infra` (konfiguracja AWS — GITIGNORED)

```ini
AWS_REGION=eu-central-1
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
ROUTE53_ZONE_ID=Z0843124BD4D2BF9GZ0S
```

---

## 5. Podział komend: setup vs. deploy-prod

```
make setup           # JEDNORAZOWE — tworzy EC2 + DNS + pierwszy deploy
  └─ make infra-up          → tworzy EC2 + DNS, zapisuje IP do .server-ip
  └─ make infra-wait-ready  → czeka na cloud-init i Docker (max 10 min)
  └─ make deploy-prod       → pełny deploy aplikacji

make deploy-prod     # CODZIENNE — synchronizuje kod z istniejącą instancją
```

`deploy-one` jest zachowany jako alias do `setup` dla kompatybilności wstecznej.

---

## 6. Szczegółowy przebieg `make deploy-prod`

### Krok 0: deploy-prepare-env (lokalnie)

1. Jeśli `.env.deploy` nie istnieje — kopiuje z `.env.deploy.example`.
2. Filtruje sekrety ze stałymi placeholderami (`change-me`, `change-app-secret`, itp.) i zastępuje je losowymi 40-znakowymi tokenami (generowanymi przez `openssl rand -base64 32`).
3. Oblicza i wstawia do `.env.deploy`:
   - `DATABASE_URL` — z aktualnych wartości `MARIADB_USER`, `MARIADB_PASSWORD`, `MARIADB_DATABASE`.
   - `MESSENGER_PROVISIONING_TRANSPORT_DSN` i `MESSENGER_PROJECTION_TRANSPORT_DSN` — z `RABBITMQ_DEFAULT_USER` + `RABBITMQ_DEFAULT_PASS`.
4. Generuje `docker/nginx/.htpasswd` z aktualnego `ADMIN_BASIC_AUTH_USER` + `ADMIN_BASIC_AUTH_PASSWORD` (bcrypt przez `htpasswd -nbB` albo `openssl passwd -apr1`).
5. Synchronizuje `AWS_ROUTE53_HOSTED_ZONE_ID` z `.env.infra` (zapobiega użyciu złej strefy Route53 przez provisioning).

### Krok 1: deploy-check (lokalnie, fail-fast)

Sprawdza przed jakimkolwiek transferem:
- `SERVER_IP` jest ustawione i nie jest `None`
- Plik `.env.deploy` istnieje
- Plik `docker/nginx/.htpasswd` istnieje
- `TLS_DOMAIN` i `LETSENCRYPT_EMAIL` są ustawione

### Krok 2: rsync (lokalnie → serwer)

```bash
rsync -avz --delete \
  --exclude .git \
  --exclude var/letsencrypt \
  --exclude var/certbot \
  ./ ubuntu@<IP>:/home/ubuntu/app
```

Wykluczone są certyfikaty i dane certbota — żyją tylko po stronie serwera i nie są nadpisywane przy każdym deploy.

### Krok 3: upload .env.deploy przez scp

Plik sekretów jest przesyłany osobno po rsync, żeby mieć pewność aktualizacji nawet jeśli rsync zignorował go przez .gitignore.

### Krok 4: Bootstrap certyfikatu TLS (na serwerze)

```bash
sudo mkdir -p var/letsencrypt/live/infratak.com var/certbot
# Jeśli cert nie istnieje — generuje self-signed fallback (ważny 1 dzień)
sudo openssl req -x509 -nodes -newkey rsa:2048 \
  -keyout var/letsencrypt/live/infratak.com/privkey.pem \
  -out  var/letsencrypt/live/infratak.com/fullchain.pem \
  -days 1 -subj '/CN=infratak.com'
```

Nginx **nie startuje** bez istniejącego pliku certyfikatu (bo `ssl_certificate` jest wpisane na stałe w `landing.conf`). Ten krok gwarantuje, że zawsze jest jakiś plik — nawet self-signed.

### Krok 5: Aktualizacja stack (na serwerze)

```bash
docker compose --env-file .env.deploy -f compose.prod.yml up -d --build --remove-orphans
```

Buduje obrazy PHP z lokalnego `Dockerfile` i restartuje **tylko serwisy, których obraz lub konfiguracja się zmieniła**. MariaDB i RabbitMQ nie są restartowane jeśli ich definicja nie zmieniła się — baza danych pozostaje nienaruszona. Brak `down` eliminuje downtime przy każdym deploy.

### Krok 6: remote-tls-cert — certbot (na serwerze)

Najpierw sprawdzany jest warunek pomijania (`checkend 2592000` = 30 dni):

- Jeśli certyfikat **istnieje**, jest od **Let's Encrypt** i **wygasa za >30 dni** → certbot jest pomijany całkowicie, nginx nie jest zatrzymywany.
- W każdym innym przypadku (brak certyfikatu, self-signed, wygasa wkrótce):
  1. Zatrzymuje `landing_nginx` (certbot standalone musi zająć port 80).
  2. Ustawia flagę: `--force-renewal` (brak/self-signed) lub `--keep-until-expiring` (LE, kończy się).
  3. Uruchamia certbot w kontenerze Docker:
     ```bash
     docker run --rm --network host \
       -v "$PWD/var/letsencrypt:/etc/letsencrypt" \
       -v "$PWD/var/certbot:/var/lib/letsencrypt" \
       certbot/certbot certonly \
         --standalone --preferred-challenges http \
         -d infratak.com \
         --email admin@infratak.com \
         --agree-tos --non-interactive <CERTBOT_FLAGS>
     ```
  4. Jeśli certbot się nie powiedzie — przywraca self-signed fallback. **Nginx zawsze wróci do działania.**
  5. Startuje `landing_nginx`.

Efekt: nginx jest zatrzymywany **co najwyżej raz na ~60 dni** (Let's Encrypt odnawia przy <30 dni ważności), a nie przy każdym deploy.

### Krok 7: Migracje (na serwerze)

```bash
docker compose exec -T landing_php php bin/console doctrine:migrations:migrate --no-interaction
```

Uruchamia wszystkie oczekujące migracje Doctrine. Flaga `transactional: false` w `doctrine_migrations.yaml` (wymóg dla MariaDB z niektórymi DDL).

### Krok 8: Cache clear (na serwerze)

```bash
docker compose exec -T landing_php php bin/console cache:clear --env=prod
```

---

## 7. Provisioning infrastruktury: `make -f Makefile.infra create-instance`

Wykonywa 7 kroków:

1. **Pobiera AMI** Ubuntu 22.04 z AWS SSM Parameter Store (dynamicznie, bez hardkodowanego ID)
2. **Tworzy/sprawdza SSH keypair** `infratak-prod-key` w EC2; zapisuje klucz do `~/.ssh/infratak-prod-key.pem`
3. **Tworzy/sprawdza Security Group** `infratak-prod-sg` z regułami ingressu:
   - TCP 22 (SSH)
   - TCP 80 (HTTP)
   - TCP 443 (HTTPS)
4. **Sprawdza czy instancja już istnieje** (po tagu `Name=infratak-prod`) — idempotentne
5. **Uruchamia instancję EC2** (`t3.small`, Ubuntu 22.04) z `user-data.sh` jako cloud-init
6. **Czeka na stan `running`** przez `aws ec2 wait instance-running`
7. **Tworzy/aktualizuje rekord DNS Route 53** (UPSERT A-record `infratak.com → <public IP>`)
8. **Zapisuje publiczne IP do `.server-ip`** lokalnie — kolejne wywołania `make deploy-prod` czytają IP z tego pliku zamiast pytać AWS API

### user-data.sh — co instaluje cloud-init

```bash
apt-get install -y docker.io docker-compose-v2
systemctl enable docker
systemctl start docker
usermod -aG docker ubuntu
```

Minimalny bootstrap — tylko Docker. Kod aplikacji jest wgrywany przez rsync w trakcie deploy.

---

## 8. Czekanie na gotowość: `make infra-wait-ready`

Po provisioning serwer potrzebuje czasu na cloud-init. Target sprawdza co 10 sekund przez SSH:

```bash
cloud-init status --wait && command -v docker && docker compose version
```

Timeout po 60 próbach (10 minut).

---

## 9. TLS — nowe targety konserwacyjne

```bash
make tls-status   # pokazuje issuer, subject, daty ważności certyfikatu z serwera
make tls-renew    # wymusza próbę odnowienia + pokazuje status po
```

`tls-renew` wywołuje ten sam helper `remote-tls-cert` co `deploy-prod`, więc logika jest identyczna.

**Kiedy używać `tls-renew` bez pełnego deploy:**
- Po wygaśnięciu okna rate-limit Let's Encrypt
- Przy manualnym odnawianiu certyfikatu
- Gdy `tls-status` pokazuje issuer `CN=infratak.com` (self-signed) zamiast `Let's Encrypt`

---

## 10. Usuwanie infrastruktury

```bash
make -f Makefile.infra destroy-all
```

1. Usuwa IP serwera z rekordu A w Route 53
2. Terminuje instancję EC2
3. Czeka na `instance-terminated`
4. Usuwa lokalny plik `.server-ip`

---

## 11. Architektura sieciowa serwisu

```
Internet
  │
  ├── :80  → landing_nginx → HTTP 301 → https://infratak.com
  │
  └── :443 → landing_nginx (TLS termination)
               ├── /health         → index.php (healthcheck)
               ├── /.well-known/   → /var/www/certbot (ACME)
               ├── /admin          → proxy_pass → admin_nginx
               └── /*              → landing_php:9000 (FastCGI)

  :8081 → admin_nginx (Basic Auth)
               └── /*              → admin_php:9000 (FastCGI)
                                      └── /admin/* → EasyAdmin
```

---

## 12. Zmienne Make możliwe do nadpisania

| Zmienna | Domyślna wartość | Opis |
|---|---|---|
| `ENV_FILE` | `.env.deploy` | Plik z sekretami |
| `COMPOSE_FILE` | `compose.prod.yml` | Plik compose |
| `SERVER_IP` | z `.server-ip` (fallback: `Makefile.infra get-ip`) | IP serwera |
| `SERVER_USER` | `ubuntu` | Użytkownik SSH |
| `SSH_KEY` | `~/.ssh/infratak-prod-key.pem` | Klucz prywatny SSH |
| `APP_DIR` | `/home/ubuntu/app` | Katalog aplikacji na serwerze |
| `TLS_DOMAIN` | `infratak.com` | Domena dla certbota i nginx |
| `LETSENCRYPT_EMAIL` | `admin@infratak.com` | Email dla Let's Encrypt |

---

## 13. Kompletna sekwencja: od zera do działającego serwera

```bash
# 1. Skonfiguruj lokalne pliki środowiskowe
cp .env.infra.example .env.infra     # uzupełnij AWS_ACCESS_KEY_ID, SECRET, ROUTE53_ZONE_ID
# .env.deploy jest generowany automatycznie przy pierwszym deploy

# 2. Jednorazowy setup (tworzy EC2 + DNS + wdraża aplikację)
make setup
# → zapisuje IP serwera do .server-ip

# Po sukcesie:
# - https://infratak.com        → strona publiczna (Symfony landing)
# - http://infratak.com         → redirect 301 do HTTPS
# - https://infratak.com/admin  → panel admina (basic auth + login Symfony)

# 3. Kolejne deploye (tylko synchronizacja kodu)
make deploy-prod
# → rsync kodu, docker compose up (bez down), warunkowy certbot, migracje

# 4. Sprawdź certyfikat TLS
make tls-status
# Jeśli wynik: issuer=CN=infratak.com → self-signed (rate limit lub brak DNS)
# Jeśli wynik: issuer=Let's Encrypt   → certyfikat zaufany przez przeglądarki

# 5. Ręczne odnowienie certyfikatu (np. po rate-limit cooldown):
make tls-renew

# 6. Utworzenie pierwszego użytkownika panelu admina
ssh -i ~/.ssh/infratak-prod-key.pem ubuntu@infratak.com
cd /home/ubuntu/app
docker compose --env-file .env.deploy -f compose.prod.yml exec -T admin_php \
  php bin/console app:admin:create-user administrator@infratak.com '<haslo>'

# 7. Reset hasła istniejącego użytkownika panelu
docker compose --env-file .env.deploy -f compose.prod.yml exec -T admin_php \
  php bin/console app:admin:set-password administrator@infratak.com '<nowe-haslo>'
```

---

## 14. Znane ograniczenia i uwagi

- **Let's Encrypt rate limit**: maksymalnie 5 certyfikatów dla tej samej domeny w ciągu 7 dni. Przy częstym reprovisioning'u można trafić na blokadę — wtedy deploy kończy się z certyfikatem self-signed (przeglądarka pokazuje ostrzeżenie), a `make tls-renew` odblokuje sytuację po wygaśnięciu okna. Przy regularnym używaniu `deploy-prod` (bez demolowania instancji) certbot odpala się co ~60 dni i rate limit nie jest problemem.
- **Dane bazy danych przy `deploy-prod`**: bezpieczne — `docker compose up` bez `down` nie usuwa named volumes (`mariadb_data`). Dane są tracone tylko przy terminacji instancji EC2.
- **Dane bazy danych przy `destroy-all`**: trwale utracone — brak mechanizmu backupu w pipeline. Należy ręcznie zrobić dump przed demolowaniem.
- **Stateful sekrety**: hasła do MariaDB i RabbitMQ są generowane raz. Ręczna zmiana wymaga też migracji danych w bazie.
- **Port 8081 dla admin_nginx**: serwis admina działa wewnętrznie na `:8081` bez TLS, ale ruch użytkownika do panelu idzie przez `landing_nginx` po HTTPS (`/admin` → proxy do `admin_nginx`). Zalecane jest ograniczenie publicznego dostępu do `:8081` na poziomie security group.
- **Single-host deployment**: cały stack (PHP, nginx, MariaDB, RabbitMQ, workers) działa na jednej instancji EC2 `t3.small`. Nie ma skalowalności horyzontalnej.
- **`.server-ip`**: plik lokalny z IP serwera (gitignored). Jeśli go usuniesz lub zmieni się IP, `make deploy-prod` automatycznie odpyta AWS (fallback w `SERVER_IP`).
