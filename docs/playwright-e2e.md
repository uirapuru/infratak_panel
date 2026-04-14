# Playwright E2E

Testy end-to-end oparte na [Playwright](https://playwright.dev/). Uruchamiają się w trybie headless na przeglądarce Chromium przeciwko lokalnemu stackowi Docker.

## Wymagania

- Node.js 20+
- Docker Compose (stack lokalny pod `http://127.0.0.1:8080`)

## Instalacja

Jednorazowo — instaluje zależności npm i przeglądarkę Chromium:

```bash
make playwright-install
```

Lub ręcznie:

```bash
npm install
npx playwright install chromium
```

## Uruchomienie testów

### Przez Makefile (zalecane)

```bash
make playwright-test          # headless, uruchamia docker compose jeśli nie działa
make playwright-test-headed   # z widocznym oknem przeglądarki
make playwright-test-ui       # interaktywny UI Playwright (przeglądarka + trace viewer)
```

### Przez npm

Wymaga działającego stacku Docker przed wywołaniem:

```bash
docker compose up -d nginx php mariadb rabbitmq

npm run pw:test               # wszystkie testy, headless
npm run pw:test:headed        # z oknem przeglądarki
npm run pw:test:ui            # interaktywny UI
npm run pw:report             # otwiera raport HTML z ostatniego uruchomienia
```

## Uruchamianie wybranych testów

```bash
# Jeden plik
npx playwright test tests/e2e/order.spec.ts

# Jeden test (dopasowanie po nazwie)
npx playwright test -g "pełny formularz"

# Kilka plików
npx playwright test tests/e2e/order.spec.ts tests/e2e/register.spec.ts
```

## Struktura testów

```
tests/e2e/
├── home.spec.ts        — strona główna (smoke test)
├── register.spec.ts    — rejestracja przez /register (błąd SMTP)
└── order.spec.ts       — ścieżka zamówienia serwera (/zamow → /zamow/rejestracja → /zamow/sukces)
```

### Co pokrywa `order.spec.ts`

| Grupa | Scenariusze |
|---|---|
| Wybór serwera | Ładowanie `/zamow`, kliknięcie Dalej → formularz |
| Walidacja formularza | Puste pola, hasło za krótkie, nieprawidłowa subdomena, live preview domeny, filtr znaków JS |
| Szczęśliwa ścieżka | Pełna rejestracja → sukces (domena, dane logowania), jednorazowość sesji, duplikat e-maila, duplikat subdomeny |

## Debugowanie

### Tryb UI (trace + step-by-step)

```bash
make playwright-test-ui
```

Otwiera przeglądarkowy panel — możesz klikać po krokach, przeglądać screenshoty i sieć.

### Tryb headed (widoczna przeglądarka)

```bash
make playwright-test-headed
```

### Slowmo (spowolnienie wykonania)

```bash
npx playwright test --headed --slow-mo=500
```

### Raport HTML po nieudanych testach

```bash
npm run pw:report
```

Screenshoty, filmy i trace'y z nieudanych testów lądują w `test-results/`.

### Inspektor (zatrzymanie na breakpoincie)

Dodaj do testu:

```ts
await page.pause();
```

Następnie uruchom w trybie headed — Playwright otworzy inspektor w punkcie `pause()`.

## Konfiguracja

Plik `playwright.config.ts` w korzeniu projektu.

Domyślny URL aplikacji: `http://127.0.0.1:8080`  
Nadpisanie przez zmienną środowiskową:

```bash
PLAYWRIGHT_BASE_URL=https://staging.infratak.com npm run pw:test
```

## Izolacja danych testowych

Testy tworzące użytkowników i serwery używają unikalnych wartości opartych na `Date.now()`, więc kolejne uruchomienia nie kolidują ze sobą. Dane testowe pozostają w lokalnej bazie — przy potrzebie czystego stanu wystarczy:

```bash
docker compose down -v && docker compose up -d
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```
