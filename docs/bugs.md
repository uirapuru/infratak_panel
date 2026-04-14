# Znane błędy i problemy

Dokument prowadzony ręcznie. Każdy wpis zawiera: opis, dotkniętą warstwę, kroki do reprodukcji (jeśli znane) i status.

---

## BUG-001 — Stary CSRF token przy rotacji hasła OTS (krytyczny)

**Status:** Naprawiony (2026-04-13)  
**Priorytet:** Krytyczny  
**Warstwa:** `src/Service/Provisioning/OtsApiClient.php`

### Opis

`OtsApiClient::rotateAdminPassword` wykonuje trzy żądania HTTP:

1. `GET /api/login` → pobiera `csrf_token` z body + zapisuje cookies
2. `POST /api/login` → uwierzytelnia; zwraca nowe cookies (w tym potencjalnie nowy `csrftoken`)
3. `POST /api/password/change` → wysyła `X-CSRFToken: <token z kroku 1>`

Django po zalogowaniu może zaktualizować `csrftoken` w `Set-Cookie`. Kod łączy cookies z odpowiedzi na POST (`array_merge`), ale `$csrfToken` używany jako nagłówek `X-CSRFToken` pochodzi ze starego `GET /api/login` i **nigdy nie jest odświeżany**.

Efekt: `POST /api/password/change` dostaje `403 CSRF verification failed`.

### Jak szukać w logach

```
var/log/worker_provisioning.log
szukaj: POST /api/password/change failed: status=403
```

### Fragment kodu (linie 28–88)

```php
// linia 43 — token pochodzi z GET
$csrfToken = $loginJson['response']['csrf_token'] ?? null;

// linia 72 — cookies zaktualizowane z POST /api/login
$cookies = array_merge($cookies, $this->extractCookies($authResponse->getHeaders(false)['set-cookie'] ?? []));

// linia 79 — ale X-CSRFToken nadal używa starego $csrfToken
'X-CSRFToken' => $csrfToken,
```

### Proponowana naprawa

Po `POST /api/login` wyciągnąć nową wartość `csrftoken` z zaktualizowanych cookies i użyć jej jako `X-CSRFToken` w żądaniu zmiany hasła.

```php
// Po array_merge cookies:
$newCsrfToken = $cookies['csrftoken'] ?? $csrfToken;
// Użyć $newCsrfToken zamiast $csrfToken w X-CSRFToken dla password/change
```

---

## BUG-002 — Hasło administratora pokazane w flash PRZED ukończeniem rotacji (krytyczny UX)

**Status:** Naprawiony (2026-04-13)  
**Priorytet:** Wysoki  
**Warstwa:** `src/Controller/AdminServerCrudController.php:471–478`

### Opis

Przy ręcznym resecie hasła przez admina:

```php
// Hasło pokazane TUTAJ — zanim worker zadzwoni do OTS
$this->addPasswordFlash('success', 'Admin password reset queued. One-time password:', $newPassword);

// Dopiero potem wiadomość do kolejki
$this->messageBus->dispatch(new RotateAdminPasswordMessage(...));
```

Jeśli `RotateAdminPasswordHandler` się nie powiedzie (np. z powodu BUG-001), admin widzi hasło, które **nigdy nie zostało ustawione** na serwerze OTS. Spróbuje go użyć i nie zadziała, bez żadnej informacji o błędzie w UI.

Dla kontrastu: post-provisioning poprawnie ustawia `otsAdminPasswordPendingReveal` dopiero po sukcesie rotacji i pokazuje hasło przy pierwszym otwarciu detalu serwera.

### Proponowana naprawa

Nie pokazywać nowego hasła w flash przy zlecaniu. Zamiast tego:
- W `RotateAdminPasswordHandler` dla `origin = 'manual-reset'` ustawić `otsAdminPasswordPendingReveal = $message->newPassword` analogicznie jak dla post-provisioningu.
- Hasło będzie widoczne w detalu serwera dopiero po potwierdzeniu sukcesu przez workera.

---

## BUG-003 — `sleepAt` nigdy nie jest czyszczony po stop/start (poważny)

**Status:** Naprawiony (2026-04-13)  
**Priorytet:** Średni  
**Warstwa:** `src/MessageHandler/StopServerHandler.php`, `StartServerHandler.php`, `src/Message/ServerProjectionMessage.php`

### Opis

`ServerProjectionMessage` nie ma pola `clearSleepAt`. Po zatrzymaniu lub ręcznym uruchomieniu serwera pole `sleepAt` pozostaje ustawione w bazie na starą datę z przeszłości przez cały dalszy cykl życia serwera.

