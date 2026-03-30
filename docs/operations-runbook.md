# Infratak Operations Runbook

## Cel

Krótka instrukcja operacyjna do diagnozowania problemów z workerami, kolejkami i flow provisioning/diagnose.

---

## 0. Którego Makefile używać

W repo są dwa osobne pliki i mają różny cel:

1. `Makefile` (root) — deployment aplikacji (landing + admin) na istniejącej infrastrukturze
   * używa `compose.prod.yml` i `.env.deploy`
   * przykładowe komendy:

```bash
make deploy
make deploy-logs
```

2. `Makefile.infra` (root) — provisioning infrastruktury AWS (EC2)
   * używa AWS CLI, `user-data.sh` i `.env.infra`
   * przykładowe komendy:

```bash
make -f Makefile.infra create-instance
make -f Makefile.infra get-ip
make -f Makefile.infra status
```

Zasada:

* najpierw `Makefile.infra` (gdy nie ma serwera)
* potem `Makefile` (wdrożenie aplikacji na utworzony serwer)
* nie mieszać tego z `infra/provisioning` (to inny obszar odpowiedzialności)

---

## 1. Aktualna topologia kolejek

Biznesowe kolejki AMQP są dwie:

1. `infratak.provisioning`
2. `infratak.projection`

Uwagi:

* `CreateServerMessage`, `DeleteServerMessage`, `DiagnoseServerMessage` i `StopServerMessage` używają transportu `provisioning`
* `ManualStopServerMessage`, `StartServerMessage` i `RotateAdminPasswordMessage` również używają transportu `provisioning`
* `ServerProjectionMessage` używa transportu `projection`
* dodatkowe kolejki typu `delay_*` mogą pojawiać się czasowo przez retry Symfony Messenger i nie są osobnymi kolejkami domenowymi

---

## 2. Co pokazuje dashboard admina

Dashboard pokazuje:

* liczbę serwerów `ready`
* liczbę serwerów `failed`
* liczbę serwerów „in progress”
* status workerów `provisioning` i `projection`

Kafelki workerów bazują na liczbie `consumers` odczytanej z RabbitMQ Management API dla kolejek:

* `infratak.provisioning`
* `infratak.projection`

Interpretacja:

* `Running` = consumers > 0
* `Stopped` = consumers = 0
* `Unknown` = brak odpowiedzi z RabbitMQ Management API lub problem z DSN

To pokazuje, czy worker realnie nasłuchuje kolejki, a nie tylko czy kontener istnieje.

---

## 3. Szybka checklista gdy „nic się nie dzieje”

Wykonaj w tej kolejności:

1. sprawdź kontenery:

```bash
docker compose ps
```

