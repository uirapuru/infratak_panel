# OpenTAKServer API Client Guide (PL)

Data opracowania: 30 marca 2026

Ten dokument opisuje, jak zbudowac dzialajacego klienta API dla OpenTAKServer (OTS), z naciskiem na:

- zdalne utworzenie konta uzytkownika (i konta administratora, jesli masz uprawnienia),
- uruchomienie tworzenia data package z informacja o polaczeniu,
- wygenerowanie QR code do pobrania/konfiguracji po stronie ATAK.

Dokument jest oparty na aktualnej implementacji endpointow w kodzie projektu.

## 1. Najwazniejsze fakty o API

OTS udostepnia dwie glowne warstwy HTTP:

- `OTS API` (sciezki `/api/...`) - API administracyjne i webowe.
- `MARTI API` (sciezki `/Marti/...`) - endpointy zgodne z ekosystemem TAK/ATAK (m.in. enrollment i data package sync).

W praktyce klient automatyzacyjny dla admina powinien korzystac glownie z `/api/...`, a tylko tam gdzie trzeba z `/Marti/...`.

## 2. Uwierzytelnianie i tokeny

OTS ma kilka mechanizmow auth. Najwazniejsze dla klienta API:

## 2.1. Sesja + CSRF (najpewniejsze dla `/api/...`)

Mechanizm zgodny z Flask-Security. Przeplyw:

1. `GET /api/login` - pobranie `csrf_token`.
2. `POST /api/login` z JSON `{"username": "...", "password": "..."}` i naglowkiem `X-CSRFToken`.
3. Trzymanie cookie sesji i uzywanie go przy kolejnych requestach.

Przyklad `curl`:

```bash
BASE="https://twoj-serwer:8443"
USER="administrator"
PASS="password"

# 1) pobierz csrf
CSRF=$(curl -k -s -c cookies.txt "$BASE/api/login" | jq -r '.response.csrf_token')

# 2) zaloguj sie
curl -k -s -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRFToken: $CSRF" \
  -X POST "$BASE/api/login" \
  -d "{\"username\":\"$USER\",\"password\":\"$PASS\"}"

# 3) test sesji
curl -k -s -b cookies.txt "$BASE/api/me"
```

Uwagi:

- Domyslnie (jesli brak admina) OTS tworzy konto `administrator` z haslem `password` - zmien od razu po uruchomieniu.
- Endpointy chronione przez `@auth_required()` i role (`@roles_accepted("administrator")`) beda dzialac po poprawnym loginie i z odpowiednimi rolami.

## 2.2. OAuth-like token dla CloudTAK/TAKX

- Endpoint: `GET|POST /oauth/token?username=...&password=...`
- Odpowiedz: `{"access_token":"...","token_type":"Bearer","expires_in":31536000}`

To token JWT podpisany kluczem serwera (RS256). Najczesciej potrzebny dla integracji TAKX/CloudTAK, nie jako podstawowa metoda do admin API w panelu.

## 2.3. Token enrollment ATAK (QR string)

- Endpoint: `POST /api/atak_qr_string` (wymaga zalogowania `@auth_required()`)
- Tworzy/aktualizuje token przypisany do usera i zwraca `qr_string` w formacie:
  `tak://com.atakmap.app/enroll?host=...&username=...&token=...`

To najlepsza droga do QR onboardingowego ATAK.

## 2.4. Basic Auth dla niektorych endpointow MARTI enrollment

Np. `POST /Marti/api/tls/signClient/v2` sprawdza `Authorization: Basic ...` i fallbackowo akceptuje tez token enrollment jako "haslo".

## 3. Role i ograniczenia

- Tworzenie usera: tylko administrator (`/api/user/add`).
- Nadanie roli `administrator`: tylko administrator.
- Gdy `OTS_ENABLE_LDAP=True`, lokalne endpointy zarzadzania userami zwracaja blad i trzeba operowac przez LDAP.

## 4. Wymagane funkcje klienta - gotowe przeplywy

## 4.1. Zdalne tworzenie konta uzytkownika (i admina)

Endpoint:

- `POST /api/user/add`

Wymagany JSON:

```json
{
  "username": "nowy_user",
  "password": "MocneHaslo123!",
  "confirm_password": "MocneHaslo123!",
  "roles": ["user"]
}
```

