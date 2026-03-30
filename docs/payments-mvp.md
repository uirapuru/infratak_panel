# System płatności - założenia i implementacja (MVP)

## Cel

Umożliwić szybkie przyjmowanie płatności za:
- serwery ATAK (pakiety dzienne)
- dodatki (video, szkolenia, sprzęt)

Bez budowania własnego systemu billingowego.

---

## Założenia

- brak rozliczeń AWS po stronie klienta
- stała cena (per dzień / pakiet)
- pełna kontrola kosztów po naszej stronie
- płatność z góry (prepaid)
- zero integracji na start (link do płatności)

---

## Wybrana metoda

👉 Operator: **Tpay**

Obsługiwane metody:
- BLIK
- Google Pay
- szybkie przelewy
- karty

---

## Model płatności

### 1. Płatność przez link

Flow:
1. Tworzymy ofertę (np. 1 dzień serwera)
2. Generujemy link do płatności (Tpay)
3. Wysyłamy klientowi:
   - mail / WhatsApp / PDF
4. Klient płaci
5. My uruchamiamy serwer

---

## Typy produktów

### Produkt: Serwer ATAK (1 dzień)

- cena: zgodnie z cennikiem
- jednostka: 1 dzień
- brak automatycznego odnawiania

---

### Produkt: Video addon

- dodatkowa opłata do serwera
- aktywowany tylko jeśli opłacony

---

### Produkt: Premium (30 dni)

- wyższa cena
- indywidualna konfiguracja

---

## Logika biznesowa

### Uruchomienie serwera

Serwer uruchamiany tylko jeśli:
- płatność = zaksięgowana (status SUCCESS)

---

### Czas działania

- start: moment uruchomienia
- stop: automatycznie po czasie (np. 24h / 7 dni / 30 dni)

---

### Brak płatności

- brak dostępu
- brak uruchomienia serwera

---

## Kontrola kosztów

- każdy serwer ma:
  - `expires_at`
- automatyczne wyłączenie:
  - cron / lambda
- brak możliwości przekroczenia czasu

---

## MVP - implementacja (prosta)

### Krok 1
- konto Tpay (sandbox + produkcja)

### Krok 2
- ręczne generowanie linków płatności:
  - 49 PLN
  - 99 PLN
  - 199 PLN

### Krok 3
- ręczny provisioning:
  - po potwierdzeniu płatności

---

## Etap 2 (automatyzacja)

Po walidacji:

- webhook Tpay -> backend
- automatyczne:
  - tworzenie serwera
  - generowanie danych dostępowych
  - wysyłka do klienta

---

## Etap 3 (skalowanie)

- panel klienta
- wybór pakietu
- automatyczne płatności
- historia zamówień

---

## Ryzyka

- klient kupi i nie poda danych -> trzeba mieć proces (formularz)
- błędna konfiguracja video -> koszt AWS
- brak automatycznego kill -> największe ryzyko finansowe

---

## Decyzje (ważne)

- brak pay-as-you-go
- brak fakturowania po czasie
- tylko prepaid
- tylko stałe pakiety

---

## Dlaczego tak

- najszybsze wdrożenie (1-2 dni)
- minimalne ryzyko finansowe
- brak developmentu billingowego
- łatwe do sprzedaży

---

## Następne kroki

1. Założyć konto Tpay
2. Wygenerować 3 linki:
   - STANDARD
   - STANDARD + VIDEO
   - PREMIUM
3. Przetestować płatność (BLIK)
4. Sprzedać pierwszemu klientowi
