# TODO

## Najwyzszy priorytet

0. Uruchomic Playwright E2E testy w CI/CD.
- [DONE] Setup lokalny Playwright + testy smoke dla home i register.
- TODO: Integracja z CI pipeline (GitHub Actions, Gitlab CI, etc.).
- TODO: Dodać testy dla scenariuszy logowania, formularzy API.

1. Uruchomic produkcyjnie certyfikat Let's Encrypt i domknac automatyczne odnowienia.
- Potwierdzic, ze `make tls-status` pokazuje issuer Let's Encrypt (nie self-signed).
- Ustawic i zweryfikowac cykliczne odnowienie (np. cron/systemd timer wywolujacy `make tls-renew`).
- Dodac test po odnowieniu: `curl -I https://infratak.com` + walidacja dat certyfikatu.

2. Hardening rejestracji użytkownika (error handling, mailer).
- [DONE] Obsługa błędu SMTP w `/register` — rollback user/token, friendly error message zamiast 500.
- [DONE] Dokumentacja produkcyjnego MAILER_DSN w docs/mailer-production.md.
- [DONE] Test Playwright weryfikujący scenariusz braku mailcatchera w produkcji.
- TODO: Skonfigurować rzeczywisty provider SMTP/API i poprawny MAILER_DSN w `.env.deploy`.
- TODO: Dodać i zweryfikować DNS: SPF, DKIM, DMARC dla domeny nadawcy.
- TODO: Checklist operacyjny mailera: retry, logi, monitoring błędów wysyłki.

## Zrobione (usuniete z aktywnego backlogu)

- Logowanie i ochrona panelu admina (Symfony security + nginx rate-limit/basic auth).
- Stabilizacja deployu: sync kodu na istniejaca instancje, brak `down` przed `up`, zachowanie danych bazy.
- Podzial komend: `make setup` (jednorazowo) i `make deploy-prod` (codziennie).
- TLS helpery operacyjne: `make tls-status`, `make tls-renew`.
- AWS SDK credentials z profilami na panelu (mount `var/share/.aws` do kontenerow + fallback z lokalnego `~/.aws`, zweryfikowane STS `GetCallerIdentity`).

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

5. Okres demo — przez pierwszy miesiąc działania aplikacji dostęp jest darmowy, ale ograniczony:
- każde konto może mieć maksymalnie 3 instancje,
- każda instancja musi się automatycznie wyłączać po 1 godzinie od uruchomienia.

6. Dodać abonamenty na określony czas:
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

8. [DONE] Kody promocyjne (MVP):
- [DONE] Encja `PromoCode` (code, durationDays, maxUses, usedCount, expiresAt, isActive)
- [DONE] Rejestracja przez `/zamow/rejestracja` wymaga ważnego kodu
- [DONE] Kod określa czas działania serwera (`durationDays`) — subskrypcja tworzona automatycznie
- [DONE] Admin CRUD do zarządzania kodami
- TODO: rozszerzyć o rabat procentowy/kwotowy przy podłączeniu płatności
- TODO: powiązanie kodu z zamówieniem/subskrypcją w historii

9. Dodać rozliczanie płatnościami internetowymi:
- integracja z operatorem płatności,
- obsługa webhooków i potwierdzeń płatności,
- statusy płatności oraz retry/obsługa błędów,
- powiązanie płatności z abonamentem i lifecycle serwera.

10. Dodać pobieranie rzeczywistego kosztu instancji od uruchomienia do zakończenia.
- Integracja z danymi kosztowymi AWS (billing/cost explorer) per instancja.
- Prezentacja kosztu końcowego po zatrzymaniu/terminacji instancji.
- Możliwość wglądu w koszt per serwer i sumarycznie per konto.

11. Dodać dokładne zliczanie czasu pracy instancji na podstawie zdarzeń start/stop.
- Zapisywać dokładny timestamp każdego startu i stopu.
- Sumować wszystkie okresy aktywności instancji (wiele cykli start/stop).
- Udostępnić łączny czas pracy do rozliczeń i raportowania.

12. Dodać monitoring bieżącego zużycia CPU i dysku.
- Zbierać metryki okresowo (np. CloudWatch) dla każdej instancji.
- Pokazywać aktualne użycie CPU i przestrzeni dyskowej w panelu.
- Dodać progi alertów i historię trendów zużycia.

## Powiązanie CloudTak z istniejącym serwerem

13. Przy uruchamianiu nowej instancji CloudTak dodać dodatkowy krok wyboru serwera docelowego do podpięcia.
- Do wyboru mają być wyłącznie instancje OpenTak i GovTak posiadane przez aktualnego użytkownika.
- Brak dostępnych instancji OpenTak/GovTak powinien blokować utworzenie CloudTak z czytelnym komunikatem.
- Wybrane powiązanie CloudTak -> serwer docelowy powinno zostać zapisane w bazie danych.

14. Dodać wariant uruchomienia CloudTak bez posiadania własnego serwera (tryb serwera zewnętrznego).
- W formularzu użytkownik podaje dane zewnętrznego serwera docelowego.
- Dodać walidację poprawności danych połączeniowych przed zapisaniem konfiguracji.
- Przeprowadzić analizę ryzyk (security + operacyjne), w szczególności:
	- przechowywanie i ochrona danych dostępowych,
	- walidacja certyfikatów/hostów,
	- timeouty i retry dla połączeń zewnętrznych,
	- odpowiedzialność za awarie i brak dostępności serwera zewnętrznego.

## Hardening operacyjny i obserwowalność

15. Dodać Messenger failure transport dla wiadomości, które wyczerpały retry.
- Wiadomości po 5 próbach nie powinny znikać bez śladu.
- Administrator powinien móc zobaczyć, co dokładnie utknęło i dlaczego.

16. Dodać komendę operacyjną Symfony do ręcznej diagnozy/problem resolution, np. `app:ops:diagnose-server <id>`.
- Ma wykonywać ten sam flow co przycisk Diagnose z admina.
- Ma być bezpieczna do użycia lokalnie i na środowisku serwisowym.

17. Dodać auto-refresh dla dashboardu workerów i detailu serwera.
- Status workerów i status/step serwera powinny odświeżać się bez ręcznego reloadu strony.

18. Dodać prosty healthcheck aplikacyjny workerów, nie tylko odczyt consumer count z RabbitMQ.
- Sam consumer count nie odróżnia workera zdrowego od workera, który wisi logicznie.

## MediaMTX po provisioningu

19. Dodać ograniczenie liczby nadawanych streamów i oglądających (viewerów) w MediaMTX po provisioningu.
- Limity mają być konfigurowalne per serwer/plan.
- Provisioning powinien zapisywać konfigurację limitów w plikach MediaMTX.
- Przekroczenie limitu powinno być widoczne w logach operacyjnych i łatwe do diagnozy z panelu.