Aby utworzyc konto admina:

```json
{
  "username": "nowy_admin",
  "password": "MocneHaslo123!",
  "confirm_password": "MocneHaslo123!",
  "roles": ["administrator"]
}
```

Przyklad `curl` (po zalogowaniu i z `cookies.txt`):

```bash
curl -k -s -b cookies.txt \
  -H "Content-Type: application/json" \
  -X POST "$BASE/api/user/add" \
  -d '{
    "username":"atak_user_01",
    "password":"MocneHaslo123!",
    "confirm_password":"MocneHaslo123!",
    "roles":["user"]
  }'
```

Typowe odpowiedzi:

- `200 {"success": true}`
- `400` np. user istnieje / role niepoprawne / hasla niezgodne
- `403` gdy probujesz admin role bez odpowiednich uprawnien

## 4.2. Utworzenie data package z informacja o polaczeniu

Najbardziej praktyczna droga dla paczki polaczeniowej ATAK/iTAK:

1. Wywolaj `POST /api/certificate` z `username`.
2. OTS wygeneruje certy i doda data package (np. `<username>_CONFIG.zip`, `<username>_CONFIG_iTAK.zip`).
3. Pobierz hash paczki przez `GET /api/data_packages?filename=<nazwa>&per_page=1`.
4. Pobierz plik przez `GET /api/data_packages/download?hash=<hash>`.

Krok 1 - generowanie:

```bash
curl -k -s -b cookies.txt \
  -H "Content-Type: application/json" \
  -X POST "$BASE/api/certificate" \
  -d '{"username":"atak_user_01"}'
```

Krok 2 - znalezienie paczki:

```bash
curl -k -s -b cookies.txt \
  "$BASE/api/data_packages?filename=atak_user_01_CONFIG.zip&per_page=1"
```

Krok 3 - pobranie po hashu:

```bash
HASH="<hash_z_api_data_packages>"
curl -k -L -b cookies.txt \
  "$BASE/api/data_packages/download?hash=$HASH" \
  -o atak_user_01_CONFIG.zip
```

Uwaga:

- `POST /api/certificate` zwraca tylko `{"success": true}` bez hashy, dlatego trzeba wykonac odczyt listy paczek.
- Dla iTAK analogicznie szukaj nazwy `<username>_CONFIG_iTAK.zip`.

## 4.3. Generowanie QR code dla ATAK

Sa dwa sensowne scenariusze:

### A) QR enrollment (rekomendowany)

1. `POST /api/atak_qr_string`
2. Pobierz pole `qr_string`.
3. Zamien string na obraz QR po stronie klienta (Python/Node/mobile).

Przyklad:

```bash
curl -k -s -b cookies.txt \
  -H "Content-Type: application/json" \
  -X POST "$BASE/api/atak_qr_string" \
  -d '{"username":"atak_user_01","max":1}'
```

Przykladowa odpowiedz:

```json
{
  "success": true,
  "sub": "atak_user_01",
  "iat": 1760000000,
  "exp": null,
  "nbf": null,
  "max": 1,
  "disabled": false,
  "qr_string": "tak://com.atakmap.app/enroll?host=...&username=atak_user_01&token=..."
}
```

### B) QR do pobrania konkretnej paczki ZIP

OTS nie ma dedykowanego endpointu "zrob mi obraz QR PNG" dla data package URL. Robisz to po stronie klienta:

1. Zbuduj URL pobrania: `https://host:8443/api/data_packages/download?hash=...`
2. Wygeneruj QR z tego URL lokalnie biblioteka QR.

## 5. Endpoints referencyjne (pod Twoj klient)

## 5.1. Auth i sesja

- `GET /api/login` - pobranie CSRF
- `POST /api/login` - logowanie
- `GET /api/logout` - wylogowanie
- `GET|POST /oauth/token` - token Bearer (CloudTAK/TAKX)

## 5.2. Uzytkownicy

- `POST /api/user/add` - tworzenie usera/admina
- `POST /api/user/role` - zmiana rol
- `POST /api/user/password/reset` - reset hasla przez admina
- `GET /api/users`, `GET /api/users/all` - lista userow

## 5.3. Data package