Konsekwencje:
1. Panel admina pokazuje nieaktualną datę `sleepAt` — nie można jej wyczyścić z UI.
2. Przy zduplikowanym `StopServerMessage` (np. z kolejnych retry prowisioningu przez API) stary timestamp nadal pasuje do warunku w `StopServerHandler`:
   ```php
   if ($sleepAt === null || $sleepAt->getTimestamp() !== $message->targetSleepAt->getTimestamp()) {
       return; // pominięcie — NIE działa jeśli timestamps są zgodne
   }
   ```
   Duplikat może wykonać zatrzymanie instancji w nieoczekiwanym momencie.

### Proponowana naprawa

Dodać pole `clearSleepAt: bool = false` do `ServerProjectionMessage` i `ServerProjectionHandler`. Ustawić `clearSleepAt: true` w projekcji sukcesu `StopServerHandler` i `StartServerHandler`.

---

## BUG-004 — Re-provisioning resetuje hasło OTS do `'password'` (poważny)

**Status:** Naprawiony (2026-04-13)  
**Priorytet:** Średni  
**Warstwa:** `src/Service/Server/ServerCreationService.php:72–73`

### Opis

`initializeForProvisioning` zawiera:

```php
->setOtsAdminPasswordPrevious($server->getOtsAdminPasswordCurrent())
->setOtsAdminPasswordCurrent('password')  // hardkodowane
->setOtsAdminPasswordRotatedAt(null)
```

Jeśli serwer był już sprowisionowany i ma realne hasło (po udanej rotacji), a ktoś uruchamia re-provisioning przez API, stare hasło jest zapisywane do `Previous` ale `Current` jest resetowane do `'password'`. Po zakończeniu nowego provisioningu `CreateServerHandler` wyśle `RotateAdminPasswordMessage(oldPassword='password')`, podczas gdy faktyczne hasło na OTS to poprzednio ustawione. Rotacja się nie powiedzie.

Dotyczy wyłącznie re-provisioningu przez `POST /servers` z API. Przycisk "Retry provisioning" w adminie (`AdminServerCrudController::retryProvisioning`) pomija `ServerCreationService` i nie ma tego problemu.

### Proponowana naprawa

Przy re-provisioningu nie nadpisywać `otsAdminPasswordCurrent` jeśli `otsAdminPasswordRotatedAt !== null` — oznacza to, że hasło zostało faktycznie zmienione i trzeba je zachować dla przyszłej rotacji.

---

## BUG-005 — Wielokrotne logi FAILED przy retry cleanup serwera (mniejszy)

**Status:** Naprawiony (2026-04-13)  
**Priorytet:** Niski  
**Warstwa:** `src/MessageHandler/DeleteServerHandler.php:72–98`

### Opis

Po nieudanym cleanup handler: (1) dispatchuje projekcję `FAILED` z wpisem do `ServerOperationLog`, (2) re-rzuca wyjątek. RabbitMQ ponawia wiadomość (5 prób zgodnie z konfiguracją). Każda próba tworzy nowy wpis `FAILED` w logach operacyjnych. W razie rozległych problemów z AWS (np. niedostępność API przez dłuższy czas) generuje to znaczny szum w `ServerOperationLog` i panelu admina.

### Proponowana naprawa

Logować projekcję FAILED tylko przy ostatniej próbie (gdy `attempt >= MAX_RETRIES`) lub po pierwsze skorzystać z Messenger failure transport (TODO nr 15 w `docs/todo.md`).

---

## BUG-006 — Niespójność w `hasActiveServerWithName`: enum zamiast `->value` (mniejszy)

**Status:** Naprawiony (2026-04-13)  
**Priorytet:** Niski  
**Warstwa:** `src/Repository/ServerRepository.php:29`

### Opis

```php
// hasActiveServerWithName (linia 29)
->setParameter('deletedStatus', ServerStatus::DELETED)  // enum bez ->value

// countInProvisioning (linie 45–51)
->setParameter('deletedStatus', ServerStatus::DELETED->value)  // poprawnie
->setParameter('failedStatus',  ServerStatus::FAILED->value)
```

Z Doctrine ORM 3.x oba warianty działają poprawnie dla kolumn z `enumType`, ale niespójność może wprowadzać zamieszanie i jest potencjalnym problemem przy refaktoringu.

---

