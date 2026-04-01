# Playwright E2E Setup

## Wymagania
- Node.js 20+
- npm
- Uruchomiony lokalnie stack Docker Compose (aplikacja pod `http://127.0.0.1:8080`)

## Instalacja
1. Zainstaluj zaleznosci Node:
   ```bash
   npm install
   ```
2. Zainstaluj przegladarke Chromium dla Playwright:
   ```bash
   npm run pw:install
   ```

## Uruchomienie testow
1. Upewnij sie, ze aplikacja dziala lokalnie:
   ```bash
   docker compose up -d nginx php mariadb rabbitmq
   ```
2. Odpal testy:
   ```bash
   npm run pw:test
   ```

## Przydatne komendy
- Tryb UI: `npm run pw:test:ui`
- Tryb headed: `npm run pw:test:headed`
- Raport HTML: `npm run pw:report`

## Zmiana URL aplikacji
Domyslnie testy uzywaja `http://127.0.0.1:8080`.
Mozesz nadpisac URL zmienna srodowiskowa:

```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8080 npm run pw:test
```
