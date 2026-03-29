# TODO

## OpenTAK integration po provisioningu

1. Po zakończeniu provisioningu przez API OpenTAKServer ma się utworzyć konto z hasłem podanym podczas tworzenia serwera.
- Maksymalnie 10 kont może być utworzonych dla jednego serwera podczas procesu tworzenia.

2. Dane kont mają być zapisane w panelu w bazie danych.
- Hasła muszą być bezpiecznie zaszyfrowane.
- Musi istnieć kontrolowana możliwość odszyfrowania haseł.

3. Po utworzeniu kont system ma pobrać z OpenTAKServer dla każdego konta:
- link do connection data package (ZIP),
- QR code do connection data package,
- informację, do którego konta należą dane.
- QR code i ZIP mają być zapisane w tabeli kont wraz z powiązaniem do konta.

## Architektura typów serwera

4. Zmienić encję bazową Server na abstrakcyjną i dodać dziedziczenie po jednej tabeli (single table inheritance) dla typów:
- OpenTak,
- GovTak,
- CloudTak.

Cel:
- umożliwić różne strategie provisioningu per typ,
- zachować wspólne pola w klasie bazowej,
- przygotować projekt na dodawanie kolejnych typów serwerów w przyszłości.

## Billing i monetyzacja

5. Dodać abonamenty na określony czas:
- warianty okresu: dni, miesiące, rok,
- mapowanie planu na odpowiedni typ/rozmiar instancji,
- walidacja terminu ważności i automatyczne wygaszanie po upływie czasu.

6. Dodać różne typy instancji jako element planu:
- cennik zależny od typu instancji,
- możliwość zmiany planu/typu (upgrade/downgrade),
- historia zmian planu per serwer.

7. Dodać rejestrację konta do rozliczeń:
- profil billingowy użytkownika/organizacji,
- dane rozliczeniowe i kontaktowe,
- powiązanie serwerów z kontem billingowym.

8. Dodać kody promocyjne:
- rabat procentowy lub kwotowy,
- ograniczenia ważności, liczby użyć i zakresu planów,
- audyt użycia kodu i powiązanie z zamówieniem/subskrypcją.

9. Dodać rozliczanie płatnościami internetowymi:
- integracja z operatorem płatności,
- obsługa webhooków i potwierdzeń płatności,
- statusy płatności oraz retry/obsługa błędów,
- powiązanie płatności z abonamentem i lifecycle serwera.

## Powiązanie CloudTak z istniejącym serwerem

10. Przy uruchamianiu nowej instancji CloudTak dodać dodatkowy krok wyboru serwera docelowego do podpięcia.
- Do wyboru mają być wyłącznie instancje OpenTak i GovTak posiadane przez aktualnego użytkownika.
- Brak dostępnych instancji OpenTak/GovTak powinien blokować utworzenie CloudTak z czytelnym komunikatem.
- Wybrane powiązanie CloudTak -> serwer docelowy powinno zostać zapisane w bazie danych.

11. Dodać wariant uruchomienia CloudTak bez posiadania własnego serwera (tryb serwera zewnętrznego).
- W formularzu użytkownik podaje dane zewnętrznego serwera docelowego.
- Dodać walidację poprawności danych połączeniowych przed zapisaniem konfiguracji.
- Przeprowadzić analizę ryzyk (security + operacyjne), w szczególności:
	- przechowywanie i ochrona danych dostępowych,
	- walidacja certyfikatów/hostów,
	- timeouty i retry dla połączeń zewnętrznych,
	- odpowiedzialność za awarie i brak dostępności serwera zewnętrznego.

## Hardening operacyjny i obserwowalność

12. Dodać Messenger failure transport dla wiadomości, które wyczerpały retry.
- Wiadomości po 5 próbach nie powinny znikać bez śladu.
- Administrator powinien móc zobaczyć, co dokładnie utknęło i dlaczego.

13. Dodać komendę operacyjną Symfony do ręcznej diagnozy/problem resolution, np. `app:ops:diagnose-server <id>`.
- Ma wykonywać ten sam flow co przycisk Diagnose z admina.
- Ma być bezpieczna do użycia lokalnie i na środowisku serwisowym.

14. Dodać auto-refresh dla dashboardu workerów i detailu serwera.
- Status workerów i status/step serwera powinny odświeżać się bez ręcznego reloadu strony.

15. Dodać prosty healthcheck aplikacyjny workerów, nie tylko odczyt consumer count z RabbitMQ.
- Sam consumer count nie odróżnia workera zdrowego od workera, który wisi logicznie.

## MediaMTX po provisioningu

16. Dodać ograniczenie liczby nadawanych streamów i oglądających (viewerów) w MediaMTX po provisioningu.
- Limity mają być konfigurowalne per serwer/plan.
- Provisioning powinien zapisywać konfigurację limitów w plikach MediaMTX.
- Przekroczenie limitu powinno być widoczne w logach operacyjnych i łatwe do diagnozy z panelu.
