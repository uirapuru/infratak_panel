# Infratak TLS Certificate Runbook

## Cel

Instrukcja operacyjna dla certyfikatu TLS domeny `infratak.com`:

1. jak wykonać standardową aktualizację/odnowienie,
2. jak sprawdzić co jest faktycznie serwowane publicznie,
3. co zrobić awaryjnie, gdy certyfikat jest nieważny albo uszkodzony.

---

## 1. Szybka procedura standardowa

Uruchom z root repozytorium:

```bash
make remote-tls-cert
```

Co robi komenda:

1. sprawdza aktualny certyfikat na serwerze,
2. jeśli cert jest Let’s Encrypt i ma >30 dni ważności, pomija odnowienie,
3. w pozostałych przypadkach uruchamia certbot,
4. restartuje `landing_nginx` po operacji certyfikatu.

Uwaga: podczas kroku certbota jest krótki stop/start `landing_nginx`.

---

## 2. Weryfikacja po operacji

### A. Status certyfikatu po stronie serwera

```bash
make tls-status
```

### B. Certyfikat widoczny publicznie (to widzi przeglądarka)

```bash
echo | openssl s_client -servername infratak.com -connect infratak.com:443 2>/dev/null | openssl x509 -noout -issuer -subject -dates
```

### C. Sprawdzenie walidacji TLS i odpowiedzi HTTP

```bash
curl -Iv https://infratak.com
```

Sygnał poprawności:

1. issuer to Let’s Encrypt,
2. data ważności (`notAfter`) jest w przyszłości,
3. `curl` pokazuje `SSL certificate verify ok`.

---

## 3. Gdy certyfikat stracił ważność lub przeglądarka pokazuje błąd

Wykonaj po kolei:

1. standardowe odnowienie:

```bash
make remote-tls-cert
```

2. ponowna weryfikacja publiczna:

```bash
echo | openssl s_client -servername infratak.com -connect infratak.com:443 2>/dev/null | openssl x509 -noout -issuer -subject -dates
curl -Iv https://infratak.com
```

3. jeśli dalej jest self-signed lub expired, przejdź do procedury awaryjnej poniżej.

---

## 4. Procedura awaryjna: uszkodzona linia certbota

Objawy:

1. certbot twierdzi, że cert jest ważny, ale publicznie widać self-signed/expired,
2. `renewal/<domain>.conf` jest pusty albo niespójny,
3. `live/<domain>` nie wskazuje poprawnie na `archive/<domain>`.

Kroki naprawcze (zachowują backup obecnych plików):

```bash
ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -i ~/.ssh/infratak-prod-key.pem ubuntu@<SERVER_IP> "\
  set -euo pipefail; \
  cd /home/ubuntu/app; \
  docker compose --env-file .env.deploy -f compose.prod.yml stop landing_nginx; \
  TS=\$(date +%Y%m%d%H%M%S); \
  sudo mv var/letsencrypt/live/infratak.com var/letsencrypt/live/infratak.com.bak-\$TS 2>/dev/null || true; \
  sudo rm -f var/letsencrypt/renewal/infratak.com.conf; \
  sudo rm -rf var/letsencrypt/archive/infratak.com; \
  docker run --rm --network host \
    -v \"\$PWD/var/letsencrypt:/etc/letsencrypt\" \
    -v \"\$PWD/var/certbot:/var/lib/letsencrypt\" \
    certbot/certbot certonly --standalone --preferred-challenges http \
    -d infratak.com --email admin@infratak.com --agree-tos --non-interactive; \
  docker compose --env-file .env.deploy -f compose.prod.yml start landing_nginx \
"
```

Następnie zweryfikuj:

```bash
curl -Iv https://infratak.com
```

---

## 5. Typowe przyczyny błędów certyfikatu

1. przeterminowany fallback self-signed (bootstrap),
2. uszkodzony stan `var/letsencrypt/live`, `var/letsencrypt/archive` lub `var/letsencrypt/renewal`,
3. cache przeglądarki/HSTS po stronie klienta,
4. problem DNS (domena nie wskazuje na aktualną instancję).

---

## 6. Dobre praktyki operacyjne

1. po każdym deployu sprawdź cert publiczny przynajmniej raz:

```bash
curl -Iv https://infratak.com
```

2. po incydencie certyfikatu dokumentuj datę, objaw i wynik komend w runbooku operacyjnym,
3. nie usuwaj backupów `live/infratak.com.bak-*` od razu po naprawie (zostaw minimum 24h).
