#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${ENV_FILE:-${ROOT_DIR}/.env.deploy}"
COMPOSE_FILE="${COMPOSE_FILE:-${ROOT_DIR}/compose.prod.yml}"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing env file: ${ENV_FILE}"
  echo "Create it from ${ROOT_DIR}/.env.deploy.example"
  exit 1
fi

if [[ ! -f "${ROOT_DIR}/docker/nginx/.htpasswd" ]]; then
  echo "Missing ${ROOT_DIR}/docker/nginx/.htpasswd"
  echo "Generate it with: htpasswd -nbB admin '<password>' > docker/nginx/.htpasswd"
  exit 1
fi

COMPOSE_CMD=(docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}")

echo "[1/4] Building and starting services"
"${COMPOSE_CMD[@]}" up -d --build --remove-orphans

echo "[2/4] Running migrations"
"${COMPOSE_CMD[@]}" exec -T landing_php php bin/console doctrine:migrations:migrate --no-interaction

echo "[3/4] Clearing Symfony cache"
"${COMPOSE_CMD[@]}" exec -T landing_php php bin/console cache:clear --env=prod

echo "[4/4] Service status"
"${COMPOSE_CMD[@]}" ps

echo "Deploy complete"
