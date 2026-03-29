# Lokalna konfiguracja AWS — krok po kroku

Jak uruchomić lokalnie infratak_panel z profilem AWS `infratak-dev` tak, żeby kontenery PHP i worker używały tych samych credentials.

---

## 1. Co ma finalnie działać

* lokalny profil AWS CLI: `infratak-dev`
* backend (`php`) i oba workery (`worker_provisioning`, `worker_projection`) w Dockerze używają tego samego profilu
* EC2 uruchamiane przez aplikację dostają `IamInstanceProfile`
* provisioning przez SSM działa
* diagnose działa asynchronicznie przez ten sam worker provisioning
* klucze AWS **nie są** wpisane ręcznie do kodu ani do `.env`

---

## 2. Dwie oddzielne tożsamości AWS

To jest krytyczne rozróżnienie — mieszanie ich prowadzi do błędów, które są trudne do zdebugowania.

### A. IAM user / credentials lokalne

Używany przez:
* AWS CLI na Twojej maszynie
* Symfony app (`php`) w kontenerze
* oba workery Messenger w kontenerze

W projekcie: user **`infratak-provisioner`**, profil lokalny domyślnie **`infratak-dev`**

### B. IAM role dla EC2

Przypinana do instancji EC2 przez `IamInstanceProfile` w momencie `RunInstances`.

W projekcie:
* rola: **`infratak-ec2-ssm-role`** z policy `AmazonSSMManagedInstanceCore`
* instance profile: **`infratak-ec2-profile`**

Jeśli `AWS_INSTANCE_PROFILE_NAME` jest puste, aplikacja rzuci `FinalException` **zanim** odpali `RunInstances` — intencjonalne fail-fast.

---

## 3. Skonfiguruj lokalny profil AWS CLI

```bash
aws configure --profile infratak-dev
```

Podaj:
* `AWS Access Key ID`
* `AWS Secret Access Key`
* `Default region name` → `eu-central-1`
* `Default output format` → `json`

Sprawdź:

```bash
aws sts get-caller-identity --profile infratak-dev
```

Powinieneś zobaczyć usera `infratak-provisioner`.

Oczekiwana struktura plików:

`~/.aws/config`

```ini
[profile infratak-dev]
region = eu-central-1
output = json
```

`~/.aws/credentials`

```ini
[infratak-dev]
aws_access_key_id = TU_KEY
aws_secret_access_key = TU_SECRET
```

---

## 4. Zmienne środowiskowe wymagane przez PHP AWS SDK

To jest najczęstszy punkt pomyłek. Oprócz `AWS_PROFILE` i `AWS_REGION` SDK wymaga dodatkowych flag środowiskowych:

| Zmienna                     | Wartość         | Po co                                                                                                |
|-----------------------------|-----------------|------------------------------------------------------------------------------------------------------|
| `AWS_SDK_LOAD_CONFIG`       | `1`             | Każe PHP AWS SDK załadować `~/.aws/config`. **Bez tego named profiles nie działają.**                |
| `AWS_EC2_METADATA_DISABLED` | `true`          | Blokuje próbę odpytania EC2 metadata service (169.254.169.254). W Dockerze ten endpoint nie istnieje; bez tej flagi SDK będzie timeout-ował próbując uzyskać credentials stamtąd. |

W aktualnym `compose.yaml` ustawione są `AWS_PROFILE`, `AWS_REGION`, `AWS_SDK_LOAD_CONFIG` i `AWS_EC2_METADATA_DISABLED`. Przy każdej modyfikacji compose zachowaj je.

> `AWS_DEFAULT_REGION` nie jest obecnie wymagane przez samą aplikację, bo klienci AWS w kodzie dostają region jawnie z konfiguracji Symfony. Możesz dodać tę zmienną lokalnie, jeśli używasz dodatkowych ad-hoc skryptów PHP/CLI, ale dokumentacja projektu nie zakłada jej jako obowiązkowej.

> **SDK retry config:** Przy tworzeniu klientów SSM/EC2 ustaw `'retries' => 5` (lub odpowiednią wartość), żeby SDK sam ponawiał przy przejściowych błędach sieciowych — to osobna warstwa ochrony poza logiką `wait_ssm`:
> ```php
> new SsmClient([
>     'version' => 'latest',
>     'region'  => $region,
>     'retries' => 5,
> ]);
> ```

---

## 5. Bind mount `~/.aws` do kontenerów

Kontenery PHP działają jako `root` (`php:8.4-fpm` bez zmiany usera w Dockerfile). Dlatego katalog AWS montowany jest do `/root/.aws`.

