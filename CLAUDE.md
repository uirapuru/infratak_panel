# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Infratak Panel is a **server provisioning orchestrator** — not a CRUD or dashboard app. Its core job is to reliably provision OpenTAK (OTS) server instances on AWS by coordinating: EC2 launch → IP assignment → DNS via Route 53 → SSM readiness → shell provisioning → Let's Encrypt certificates. All provisioning is async via Symfony Messenger.

## Common Commands

### Local development (Docker)
```bash
# Start all services (nginx on :8080, mariadb on :3306, rabbitmq on :5672/:15672, mailcatcher on :1080)
docker compose up -d

# Run PHP commands inside container
docker compose exec php php bin/console <command>

# Database migrations
docker compose exec php php bin/console doctrine:migrations:migrate

# Create admin user
docker compose exec php php bin/console app:admin:create-user <email> <password>

# Set/reset admin password
docker compose exec php php bin/console app:admin:set-password <email> <password>

# Consume worker queues manually
docker compose exec php php bin/console messenger:consume provisioning
docker compose exec php php bin/console messenger:consume projection

# View worker logs
tail -f var/log/worker_provisioning.log
tail -f var/log/worker_projection.log
tail -f var/log/aws.log
```

### Playwright E2E tests
```bash
make playwright-install   # install deps + Chromium
make playwright-test      # run headless (spins up docker compose first)
make playwright-test-ui   # run with Playwright UI
make playwright-test-headed
```
Tests live in `tests/e2e/`.

### Production deployment
```bash
make setup          # one-time: create EC2 + DNS + full deploy
make deploy-prod    # incremental deploy (rsync + docker up + certbot + migrations)
make tls-status     # check TLS certificate on server
make tls-renew      # force certificate renewal
make deploy-rotate-secrets  # rotate APP_SECRET + ADMIN_BASIC_AUTH_PASSWORD
```

## Architecture

### Dual-role deployment
A single PHP codebase serves two roles, selected via `APP_ROLE` env var:
- **`landing`** — public site (`/`, `/register`, `/login`, etc.). Blocks `/admin`.
- **`admin`** — EasyAdmin panel (`/admin` only). Blocks everything else.

`RouteGuardSubscriber` enforces this at the Symfony level. Nginx enforces it at the network level. In dev, both roles share one container with `APP_ROLE=admin`.

### Async provisioning pipeline
All provisioning runs in background workers consuming RabbitMQ queues:

1. **HTTP request** → `CreateServerProcessor` saves entity → dispatches `CreateServerMessage` to `provisioning` queue
2. **`worker_provisioning`** → `CreateServerHandler` → `ProvisioningOrchestrator::advance()` → AWS calls via `AwsProvisioningClient`
3. Each step updates `Server::$step` and re-dispatches the message (with `DelayStamp`) until provisioning is complete
4. State changes are persisted via `ServerProjectionMessage` → `worker_projection` → `ServerProjectionHandler` writes to DB and creates `ServerOperationLog` entries

**Provisioning steps** (in order, stored in `ServerStep` enum):
`EC2 → WAIT_IP → DNS → WAIT_DNS → WAIT_SSM → PROVISION → CERT → NONE (ready)`

**Two separate queues/workers:**
- `provisioning` — all mutation operations (create/delete/stop/start/diagnose/rotate-password)
- `projection` — read-model persistence (status updates + operation logs). Never mix these.

### Retry and failure
- `RetryableProvisioningException` → retry (max 5 attempts, 10–30s delay)
- `FinalException` → immediate `FAILED` status, no retry
- Generic `\Throwable` → treated as retryable
- After 5 attempts → status `FAILED`, `lastError` persisted

### Key service boundaries
- **No AWS calls in controllers** — only in `AwsProvisioningClient`
- **No provisioning in HTTP request lifecycle** — always via Messenger
- **No business logic in API Platform processors** — processors only initialize state and dispatch messages

### OTS admin password rotation
- Triggered by `RotateAdminPasswordMessage` (post-provisioning or manual admin reset)
- Executed via `OtsApiClient` (HTTPS calls to OTS API: `/api/login` + `/api/password/change`)
- New password stored in `otsAdminPasswordCurrent`; shown once via `otsAdminPasswordPendingReveal` flash on first admin detail view

### Infrastructure submodule
`infra/provisioning/` is a git submodule containing:
- `provisioning.sh` — the shell script sent to EC2 via SSM
- `nginx/` — nginx config templates (placeholders `__DOMAIN__`, `__PORTAL_DOMAIN__` replaced at runtime)
- `Makefile` — its `cleanup` target is validated before any teardown operation

`SubmoduleProvisioningAssets` reads these files and builds SSM command arrays. EC2 communication is SSM-only (no SSH).

## Project Rules (from `docs/project-rules.md`)

- **After every code/config change**, add an entry to `docs/changelog.md` (date ISO, summary, files modified, rationale). This is mandatory.
- After every update: also update docs in `docs/` and `.env.example` if env vars changed.
- After changing enums, handlers, or Messenger routing: **restart workers** so they reload current code.
- Keep admin layer (EasyAdmin controllers) free of business logic.

## Environment Files

| File | Purpose |
|---|---|
| `.env` | Symfony defaults (committed) |
| `.env.deploy` | Production secrets — **gitignored**, generated by `make deploy-prepare-env` |
| `.env.deploy.example` | Template for `.env.deploy` |
| `.env.infra` | AWS credentials/region/zone ID — **gitignored** |
| `.env.infra.example` | Template for `.env.infra` |
| `var/share/.aws/` | AWS profile copied from `.env.infra` for production containers |

## Logging

Separate Monolog channels write to separate files in `/var/log/infratak/`:
- `worker_provisioning.log` — provisioning steps, retries, failures
- `worker_projection.log` — read-model persistence
- `aws.log` — all AWS SDK calls (errors with full context)
- Main app log goes to stderr (prod) or `var/log/dev.log` (dev)