## BUG-007 — `RotateAdminPasswordHandler` nie tworzy wpisu w `ServerOperationLog` (poważny)

**Status:** Naprawiony (2026-04-14)
**Priorytet:** Średni
**Warstwa:** `src/MessageHandler/RotateAdminPasswordHandler.php`

### Opis

Wszystkie pozostałe handlery (provisioning, stop, start, diagnose) tworzą wpisy `ServerOperationLog` widoczne w panelu admina. `RotateAdminPasswordHandler` zapisywał wynik rotacji wyłącznie do loggera (`worker_provisioning.log`). Administrator nie miał możliwości sprawdzenia w UI kiedy rotacja się odbyła, kto ją zlecił ani dlaczego się nie powiodła.

### Proponowana naprawa

Dodać `$this->entityManager->persist(new ServerOperationLog(...))` zarówno przy sukcesie jak i przy błędzie rotacji, przed `flush()`.

---

## BUG-008 — `SyncServerStatusCommand` nie czyści `endedAt` przy korekcie STOPPED→READY (mniejszy)

**Status:** Naprawiony (2026-04-14)
**Priorytet:** Niski
**Warstwa:** `src/Command/SyncServerStatusCommand.php:dispatchStatusCorrection`

### Opis

Gdy AWS sync wykrywa, że serwer w DB ma status STOPPED, ale na EC2 jest `running`, koryguje status na READY. W `ServerProjectionMessage` przekazywane było `endedAt: null` (co w handlerze oznacza "nie zmieniaj"), więc stara wartość `endedAt` z poprzedniego stopu pozostawała w bazie. Serwer w panelu wyglądał na aktywny (`READY`) ale miał ustawiony czas zakończenia z przeszłości.

### Proponowana naprawa

Przy korekcie na READY: ustawić `startedAt = now`, `clearEndedAt = true`. Przy korekcie na STOPPED/DELETED: ustawić `endedAt = now` (już było). Dodane w `dispatchStatusCorrection`.

---

## BUG-009 — Rotacja hasła portalu powoduje nienaprawialny stan (krytyczny)

**Status:** Naprawiony (2026-04-14)
**Priorytet:** Krytyczny
**Warstwa:** `src/Service/Provisioning/AwsProvisioningClient.php`

### Opis

Próba rotacji hasła na domenie portalu (`portal.{subdomain}.infratak.com`) przez `OtsApiClient::rotateAdminPassword` kończyła się wyjątkiem (portal to osobna aplikacja Flask bez endpointu `/api/login`). Wyjątek był re-throw'owany, co uniemożliwiało zapis `otsAdminPasswordCurrent` w DB. Przy kolejnych próbach `oldPassword` w wiadomości był nieaktualny — rotacja głównego OTS również zaczynała failować. Efekt: oba serwisy miały nieprawidłowe hasło i nie dało się wrócić do prawidłowego stanu przez panel.

### Naprawa

Usunięto rotację portalu z `AwsProvisioningClient::rotateOtsAdminPassword`. Portal (`/opt/opentak-onboarding-portal/`, Flask + gunicorn, port 5000) nie posiada własnej tabeli użytkowników z hasłem — używa OIDC lub innej federacji. Jedynym serwisem wymagającym rotacji jest główna instancja OTS (port 8081, PostgreSQL, Flask-Security-Too).

### Recovery (wykonany ręcznie przez SSM)

```bash
# Reset hasła administratora OTS przez Flask app context
sudo -u ubuntu bash -c "cd /home/ubuntu/ots && \
  /home/ubuntu/.opentakserver_venv/bin/python3 -c \"
import sys
sys.path.insert(0, '/home/ubuntu/.opentakserver_venv/lib/python3.12/site-packages')
from opentakserver.app import create_app
app = create_app()
with app.app_context():
    from flask_security.utils import hash_password
    from opentakserver.extensions import db
    from opentakserver.models.user import User
    u = User.query.filter_by(username='administrator').first()
    u.password = hash_password('password')
    db.session.commit()
\""
# Następnie zaktualizuj DB panelu:
# UPDATE server SET ots_admin_password_current='password', last_error=NULL WHERE id='<id>';
```

---

## Powiązane TODO

- `docs/todo.md` poz. 15: Dodać Messenger failure transport — bez niego wiadomości po wyczerpaniu retry znikają bez śladu (dotyczy BUG-001, BUG-005)
- `docs/todo.md` poz. 17: Auto-refresh detalu serwera — po naprawie BUG-002 ułatwi obserwację wyniku rotacji hasła
