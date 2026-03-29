# Infratak Project Rules

## Core Product Rule
This system is an orchestrator for reliable server provisioning.
It is not a CRUD-first app and not a dashboard-first app.

## Architecture Rules (Mandatory)
- No business logic in API Platform resource definitions.
- No AWS calls in controllers.
- No provisioning in HTTP request lifecycle.
- All provisioning must be async via Messenger.
- Provisioning must be step-based and resumable.

## Layer Responsibilities
- API Platform: transport and input/output only.
- Processor: input handling, state initialization, message dispatch.
- Application/Provisioning Service: orchestration logic.
- Provisioning Handler: AWS execution coordination and retry scheduling.
- Projection Handler: persistence updates (status/log read model).
- AWS Client layer: all external AWS interactions.

## Queue Topology
- Use dedicated queue/transport for provisioning commands.
- Use dedicated queue/transport for projection updates (status/log events).
- Do not mix provisioning side effects and read-model persistence in one worker.

## Provisioning Flow Contract
Expected steps:
1. ec2
2. wait_ip
3. dns
4. wait_dns
5. provision
6. cert
7. ready

Every step must:
- update persisted state
- support retry
- be resumable after interruption

## Retry and Failure Policy
- Max attempts: 5
- Delay window: 10-30 seconds
- Persist last error message
- Set status=failed when retries are exhausted

## Operational Rules
- Log step start and step end
- Log AWS request/response context where safe
- Log retries and terminal failures
- Keep admin layer free of business logic
- If provisioning assets live in infra/provisioning, treat that submodule as source of truth for provisioning.sh and nginx templates unless explicitly migrated

## Definition of Done (MVP)
- POST /servers queues provisioning
- EC2 instance created
- DNS records created and propagated
- HTTPS enabled via certbot
- No SSH used (SSM only)
- Parallel provisioning supported

## Documentation and Test Discipline (Mandatory)
After every code update, also update:
- documentation in docs/
- relevant automated tests

No change is complete unless code, docs, and tests are consistent.

## Quality Gate Before Merge
- composer install works
- lint/static checks pass
- tests pass
- migration state is valid
- docs reflect final behavior