- `POST /api/certificate` - tworzenie cert + paczek konfiguracyjnych
- `GET /api/data_packages` - lista paczek (filtrowanie m.in. po `filename`, `hash`)
- `GET /api/data_packages/download?hash=...` - pobranie ZIP
- `POST /api/data_packages` - upload paczki (multipart/form-data)
- `PATCH /api/data_packages` - ustawienia instalacji (`install_on_enrollment`, `install_on_connection`)

## 5.4. ATAK QR / enrollment token

- `POST /api/atak_qr_string` - utworzenie/odswiezenie tokena i zwrot `qr_string`
- `GET /api/atak_qr_string?username=...` - odczyt istniejacego tokena
- `DELETE /api/atak_qr_string?username=...` - usuniecie tokena

## 6. Minimalny klient Python (end-to-end)

Ponizszy przyklad:

- loguje admina,
- tworzy usera,
- generuje paczke konfiguracyjna,
- pobiera hash paczki,
- pobiera `qr_string` ATAK,
- zapisuje obraz QR do pliku PNG.

```python
import requests
import qrcode

BASE = "https://twoj-serwer:8443"
ADMIN_USER = "administrator"
ADMIN_PASS = "password"
NEW_USER = "atak_user_01"
NEW_PASS = "MocneHaslo123!"

s = requests.Session()
s.verify = False  # produkcyjnie ustaw poprawny cert

# 1) csrf + login
csrf = s.get(f"{BASE}/api/login").json()["response"]["csrf_token"]
login = s.post(
    f"{BASE}/api/login",
    json={"username": ADMIN_USER, "password": ADMIN_PASS},
    headers={"X-CSRFToken": csrf},
)
login.raise_for_status()

# 2) create user (idempotentnie: jesli istnieje, zignoruj)
r = s.post(
    f"{BASE}/api/user/add",
    json={
        "username": NEW_USER,
        "password": NEW_PASS,
        "confirm_password": NEW_PASS,
        "roles": ["user"],
    },
)
if r.status_code not in (200, 400):
    r.raise_for_status()

# 3) create cert/config data package
r = s.post(f"{BASE}/api/certificate", json={"username": NEW_USER})
r.raise_for_status()

# 4) find package hash
r = s.get(
    f"{BASE}/api/data_packages",
    params={"filename": f"{NEW_USER}_CONFIG.zip", "per_page": 1},
)
r.raise_for_status()
rows = r.json().get("results", [])
if not rows:
    raise RuntimeError("Nie znaleziono paczki konfiguracyjnej")
package_hash = rows[0]["hash"]

# 5) enrollment qr string
r = s.post(
    f"{BASE}/api/atak_qr_string",
    json={"username": NEW_USER, "max": 1},
)
r.raise_for_status()
qr_string = r.json()["qr_string"]

# 6) save QR png
img = qrcode.make(qr_string)
img.save(f"{NEW_USER}_atak_enroll_qr.png")

# 7) optional: direct package download url
download_url = f"{BASE}/api/data_packages/download?hash={package_hash}"
print("QR enrollment:", qr_string)
print("Data package URL:", download_url)
```

Instalacja zaleznosci:

```bash
pip install requests qrcode[pil]
```

## 7. Bezpieczenstwo i praktyka produkcyjna

- Natychmiast zmien domyslne haslo admina.
- Nie trzymaj hasel i tokenow w kodzie; uzyj zmiennych srodowiskowych/secret managera.
- Ogranicz role i tworz oddzielne konto techniczne dla automatyzacji.
- Jezeli korzystasz z QR enrollment, ustaw `max` oraz ewentualnie `exp`/`nbf`.
- Wlaczone LDAP zmienia model zarzadzania kontami (tworzenie userow lokalnie moze byc zablokowane).

## 8. Co warto dopisac w Twoim kliencie

- Retry i timeouty na requestach.
- Czytelne mapowanie bledow (`400`, `401`, `403`, `404`, `500`).
- Tryb idempotentny (np. user juz istnieje).
- Logowanie audytowe operacji admina.

---

Jesli chcesz, moge w kolejnym kroku dopisac gotowy klient CLI (np. Python `argparse` albo Node.js) z komendami:

- `create-user`
- `create-config-package`
- `generate-atak-qr`
- `generate-download-qr`
