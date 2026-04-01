# Połączenie do zdalnej bazy danych z pomocą tunelu SSH

Instrukcja pozwalająca na połączenie się do bazy danych MariaDB działającej na serwerze produkcyjnym za pośrednictwem tunelu SSH.

---

## Warunki wstępne

- Klucz SSH: `~/.ssh/infratak-prod-key.pem`
- Dostęp SSH jako: `ubuntu@infratak.com`
- Dane dostępu do bazy z `.env.deploy` (MARIADB_USER, MARIADB_PASSWORD)

---

## Krok 1: Sprawdzenie nazwy kontenera

Najpierw upewnij się, jaka jest dokładna nazwa kontenera MariaDB:

```bash
ssh -i ~/.ssh/infratak-prod-key.pem ubuntu@infratak.com "docker ps | grep -i mariadb"
```

Zwróć uwagę na kolumnę **NAMES** — powinna to być coś w rodzaju `app-mariadb-1`.

---

## Krok 2: Otwarcie tunelu SSH

Użyj poniższej komendy, **zastępując `app-mariadb-1` rzeczywistą nazwą kontenera**:

```bash
ssh -i ~/.ssh/infratak-prod-key.pem -L 9999:$(ssh -i ~/.ssh/infratak-prod-key.pem ubuntu@infratak.com "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' app-mariadb-1"):3306 ubuntu@infratak.com -N
```

**Co to robi:**
- `-L 9999:...:3306` — mapuje port 9999 na `localhost` do portu 3306 bazy danych na serwerze
- `-N` — nie otwiera interaktywnego shella, tunel działa w tle

Po wykonaniu komenda **nie wyświetli żadnego output** — to oznacza, że tunel działa poprawnie.

---

## Krok 3: Pobranie danych dostępu

Pobierz dane do bazy z `.env.deploy`:

```bash
grep -E "MARIADB_(USER|PASSWORD|DATABASE)" .env.deploy
```

Zwróci coś w rodzaju:
```
MARIADB_DATABASE=app
MARIADB_USER=app
MARIADB_PASSWORD=<hasło>
```

---

## Krok 4: Połączenie z bazą danych

### Opcja A: Wiersz poleceń (mysql/mariadb)

```bash
mysql -h 127.0.0.1 -P 9999 -u app -p app
```

Po wpisaniu komendy, podaj hasło z `MARIADB_PASSWORD`.

### Opcja B: Narzędzie graficzne (MySQL Workbench, DBeaver, itp.)

W ustawieniach połączenia uzupełnij:

| Parametr | Wartość |
|----------|---------|
| **Host** | `127.0.0.1` lub `localhost` |
| **Port** | `9999` |
| **User** | `app` |
| **Password** | Hasło z `.env.deploy` (`MARIADB_PASSWORD`) |
| **Database** | `app` |

### Opcja C: Aplikacja (PHP/Symfony)

W pliku konfiguracyjnym lub zmiennej środowiskowej ustaw:

```
DATABASE_URL=mysql://app:<hasło>@127.0.0.1:9999/app?serverVersion=11.4.2-MariaDB&charset=utf8mb4
```

---

## Problemy i rozwiązania

### Error: "No such object: app-mariadb-1"

Kontener o tej nazwie nie istnieje. Sprawdź faktyczną nazwę:

```bash
ssh -i ~/.ssh/infratak-prod-key.pem ubuntu@infratak.com "docker ps"
```

i użyj nazwy z kolumny **NAMES**.

### Error: "connect failed: Temporary failure in name resolution"

Stara komenda z nazwą serwisu `mariadb` zamiast IP kontenera. Użyj komendy z Kroku 2 — celle w niej pobierze automatycznie IP.

### Aplikacja nie łączy się: "socket has unexpectedly been closed"

Sprawdź:
1. Czy tunel SSH jest aktywny (uruchom go jeszcze raz)
2. Czy host, port i dane dostępu są poprawne
3. Czy aplikacja łączy się do `127.0.0.1:9999`, a nie do innego portu

---

## Zamykanie tunelu

Tunel będzie aktywny dopóki terminal SSH nie zostanie zamknięty. Możesz:

- **Zamknąć terminal**: <kbd>Ctrl+C</kbd>
- **Uruchomić tunel w tle** — dodaj `&` na końcu komendy i będziesz mógł korzystać z tego samego terminala

```bash
ssh -i ~/.ssh/infratak-prod-key.pem -L 9999:$(ssh -i ~/.ssh/infratak-prod-key.pem ubuntu@infratak.com "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' app-mariadb-1"):3306 ubuntu@infratak.com -N &
```

By zabić tunel, wpisz:

```bash
kill %1
```

---

## Alternatywnie: Inny port lokalny

Jeśli port `9999` jest zajęty, zmień numer na początkowy `-L`:

```bash
ssh -i ~/.ssh/infratak-prod-key.pem -L 3333:$(ssh -i ~/.ssh/infratak-prod-key.pem ubuntu@infratak.com "docker inspect -f '{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}' app-mariadb-1"):3306 ubuntu@infratak.com -N
```

Wtedy aplikacja łączy się do portu `3333` zamiast `9999`.