**Nie wkładaj kluczy do Dockera ręcznie.** Bind mount to jedyna właściwa droga lokalnie.

Montuj tylko do kontenerów, które wywołują AWS API: `php`, `worker_provisioning`, `worker_projection`. Bez `nginx`, `mariadb`, `rabbitmq`.

---

## 6. Jak wygląda `compose.yaml` dla kontenerów AWS

Poniższy fragment odzwierciedla aktualny `compose.yaml`:

```yaml
services:
  php:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    working_dir: /app
    environment:
      AWS_PROFILE: ${AWS_PROFILE:-infratak-dev}
      AWS_REGION: ${AWS_REGION:-eu-central-1}
      AWS_SDK_LOAD_CONFIG: "1"
      AWS_EC2_METADATA_DISABLED: "true"
    volumes:
      - ./:/app
      - ~/.aws:/root/.aws:ro

  worker_provisioning:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    working_dir: /app
    command: php bin/console messenger:consume provisioning --time-limit=3600 --memory-limit=256M
    environment:
      AWS_PROFILE: ${AWS_PROFILE:-infratak-dev}
      AWS_REGION: ${AWS_REGION:-eu-central-1}
      AWS_SDK_LOAD_CONFIG: "1"
      AWS_EC2_METADATA_DISABLED: "true"
    volumes:
      - ./:/app
      - ~/.aws:/root/.aws:ro

  worker_projection:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    working_dir: /app
    command: php bin/console messenger:consume projection --time-limit=3600 --memory-limit=256M
    environment:
      AWS_PROFILE: ${AWS_PROFILE:-infratak-dev}
      AWS_REGION: ${AWS_REGION:-eu-central-1}
      AWS_SDK_LOAD_CONFIG: "1"
      AWS_EC2_METADATA_DISABLED: "true"
    volumes:
      - ./:/app
      - ~/.aws:/root/.aws:ro
```

> **Uwaga:** użycie `~/.aws` zamiast `${HOME}/.aws` działa poprawnie na Linux, Mac i w CI (np. GitHub Actions). `${HOME}` bywa problematyczne w niektórych środowiskach CI.

---

## 7. Zmienne w `.env` projektu

Poniższe zmienne muszą być ustawione w `.env` (lub `.env.local`). Wartości z rzeczywistymi ID trzymaj lokalnie — nie commituj sekretów.

```env
AWS_REGION=eu-central-1
AWS_PROFILE=infratak-dev
AWS_EC2_AMI_ID=ami-xxxxxxxxxxxxxxxxx
AWS_EC2_INSTANCE_TYPE=t3.medium
AWS_EC2_SECURITY_GROUP_ID=sg-xxxxxxxxxxxxxxxxx
AWS_EC2_SUBNET_ID=subnet-xxxxxxxxxxxxxxxxx
AWS_ROUTE53_HOSTED_ZONE_ID=ZXXXXXXXXXXXXXXXXXXXX
AWS_INSTANCE_PROFILE_NAME=infratak-ec2-profile
```

> **Uwaga:** klucze AWS (`aws_access_key_id`, `aws_secret_access_key`) **nigdy** nie trafiają do `.env`. Przychodzą wyłącznie z `~/.aws/credentials`.

---

## 8. Testowanie — co sprawdzić po `docker compose up -d`

### Na hoście

```bash
aws sts get-caller-identity --profile infratak-dev
```

### W kontenerze — zmienne środowiskowe

```bash
docker compose exec php printenv AWS_PROFILE
docker compose exec php printenv AWS_SDK_LOAD_CONFIG
docker compose exec php printenv AWS_EC2_METADATA_DISABLED
docker compose exec php sh -lc 'ls -la /root/.aws'
docker compose exec php sh -lc 'cat /root/.aws/config'
```

### W kontenerze — test AWS SDK przez PHP

Kontenery **nie mają zainstalowanego AWS CLI**. Test połączenia robisz przez PHP AWS SDK:

```bash
docker compose exec php php -r "
require '/app/vendor/autoload.php';
\$sts = new \Aws\Sts\StsClient(['version' => '2011-06-15', 'region' => 'eu-central-1']);
var_dump(\$sts->getCallerIdentity()->toArray());
"
```

---

## 9. Wymagania po stronie AWS

### EC2 must-have

* **Instance profile**: `infratak-ec2-profile` — musi istnieć przed uruchomieniem provisioning
* **Rola**: `infratak-ec2-ssm-role` z policy `AmazonSSMManagedInstanceCore`
* Bez instance profile aplikacja rzuci `FinalException` przed `RunInstances`