2. sprawdź kolejki, liczbę consumers i wiadomości:

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name consumers messages
```

3. sprawdź logi worker provisioning:

```bash
docker compose logs --tail=120 worker_provisioning
```

4. sprawdź logi worker projection:

```bash
docker compose logs --tail=120 worker_projection
```

---

## 4. Typowe scenariusze awarii

### A. Kontener workera działa, ale consumers = 0

Najczęściej oznacza to, że worker właśnie się restartuje albo proces consume nie nasłuchuje poprawnie.

Akcja:

```bash
docker compose restart worker_provisioning worker_projection
```

### B. Diagnose utknął w `DIAGNOSING`

Najczęstsze przyczyny:

* worker provisioning nie działa
* worker działa na starym kodzie i nie zna nowych enumów/handlerów
* wiadomość wyczerpała retry i została usunięta z transportu

Szczególnie ważne po zmianach w:

* `ServerStatus`
* `ServerStep`
* handlerach Messengera
* routingu wiadomości

Akcja:

```bash
docker compose restart worker_provisioning worker_projection
```

Potem ponów akcję Diagnose.

### C. Kolejka jest pusta, a rekord nadal wygląda na zawieszony

To zwykle znaczy, że wiadomość została już odebrana i przetworzona albo odrzucona po retry.

Wtedy prawda jest w logach workera, nie w samej kolejce.

---

## 5. Aktualny flow Diagnose

1. użytkownik klika `Diagnose`
2. server dostaje:
   * `status = diagnosing`
   * `step = wait_ssm`
   * `lastDiagnoseStatus = running`
3. `DiagnoseServerMessage` trafia na transport `provisioning`
4. worker provisioning uruchamia komendy diagnostyczne przez SSM
5. w trakcie step może przejść na `provision`
6. wynik końcowy:
   * sukces: `status = ready`, `step = none`
   * porażka: `status = failed`, `step` zostaje na etapie błędu

---

## 6. Aktualny flow scheduled sleep

1. podczas tworzenia serwera można ustawić `sleepAt`
2. jeśli `sleepAt` jest puste, nic nie jest planowane
3. jeśli `sleepAt` jest ustawione, panel dispatchuje `StopServerMessage` z opóźnieniem do tej daty
4. w chwili osiągnięcia `sleepAt` worker provisioning wykonuje `EC2 StopInstances`
5. wynik końcowy:
   * sukces: `status = stopped`, `step = none`
   * porażka: status pozostaje bez zmiany, w `lastError` i operation log pojawia się błąd

Uwagi:

* to jest stop instancji EC2, nie delete i nie terminate
* rekord serwera pozostaje w bazie
* jeśli wiadomość dotrze zbyt wcześnie albo instancja nie ma jeszcze `awsInstanceId`, worker ponowi próbę

---

## 7. Aktualny flow resetu hasła admina OTS

1. trigger pochodzi z jednego z dwóch źródeł:
   * automatycznie po zakończeniu provisioningu (`post-provisioning`)
   * ręcznie z akcji admin (`manual-reset`)
2. panel dispatchuje `RotateAdminPasswordMessage` na transport `provisioning`
3. worker provisioning wykonuje bezpośrednie call-e REST do OTS API:
   * `GET /api/login` (csrf + cookie)
   * `POST /api/login`
   * `POST /api/password/change`
4. wynik końcowy:
   * sukces: aktualizacja `otsAdminPasswordCurrent`, `otsAdminPasswordPrevious`, `otsAdminPasswordRotatedAt`
   * porażka: zapis błędu do `lastError` + log `OTS admin password rotation attempt failed`

Uwagi:

* reset hasła nie używa już zdalnego skryptu przez SSM
* manual reset pokazuje hasło od razu w flashu
* reveal po provisioningu jest jednorazowy (`otsAdminPasswordPendingReveal` czyszczone po pokazaniu)

### Szybka diagnostyka gdy reset hasła nie działa

1. sprawdź worker provisioning:

```bash
docker compose logs --tail=200 worker_provisioning
```

2. szukaj logów:

* `OTS admin password rotation attempt started`
* `OTS admin password rotation attempt succeeded`
* `OTS admin password rotation attempt failed`

3. sprawdź czy domena serwera odpowiada HTTPS i czy endpointy OTS są osiągalne:

```bash
curl -k -I https://<server-domain>/api/login
```

---

## 8. Statusy i ich znaczenie operacyjne

### Statusy

* `creating` — rekord został utworzony, provisioning dopiero startuje
* `provisioning` — trwa główny flow provisioning
* `cert_pending` — DNS/SSL nie są jeszcze gotowe końcowo
* `diagnosing` — trwa diagnoza uruchomiona z panelu
* `ready` — serwer gotowy
* `failed` — flow zakończył się błędem
* `deleted` — rekord logicznie usunięty / cleanup zakończony
* `stopped` — instancja została zatrzymana na AWS, nie została usunięta ani terminowana

### Kroki

* `none` — brak aktywnego kroku; w adminie powinno być to widoczne jako puste pole
* `ec2`
* `wait_ip`
* `dns`
* `wait_dns`
* `wait_ssm`
* `provision`
* `cert`
* `cleanup`

---

## 9. Dobra praktyka po zmianach backendu

Po zmianach dotyczących Messengera, workerów albo enumów zawsze wykonaj:

```bash
docker compose restart worker_provisioning worker_projection
```

To powinno być traktowane jako standardowa część lokalnej weryfikacji zmian.

---

## 10. Sensowne następne usprawnienia

Najbardziej wartościowe operacyjnie rzeczy do dodania:

1. failure transport dla Messengera, żeby wiadomości po wyczerpaniu retry nie znikały
2. osobny ekran/admin action do ponownego wysłania wiadomości z failure transport
3. auto-refresh dashboardu workerów i detail view serwera
4. komenda Symfony typu `app:ops:diagnose-server <id>` do bezpiecznej operacyjnej diagnozy bez ręcznych skryptów `php -r`
5. prosty healthcheck workerów na poziomie aplikacji, nie tylko RabbitMQ consumer count