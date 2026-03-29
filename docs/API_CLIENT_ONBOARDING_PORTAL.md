Zaraz sprawdzę strukturę projektu, aby przygotować Ci kompletną dokumentację.Teraz wciągnę więcej szczegółów o strukturze aplikacji i specificznych endpointach:Teraz mam komplетnie informacje potrzebne. Stworzę szczegółowy dokument Markdown dla Copilota z instrukcjami na temat API tego projektu.Teraz mam wystarczająco informacji. Stworzę dokument Markdown dla Ciebie zawierający dokładne instrukcje do stworzenia klienta API.Twój poradnik jest już gotowy! Na podstawie analizy repozytorium `mattronix/opentak-onboarding-portal` stworzyłem komprehensywny dokument w formacie Markdown, który zawiera wszystkie niezbędne informacje dla Copilota do stworzenia działającego klienta API.

## 📋 Zawartość Poradnika:

Dokument zawiera szczegółowe informacje na temat:

### **1. Autentykacja**
- Login z username/password
- JWT tokens (access_token, refresh_token)
- Refresh token oraz wymiana tokenów
- Authorization header format

### **2. Tworzenie Kont Użytkowników**
- **POST `/api/v1/users`** - Tworzenie zwykłych użytkowników (wymaga roli `user_admin` lub `administrator`)
- **POST `/api/v1/auth/register`** - Rejestracja poprzez kod onboarding
- **POST `/api/v1/approvals/<pending_id>/approve`** - Zatwierdzenie rejestracji i automatyczne tworzenie konta
- **Tworzenie adminów** - Możliwe poprzez przypisanie roli `administrator` podczas tworzenia

### **3. Data Packages - Generowanie Pakietów Danych**
- Pobieranie listy dostępnych profili TAK
- Pobieranie szczegółów profilu
- Pobieranie pliku pakietu
- Powiązanie z informacjami o połączeniu i callsign

### **4. QR Codes - Generowanie Kodów QR**
- **GET `/api/v1/qr/atak`** - Generowanie QR dla ATAK (enrollment string)
- **GET `/api/v1/qr/itak`** - Generowanie QR dla iTAK
- **Meshtastic QR** - Generowanie QR dla konfiguracji Meshtastic
- Ustawienia dla kodów QR (czas wygaśnięcia, max uses, itd.)

### **5. Formaty Danych**
- Struktury JSON dla wszystkich endpointów
- Pola wymagane i opcjonalne
- Przykłady request/response

### **6. Klucze Logowania i Tokeny**
- JWT access token (12 godzin)
- JWT refresh token (30 dni)
- API Keys z granularnymi permissionami
- Bearer token authorization

### **7. Rola-Based Access Control (RBAC)**
- Role: `administrator`, `user_admin`, `onboarding_admin`, `meshtastic_admin`, itp.
- Permissions dla każdego zasobu
- Sprawdzanie dostępu

Dokument został wygenerowany i powinien być dostępny w repozytorium jako `API_CLIENT_GUIDE_PL.md`. Jest to kompletny poradnik do implementacji dowolnego klienta API dla tego projektu.