### IAM user `infratak-provisioner` musi mieć dostęp do

* EC2: `RunInstances`, `DescribeInstances`, `TerminateInstances`
* SSM: `SendCommand`, `GetCommandInvocation`, `DescribeInstanceInformation`, `ListCommandInvocations`
* Route53: `ChangeResourceRecordSets`, `ListHostedZones`
* IAM: **`PassRole`** (krytyczne — bez tego `RunInstances` z `IamInstanceProfile` zwróci `AccessDenied`)

> **`iam:PassRole` — MUST HAVE.** Bez tego EC2 w ogóle się nie uruchomi z profilem. Policy musi wyglądać dokładnie tak:
>
> ```json
> {
>   "Effect": "Allow",
>   "Action": "iam:PassRole",
>   "Resource": "arn:aws:iam::ACCOUNT_ID:role/infratak-ec2-ssm-role"
> }
> ```
>
> Zastąp `ACCOUNT_ID` numerem konta AWS. Użycie `*` jako Resource jest dopuszczalne lokalnie/dev, ale niepotrzebnie szerokie.

---

## 10. SCP / blokery organizacyjne

Jeśli konto AWS należy do organizacji z restrictive SCP, niektóre akcje mogą być zablokowane **mimo poprawnie skonfigurowanego usera i profilu.** Objawia się to jako `AccessDeniedException` / `403` dla akcji takich jak `ssm:DescribeInstanceInformation`.

Rozwiązania:
1. Poproś administratora org o wyłączenie SCP dla konta deweloperskiego
2. Użyj osobnego konta AWS bez SCP (najszybsza droga dla MVP)

Aplikacja rzuci `FinalException` jeśli `getSsmDiagnostics()` dostanie `AccessDeniedException` — jest to celowe, żeby nie ukrywać blokera organizacyjnego jako retryable failure.

---

## 11. Checklista końcowa

Wykonaj w tej kolejności:

1. `aws configure --profile infratak-dev`
2. `aws sts get-caller-identity --profile infratak-dev` — potwierdź, że widzisz `infratak-provisioner`
3. Uzupełnij `.env` / `.env.local`:
   - `AWS_PROFILE=infratak-dev`
   - `AWS_REGION=eu-central-1`
   - `AWS_INSTANCE_PROFILE_NAME=infratak-ec2-profile`
   - `AWS_EC2_SECURITY_GROUP_ID`, `AWS_EC2_SUBNET_ID`, `AWS_ROUTE53_HOSTED_ZONE_ID`
4. Potwierdź, że `compose.yaml` ma `AWS_SDK_LOAD_CONFIG=1` i `AWS_EC2_METADATA_DISABLED=true`
5. `docker compose up -d`
6. `docker compose exec php sh -lc 'ls -la /root/.aws'` — potwierdź widoczność plików
7. Test PHP AWS SDK (punkt 8)
8. Upewnij się, że instancja EC2 `infratak-ec2-profile` istnieje w AWS IAM
9. Testuj provisioning

---

## 12. Najczęstsze błędy i przyczyny

| Objaw                                       | Przyczyna                                       | Fix                                                          |
|---------------------------------------------|--------------------------------------------------|--------------------------------------------------------------|
| `CredentialsException` w kontenerze         | Brak `AWS_SDK_LOAD_CONFIG=1`                    | Dodaj env do compose                                         |
| SDK się "wiesza" przy starcie               | Brak `AWS_EC2_METADATA_DISABLED=true`           | Dodaj env do compose                                         |
| SDK nie widzi regionu                       | Brak `AWS_DEFAULT_REGION`                       | Dodaj do compose i `.env`                                    |
| `InvalidInstanceId` z SSM SendCommand       | EC2 nie ma instance profile lub agent nie gotowy | Poczekaj (`wait_ssm` step) lub sprawdź IAM role na instancji |
| `AccessDenied` przy `RunInstances`          | Brak `iam:PassRole` dla `infratak-provisioner`  | Dodaj policy z `iam:PassRole` na `infratak-ec2-ssm-role`     |
| `FinalException: AWS_INSTANCE_PROFILE_NAME` | Zmienna pusta w `.env`                           | Ustaw `AWS_INSTANCE_PROFILE_NAME=infratak-ec2-profile`       |
| `AccessDeniedException` na SSM describe     | Brak policy dla usera lub SCP blokuje           | Dodaj policy / zmień konto                                   |
| EC2 startuje bez IAM profile                | Pusta zmienna lub brak `IamInstanceProfile` w `RunInstances` | Sprawdź `AwsProvisioningClient::createEc2Instance()`    |

