# Infratak MVP - Current State for AI

## Project Goal
Backend orchestrator that provisions per-user ATAK (OpenTAK) instances on AWS in a fully async, step-based, resumable flow.

## Stack in Use
- PHP 8.4+
- Symfony 7.3
- API Platform
- Symfony Messenger
- RabbitMQ
- MariaDB
- Docker
- AWS SDK for PHP (EC2, Route53, SSM)
- EasyAdmin

## What Is Already Implemented

### Domain Model
- Server entity with fields:
  - id (UUID string)
  - name (unique)
  - domain
  - portalDomain
  - status
  - step
  - awsInstanceId
  - publicIp
  - lastError
  - lastRetryAt
  - sleepAt
  - lastDiagnoseStatus
  - lastDiagnoseLog
  - lastDiagnosedAt
  - createdAt
  - updatedAt
- Enums:
  - ServerStatus: creating, diagnosing, provisioning, cert_pending, ready, failed, deleted, stopped
  - ServerStep: none, ec2, wait_ip, dns, wait_dns, wait_ssm, provision, cert, cleanup

### API Layer (API Platform)
- Server resource operations:
  - GET /servers
  - GET /servers/{id}
  - POST /servers
  - DELETE /servers/{id}
- POST input DTO with validation:
  - name required
  - lowercase letters, digits, dash

### Processor Layer
- CreateServerProcessor:
  - validates input
  - delegates to shared ServerCreationService
  - creates Server row and dispatches async CreateServerMessage
- DeleteServerProcessor:
  - dispatches async DeleteServerMessage
  - removes row

### Shared Create Use Case
- ServerCreationService is the single creation entry point for both API and EasyAdmin:
  - initializes canonical values (name/domain/portalDomain/status/step)
  - persists Server
  - dispatches CreateServerMessage to provisioning queue

### Async Layer (Messenger)
- Message classes:
  - CreateServerMessage
  - DeleteServerMessage
  - DiagnoseServerMessage
  - StopServerMessage
  - ServerProjectionMessage
- Handlers:
  - CreateServerHandler
  - DeleteServerHandler
  - DiagnoseServerHandler
  - StopServerHandler
  - ServerProjectionHandler
- Transport:
  - provisioning AMQP transport for AWS orchestration
  - projection AMQP transport for status/log projection updates
  - Diagnose messages are routed to the existing provisioning transport (there is no third business queue)
- Retry behavior:
  - max attempts: 5
  - delay range: 10s-30s for orchestration retries
  - projection worker persists status and logs in DB
  - no failure transport is configured yet; after max retries messages are removed from transport

### Provisioning Orchestration
ProvisioningOrchestrator executes step-by-step flow:
1. ec2 (create instance)
2. wait_ip (poll instance IP)
3. dns (create Route53 records)
4. wait_dns (DNS readiness check)
5. wait_ssm (instance profile + SSM managed readiness diagnostics)
6. provision (SSM script for nginx + HTTP)
7. cert (SSM certbot command)
8. set ready status and clear step to none

Rules respected in implementation:
- no AWS calls in controllers/processors
- no provisioning in HTTP request cycle
- provisioning logic in service + async handler
- step-based resumable flow

### AWS Integration
- EC2:
  - stopInstances
  - runInstances
  - describeInstances
  - terminateInstances
  - launch is fail-fast when AWS_INSTANCE_PROFILE_NAME is missing
  - launch uses configured SecurityGroupIds and SubnetId
- Route53:
  - UPSERT A records for main and portal domains
- SSM:
  - sendCommand with AWS-RunShellScript
  - wait_ssm checks readiness and logs diagnostics (instanceId, hasIamProfile, ssmManaged)
  - InvalidInstanceId during SendCommand is treated as retryable race (agent/registration not ready yet)
  - provisioning and cert steps wait for real SSM completion before progressing
  - used for provisioning and certbot

### Provisioning Submodule
- Git submodule added at:
  - infra/provisioning
