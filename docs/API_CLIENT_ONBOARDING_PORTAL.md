# OpenTAK Onboarding Portal - REST API Documentation

Data opracowania: 30 marca 2026  
Źródło: https://github.com/mattronix/opentak-onboarding-portal

## Ogólne informacje

- **URL API**: `/api/v1` (np. `http://localhost:5000/api/v1`)
- **Autentykacja**: JWT (JSON Web Token)
- **Format**: JSON (application/json)
- **Dokumentacja interaktywna**: `/api/docs` (Swagger UI)
- **OpenAPI spec**: `/api/v1/swagger.json`

---

## 1. Logowanie i uzyskanie tokenu (JWT)

```
POST /api/v1/auth/login
Content-Type: application/json
```

**Żądanie:**
```json
{
  "username": "string (required)",
  "password": "string (required)"
}
```

**Odpowiedź 200:**
```json
{
  "access_token": "string (JWT, 12h)",
  "refresh_token": "string (JWT, 30d)",
  "needs_profile_completion": false,
  "user": {
    "id": 1,
    "username": "string",
    "email": "string",
    "firstName": "string",
    "lastName": "string",
    "callsign": "string",
    "roles": [{"name": "string", "displayName": "string"}],
    "expiryDate": "ISO8601 or null",
    "has_password": true
  }
}
```

**Kody błędów:** `400` brakuje pola, `401` złe dane

```bash
curl -X POST http://localhost:5000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "mypassword"}'
```

---

## 2. Odświeżenie tokenu

```
POST /api/v1/auth/refresh
Authorization: Bearer <refresh_token>
```

**Odpowiedź 200:**
```json
{"access_token": "string (nowy JWT)"}
```

---

## 3. Tworzenie pojedynczego użytkownika (admin)

```
POST /api/v1/users
Authorization: Bearer <access_token>
Content-Type: application/json
```

**Wymagana rola:** `administrator` lub `user_admin`

**Żądanie:**
```json
{
  "username": "string (required, 3-32 znaki)",
  "password": "string (required)",
  "email":    "string (required)",
  "firstName":"string (required)",
  "lastName": "string (required)",
  "callsign": "string (required)",
  "roleIds":  [1, 2],
  "expiryDate": "ISO8601 (optional)"
}
```

**Odpowiedź 201:**
```json
{
  "message": "User created successfully",
  "user": {"id": 1, "username": "string", "email": "string", "callsign": "string"}
}
```

**Kody błędów:** `400` nieprawidłowy format, `403` brak uprawnień, `409` username/email zajęty

```bash
curl -X POST http://localhost:5000/api/v1/users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "newuser",
    "password": "SecurePassword123!",
    "email": "newuser@example.com",
    "firstName": "John",
    "lastName": "Doe",
    "callsign": "JOHNDOE",
    "roleIds": [2]
  }'
```

---

## 4. Tworzenie wielu użytkowników (batch)

Brak dedykowanego endpoint batch — używaj pętli:

```bash
TOKEN="..."
BASE_URL="http://localhost:5000/api/v1"

# users.csv: username,password,email,firstName,lastName,callsign
while IFS=',' read -r username password email firstname lastname callsign; do
  curl -X POST "$BASE_URL/users" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"$username\",\"password\":\"$password\",\"email\":\"$email\",\"firstName\":\"$firstname\",\"lastName\":\"$lastname\",\"callsign\":\"$callsign\"}"
  echo "Created: $username"
done < users.csv
```

---

## 5. Generowanie paczki danych TAK (config ZIP)

```
GET /api/v1/tak-profiles/<profile_id>/download
Authorization: Bearer <access_token>
```

Alternatywnie token jako query param (przydatne dla bezpośrednich linków/przegladarki):
```
GET /api/v1/tak-profiles/<profile_id>/download?token=<access_token>
```

**Odpowiedź 200:**
```
Content-Type: application/zip
Content-Disposition: inline; filename=<profile_name>_<callsign>.zip
```

