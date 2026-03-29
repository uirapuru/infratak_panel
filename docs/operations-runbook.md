# Infratak Operations Runbook

## Cel

Krótka instrukcja operacyjna do diagnozowania problemów z workerami, kolejkami i flow provisioning/diagnose.

---

## 1. Aktualna topologia kolejek

Biznesowe kolejki AMQP są dwie:

1. `infratak.provisioning`
2. `infratak.projection`

Uwagi:

* `CreateServerMessage`, `DeleteServerMessage` i `DiagnoseServerMessage` używają transportu `provisioning`
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

## 6. Statusy i ich znaczenie operacyjne

### Statusy

* `creating` — rekord został utworzony, provisioning dopiero startuje
* `provisioning` — trwa główny flow provisioning
* `cert_pending` — DNS/SSL nie są jeszcze gotowe końcowo
* `diagnosing` — trwa diagnoza uruchomiona z panelu
* `ready` — serwer gotowy
* `failed` — flow zakończył się błędem
* `deleted` — rekord logicznie usunięty / cleanup zakończony
* `stopped` — status rezerwowy na przyszłość

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

## 7. Dobra praktyka po zmianach backendu

Po zmianach dotyczących Messengera, workerów albo enumów zawsze wykonaj:

```bash
docker compose restart worker_provisioning worker_projection
```

To powinno być traktowane jako standardowa część lokalnej weryfikacji zmian.

---

## 8. Sensowne następne usprawnienia

Najbardziej wartościowe operacyjnie rzeczy do dodania:

1. failure transport dla Messengera, żeby wiadomości po wyczerpaniu retry nie znikały
2. osobny ekran/admin action do ponownego wysłania wiadomości z failure transport
3. auto-refresh dashboardu workerów i detail view serwera
4. komenda Symfony typu `app:ops:diagnose-server <id>` do bezpiecznej operacyjnej diagnozy bez ręcznych skryptów `php -r`
5. prosty healthcheck workerów na poziomie aplikacji, nie tylko RabbitMQ consumer count