- Worker provisioning uses submodule assets as source of truth for:
  - provisioning.sh
  - nginx/ templates
- Execution model:
  - local read/render inside Symfony worker
  - remote execution through AWS SSM
- This keeps the orchestrator on SSM while reusing the provisioning repository contents.

### Admin and Operations
- Health endpoint:
  - GET /health
- EasyAdmin:
  - /admin dashboard
  - dashboard cards for ready, failed, and in-progress servers
  - dashboard worker cards for provisioning/projection consumer visibility
  - server list/details/edit/delete
  - optional sleepAt datetime may be provided on create and is visible on index/detail
  - if sleepAt is set, panel schedules a future StopServerMessage for AWS stop
  - creating a new server from EasyAdmin queues provisioning via ServerCreationService
  - create form should expose only user-provided input such as name; system fields like lastError/status/step are managed asynchronously
  - enum fields such as status and step are rendered as badges in admin
  - action: retry provisioning (queues CreateServerMessage)
  - action: diagnose (queues DiagnoseServerMessage on provisioning transport)
  - diagnose sets status=diagnosing while running
  - diagnose writes final status ready or failed
  - step is cleared when server returns to ready; on failed diagnose the step remains at the failure point
  - operation log screen in admin menu

### Projection Log Model
- New table/entity: server_operation_log
- Each provisioning/projection event stores:
  - level
  - status/step snapshot
  - message
  - JSON context
  - timestamp

### Docker Setup
Configured services:
- php
- nginx
- mariadb
- rabbitmq
- worker_provisioning
- worker_projection

Worker details:
- workers use restart: unless-stopped
- provisioning and projection are long-running PHP processes and do not hot-reload code
- after changing enums, handlers, or messenger-related code, workers must be restarted

Main files:
- compose.yaml
- compose.override.yaml
- docker/php/Dockerfile
- docker/nginx/conf.d/default.conf

### Persistence
- Migration created for server table:
  - migrations/Version20260329164000.php

## Important Runtime Notes
- Fill real AWS values in .env before production use:
  - AWS_REGION
  - AWS_EC2_AMI_ID
  - AWS_ROUTE53_HOSTED_ZONE_ID
  - AWS_INSTANCE_PROFILE_NAME (if needed)
- Ensure worker is running to process async messages.
- For full async flow run dedicated workers:
  - docker compose up -d worker_provisioning worker_projection
- After backend changes affecting Messenger handlers/enums, restart workers:
  - docker compose restart worker_provisioning worker_projection
- Before opening /admin on a fresh environment, run migrations:
  - docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

Diagnose runtime notes:
- Diagnose is asynchronous and uses the provisioning queue/worker
- While diagnose is running, server status should be diagnosing
- A stuck diagnosing status with no updates usually indicates one of:
  - worker not running
  - worker running old code and needing restart
  - diagnose message exhausted retries and was removed from transport

## Known Gaps / Next Work
- Add automated tests (unit + integration + e2e flow checks)
- Harden idempotency for each external step
- Add richer status observability and structured logs
- Add Messenger failure transport for post-mortem recovery of exhausted messages
- Add CI pipeline for lint/test/migration checks
- Decide whether to continue evolving the submodule through SSM adapters or migrate more of its Makefile logic into native Symfony services
- Resolve AWS organization SCP blockers or use isolated account without SCP restrictions for MVP reliability

## AWS + SSM Lessons Learned
- IAM User and EC2 IAM Role are separate concerns:
  - IAM User (`infratak-provisioner`) is for backend/CLI API access
  - EC2 IAM Role in instance profile is required for in-instance SSM execution
- Missing EC2 instance profile causes downstream provisioning failures at SSM steps even if EC2 launch and DNS succeed
- AWS organization SCP can block required diagnostics APIs (for example DescribeInstanceInformation) regardless of attached IAM policy
- When SCP blocks required APIs, provisioning reliability is environment-constrained and this must be documented as an external blocker