Paczka zawiera pliki konfiguracyjne ze wstawionym callsignem użytkownika (`${callsign}` w plikach preferencji TAK).

**Kody błędów:** `403` brak dostępu, `404` profil nie istnieje, `500` błąd generowania

```bash
# Wariant nagłówek:
curl -X GET http://localhost:5000/api/v1/tak-profiles/1/download \
  -H "Authorization: Bearer $TOKEN" \
  -o profile.zip

# Wariant query param (link do pobrania):
curl -X GET "http://localhost:5000/api/v1/tak-profiles/1/download?token=$TOKEN" \
  -o profile.zip
```

### Pobranie listy dostępnych profili

```bash
curl -X GET http://localhost:5000/api/v1/tak-profiles \
  -H "Authorization: Bearer $TOKEN"
```

---

## 6. QR code dla ATAK

### JSON z qr_string

```
GET /api/v1/qr/atak
Authorization: Bearer <access_token>
```

Query param: `?refresh=true` — wymusza regenerację tokenu

**Odpowiedź 200:**
```json
{
  "qr_string": "tak://com.atakmap.app/enroll?host=...&username=...&token=...",
  "username": "string",
  "expires_at": 1760000000,
  "max_uses": 1,
  "total_uses": 0
}
```

```bash
curl -X GET http://localhost:5000/api/v1/qr/atak \
  -H "Authorization: Bearer $TOKEN"
```

### Gotowy obraz QR (PNG)

```
GET /api/v1/Marti/api/tls/config/qr?clientUid=<username>
Authorization: Bearer <access_token>
```

**Odpowiedź 200:** `Content-Type: image/png` — binarny obraz PNG

```bash
curl -X GET "http://localhost:5000/api/v1/Marti/api/tls/config/qr?clientUid=myuser" \
  -H "Authorization: Bearer $TOKEN" \
  -o qr_atak.png
```

---

## 7. QR code dla iTAK

```
GET /api/v1/qr/itak
Authorization: Bearer <access_token>
```

**Odpowiedź 200:**
```json
{
  "qr_string": "OpenTAKServer_tak.example.com,tak.example.com,8089,SSL",
  "username": "string",
  "expires_at": null,
  "max_uses": null,
  "total_uses": null
}
```

```bash
curl -X GET http://localhost:5000/api/v1/qr/itak \
  -H "Authorization: Bearer $TOKEN"
```

### Różnice ATAK vs iTAK

| Cecha           | ATAK                        | iTAK                                      |
|-----------------|-----------------------------|-------------------------------------------|
| Format qr_string| `tak://...` (URL)           | `OpenTAKServer_HOST,HOST,PORT,SSL`        |
| Ważność tokenu  | 60 min (konfigurowalny)     | brak wygaśnięcia                         |
| Limit użyć      | 1 (konfigurowalny)          | brak limitu                               |
| Tracking        | tak (`total_uses`)          | nie                                       |
| Obraz PNG       | tak (`/Marti/...`)          | nie (string sam w sobie)                 |

---

## Przydatne dodatkowe endpointy

```bash
# Aktualny zalogowany użytkownik
GET /api/v1/auth/me

# Lista ról
GET /api/v1/roles

# Rejestracja publiczna (wymaga kodu onboardingu)
POST /api/v1/auth/register
{"username","password","email","firstName","lastName","callsign","onboardingCode"}
```

---

## Zmienne środowiskowe do testów

```bash
export TOKEN="<access_token_z_logowania>"
export BASE_URL="http://localhost:5000/api/v1"
export PROFILE_ID="1"
```

---

## Uwagi bezpieczeństwa

- Używaj HTTPS w produkcji
- Nie commituj tokenów do repozytorium
- Do automatyzacji twórz osobne konto techniczne z rolą `user_admin`
- `access_token` ważny 12h — odświeżaj przez `/auth/refresh` ze `refresh_token` (30d)