---

## 13. SSM readiness — must-have przed `SendCommand`

Po uruchomieniu EC2 **nie zakładaj, że SSM agent jest gotowy**. Między `RunInstances` a momentem, gdy agent się zarejestruje, mija 30–120 sekund. Wysłanie `SendCommand` za wcześnie zwraca `InvalidInstanceId`.

**Jak to działa w projekcie:**

Orchestrator wstrzymuje provisioning w kroku `wait_ssm`, który:
- wywołuje `DescribeInstanceInformation` co kilka sekund
- powtarza aż `PingStatus = Online` (lub do wyczerpania retry)
- dopiero wtedy przechodzi do `provision` (`SendCommand`)

Krok `wait_ssm` jest już zaimplementowany w `ProvisioningOrchestrator`. Jeśli go usuniesz albo pominiesz, provisioning będzie losowo failował w zależności od tego, jak szybko agent się załaduje.

Przepływ: `ec2 → wait_ip → dns → wait_dns → wait_ssm → provision → cert`

---

## 14. Workery Messenger — ważne po zmianach w kodzie

Workery `worker_provisioning` i `worker_projection` są długowiecznymi procesami PHP. To oznacza, że:

* po zmianie enumów (`ServerStatus`, `ServerStep`), handlerów Messengera albo routingu wiadomości **musisz zrestartować workery**,
* samo `docker compose up -d` nie gwarantuje przeładowania już działającego procesu,
* bez restartu worker może konsumować wiadomości na starym kodzie i wpadać w retry/fail.

Komenda operacyjna:

```bash
docker compose restart worker_provisioning worker_projection
```

Praktyczny przykład z projektu:

* po dodaniu statusu `diagnosing` worker provisioning, który nie został zrestartowany, nie umiał zhydradować nowej wartości enumu,
* `DiagnoseServerMessage` retry-ował 5 razy,
* po 5 próbach wiadomość została usunięta z transportu,
* rekord serwera został w stanie `DIAGNOSING`, mimo że kolejka była pusta.

Wniosek: po zmianach backendowych związanych z Messengerem restart workerów jest częścią standardowej procedury deploy/local dev.

---

## 15. Diagnose — jak działa teraz

`Diagnose` nie działa synchronicznie w request/response.

Obecny flow:

1. kliknięcie `Diagnose` w EasyAdmin ustawia:
  * `status = diagnosing`
  * `step = wait_ssm`
  * `lastDiagnoseStatus = running`
2. wiadomość `DiagnoseServerMessage` trafia na transport `provisioning`
3. worker provisioning wykonuje diagnozę przez AWS SSM
4. podczas wykonania `step` przechodzi na `provision`
5. wynik końcowy:
  * sukces: `status = ready`, `step = none`
  * porażka: `status = failed`, `step` zostaje na etapie błędu

Jeśli diagnose „nic nie robi”, sprawdź w tej kolejności:

1. `docker compose ps`
2. `docker compose exec rabbitmq rabbitmqctl list_queues name consumers messages`
3. `docker compose logs --tail=120 worker_provisioning`

---

## 16. Runtime guards — co aplikacja sprawdza automatycznie

Następujące guardy są **już zaimplementowane w kodzie** (`AwsProvisioningClient`, `CreateServerHandler`):

| Guard                                           | Gdzie                                    | Efekt przy braku                    |
|-------------------------------------------------|------------------------------------------|-------------------------------------|
| `AWS_INSTANCE_PROFILE_NAME` niepuste            | przed `RunInstances`                     | `FinalException` — provisioning nie startuje |
| `IamInstanceProfile` zawsze przekazywany do EC2 | `RunInstances`                           | EC2 bez roli → SSM nigdy nie działa |
| `AccessDeniedException` w SSM diagnostics      | `getSsmDiagnostics()`                    | `FinalException` — SCP blocker widoczny w logach |
| `InvalidInstanceId` w `SendCommand`             | `sendSsmCommandAndWait()`                | `RetryableProvisioningException` — agent jeszcze nie gotowy |

Dodatkowy guard, który warto dodać przy inicjalizacji workera:

```php
if (!getenv('AWS_PROFILE')) {
    throw new \RuntimeException('AWS_PROFILE is not set');
}
if (!is_dir(getenv('HOME') . '/.aws')) {
    throw new \RuntimeException('~/.aws directory not found in container — check bind mount');
}
```
