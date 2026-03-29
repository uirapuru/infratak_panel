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
  - createdAt
  - updatedAt
- Enums:
  - ServerStatus: creating, provisioning, cert_pending, ready, failed, stopped
  - ServerStep: ec2, wait_ip, dns, wait_dns, provision, cert

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
  - validates and normalizes input
  - creates Server row
  - dispatches async CreateServerMessage
- DeleteServerProcessor:
  - dispatches async DeleteServerMessage
  - removes row

### Async Layer (Messenger)
- Message classes:
  - CreateServerMessage
  - DeleteServerMessage
- Handlers:
  - CreateServerHandler
  - DeleteServerHandler
- Transport:
  - async AMQP transport configured (RabbitMQ DSN in env)
- Retry behavior:
  - max attempts: 5
  - delay range: 10s-30s for orchestration retries
  - failure state persisted in DB

### Provisioning Orchestration
ProvisioningOrchestrator executes step-by-step flow:
1. ec2 (create instance)
2. wait_ip (poll instance IP)
3. dns (create Route53 records)
4. wait_dns (DNS readiness check)
5. provision (SSM script for nginx + HTTP)
6. cert (SSM certbot command)
7. set ready status

Rules respected in implementation:
- no AWS calls in controllers/processors
- no provisioning in HTTP request cycle
- provisioning logic in service + async handler
- step-based resumable flow

### AWS Integration
- EC2:
  - runInstances
  - describeInstances
  - terminateInstances
- Route53:
  - UPSERT A records for main and portal domains
- SSM:
  - sendCommand with AWS-RunShellScript
  - used for provisioning and certbot

### Admin and Operations
- Health endpoint:
  - GET /health
- EasyAdmin:
  - /admin dashboard
  - server list/details/edit/delete
  - action: retry provisioning (queues CreateServerMessage)

### Docker Setup
Configured services:
- php
- nginx
- mariadb
- rabbitmq

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

## Known Gaps / Next Work
- Add automated tests (unit + integration + e2e flow checks)
- Harden idempotency for each external step
- Add richer status observability and structured logs
- Add explicit wait/verification for SSM command completion when needed
- Add CI pipeline for lint/test/migration checks
