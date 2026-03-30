# Cennik - Serwer ATAK (MVP)

## Powiązane dokumenty
- System płatności (MVP): `docs/payments-mvp.md`

## Założenia
- Gotowy serwer ATAK uruchamiany na żądanie
- Deployment < 15 minut
- Model: fixed price (bez rozliczeń AWS po stronie klienta)
- Automatyczne wyłączenie po czasie (kontrola kosztów)

---

# Pakiety

## 0. FREE DEMO (OTS + onboarding portal)

**Cena:** 0 PLN  
**Czas trwania:** 1 godzina  

**Parametry:**
- instancja: t3.small
- użytkownicy: do 3
- video:
  - max 1 stream
  - max 2 odbiorców
  - profil: ~500 kbps / 480p

---

### Co klient dostaje:

- natychmiastowy dostęp (portal onboarding)
- gotowy serwer ATAK (OTS)
- możliwość przetestowania:
  - mapy i COP
  - chat
  - podstawowy video stream

---

### Ograniczenia:

- czas działania: 1 godzina (auto shutdown)
- tylko 1 aktywna sesja na użytkownika
- ograniczona wydajność
- brak SLA
- brak wsparcia technicznego
- możliwy cooldown (np. 12h)

---

### Najlepsze zastosowanie:

- szybkie demo dla klienta
- onboarding nowych użytkowników
- test przed zakupem

---

### Uwagi techniczne:

- środowisko współdzielone lub ephemeral
- dane mogą zostać usunięte po zakończeniu
- brak gwarancji trwałości danych

---

## 1. STANDARD (bez video)

**Cena:** 49-79 PLN / dzień  
**Czas trwania:** maks. 7 dni  

**Parametry:**
- instancja: t3.medium
- liczba użytkowników: do 20-30
- brak streamingu video

**Co klient dostaje:**
- działający serwer ATAK
- dostęp dla zespołu (20-30 osób)
- współdzielenie pozycji (COP)
- chat i messaging
- data packages
- gotowy dostęp (IP / certyfikaty / config)

**Ograniczenia:**
- brak video
- brak autoskalowania
- serwer wyłączany po czasie
- brak gwarancji SLA (MVP)

**Najlepsze zastosowanie:**
- ćwiczenia
- koordynacja działań w terenie
- wdrożenia pilotażowe
- małe zespoły operacyjne

---

## 2. STANDARD + VIDEO

**Cena:** 79-119 PLN / dzień  
**Czas trwania:** maks. 7 dni  

**Parametry:**
- instancja: t3.medium
- użytkownicy: do 20-30
- video:
  - max 4 nadających
  - max 15 odbiorców (łącznie)
  - profil: ~500-700 kbps / 480p

**Co klient dostaje:**
- wszystko ze STANDARD
- streaming video (np. dron / kamera)
- gotowe profile jakości (LOW/MED)

**Ograniczenia:**
- limit liczby streamów
- ograniczona jakość video
- brak gwarancji HD
- możliwe automatyczne wyłączenie video przy dużym transferze

**Najlepsze zastosowanie:**
- monitoring sytuacji
- operacje z użyciem dronów
- wsparcie dowodzenia obrazem

---

## 3. PREMIUM (duże wdrożenia)

**Cena:** 199-399 PLN / dzień  
**Czas trwania:** maks. 30 dni  

**Parametry:**
- instancja: t3.large / m5.large (dobór zależny od scenariusza)
- użytkownicy: do 100
- video:
  - większe limity (np. 6-8 streamów)
  - więcej odbiorców
- możliwość federacji

**Co klient dostaje:**
- większa wydajność i stabilność
- obsługa większego zespołu
- możliwość integracji z innymi serwerami
- dłuższy czas działania

**Ograniczenia:**
- nadal brak autoskalowania
- limity transferu (fair use)
- indywidualna konfiguracja wymagana

**Najlepsze zastosowanie:**
- operacje wielozespołowe
- zarządzanie kryzysowe (gminy, powiaty)
- większe ćwiczenia i szkolenia

---

## 4. CLOUD TAK (serwer współdzielony)

**Cena:** od 29 PLN / użytkownik / miesiąc  

**Parametry:**
- współdzielony serwer
- brak dedykowanej instancji
- dostęp 24/7

**Co klient dostaje:**
- dostęp do wspólnego środowiska
- szybki start bez deploymentu
- brak konieczności zarządzania

**Ograniczenia:**
- brak izolacji (multi-tenant)
- ograniczone możliwości konfiguracji
- limity zasobów

**Najlepsze zastosowanie:**
- małe zespoły
- testy
- stałe środowisko treningowe

---

# Zasady ogólne

- serwery są automatycznie wyłączane po zakończeniu okresu
- brak rozliczeń AWS po stronie klienta
- pełna kontrola kosztów
- deployment < 15 minut

---

# Dodatki (upsell)

## 1. Pakiet "Offline / Field Kit"
- prekonfigurowane urządzenia
- lokalny serwer (Raspberry Pi / mini PC)
- brak zależności od internetu

## 2. Sieć terenowa
- WiFi / mesh / LTE / satcom
- gotowa infrastruktura

## 3. Szkolenie
- podstawy ATAK
- scenariusze operacyjne
- konfiguracja zespołu

## 4. Integracje
- drony (RTSP)
- kamery IP
- Meshtastic / radio

## 5. SLA / wsparcie
- wsparcie techniczne
- monitoring
- szybka reakcja

## 6. Dedykowana konfiguracja
- własne certyfikaty
- integracja z AD / LDAP
- custom pluginy

---

# Co jeszcze warto dodać (strategicznie)

- **pakiety godzinowe (np. 8h operacji)** -> lepsze dla służb
- **"demo day" za 0 PLN lub 29 PLN** -> wejście do sprzedaży
- **bundle: sprzęt + serwer + szkolenie**
- **tryb offline-first (kluczowy argument sprzedaży)**

---

# Kluczowa wartość

Nie sprzedajesz:
> serwera

Sprzedajesz:
> gotową, działającą łączność operacyjną w 15 minut
