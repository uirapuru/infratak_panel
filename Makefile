export AWS_PAGER :=

ENV_FILE ?= .env.deploy
COMPOSE_FILE ?= compose.prod.yml
COMPOSE = docker compose --env-file $(ENV_FILE) -f $(COMPOSE_FILE)

SERVER_IP ?= $(shell cat .server-ip 2>/dev/null || $(MAKE) -s -f Makefile.infra get-ip 2>/dev/null || true)
SERVER_USER ?= ubuntu
SSH_KEY ?= ~/.ssh/infratak-prod-key.pem
APP_DIR ?= /home/ubuntu/app
SSH_OPTS ?= -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15
TLS_DOMAIN ?= infratak.com
LETSENCRYPT_EMAIL ?= admin@infratak.com

SSH = ssh $(SSH_OPTS) -i $(SSH_KEY) $(SERVER_USER)@$(SERVER_IP)
SCP = scp $(SSH_OPTS) -i $(SSH_KEY)
RSYNC = rsync -avz --delete --exclude .git --exclude var/letsencrypt --exclude var/certbot -e "ssh $(SSH_OPTS) -i $(SSH_KEY)"
REMOTE_COMPOSE = docker compose --env-file $(ENV_FILE) -f $(COMPOSE_FILE)

.PHONY: deploy deploy-check deploy-up deploy-migrate deploy-cache-clear deploy-restart deploy-logs deploy-ps deploy-down infra-up infra-ip infra-wait-ready deploy-prod deploy-prepare-env deploy-rotate-secrets setup deploy-one tls-renew tls-status remote-tls-cert playwright-install playwright-test playwright-test-ui playwright-test-headed

deploy: deploy-check deploy-up deploy-migrate deploy-cache-clear
	@echo "Deploy finished successfully."

deploy-check:
	@test -n "$(SERVER_IP)" && [ "$(SERVER_IP)" != "None" ] || (echo "Missing active SERVER_IP. Run 'make -f Makefile.infra create-instance' first." && exit 1)
	@test -f "$(ENV_FILE)" || (echo "Missing $(ENV_FILE). Copy .env.deploy.example to .env.deploy and fill secrets." && exit 1)
	@test -f "docker/nginx/.htpasswd" || (echo "Missing docker/nginx/.htpasswd (required for admin basic auth)." && exit 1)
	@test -n "$(TLS_DOMAIN)" || (echo "Missing TLS_DOMAIN." && exit 1)
	@test -n "$(LETSENCRYPT_EMAIL)" || (echo "Missing LETSENCRYPT_EMAIL." && exit 1)

deploy-up:
	@$(COMPOSE) up -d --build --remove-orphans

deploy-migrate:
	@$(COMPOSE) exec -T landing_php php bin/console doctrine:migrations:migrate --no-interaction

deploy-cache-clear:
	@$(COMPOSE) exec -T landing_php php bin/console cache:clear --env=prod

deploy-restart:
	@$(COMPOSE) restart landing_php landing_nginx admin_php admin_nginx worker_provisioning worker_projection

deploy-logs:
	@$(COMPOSE) logs -f --tail=150 landing_php landing_nginx admin_php admin_nginx

deploy-ps:
	@$(COMPOSE) ps

deploy-down:
	@$(COMPOSE) down

infra-up:
	@$(MAKE) -f Makefile.infra create-instance

infra-ip:
	@$(MAKE) -f Makefile.infra get-ip

infra-wait-ready:
	@set -euo pipefail; \
	IP="$$( $(MAKE) -s -f Makefile.infra get-ip )"; \
	if [ -z "$$IP" ] || [ "$$IP" = "None" ]; then \
		echo "Missing active SERVER_IP. Run 'make -f Makefile.infra create-instance' first."; \
		exit 1; \
	fi; \
	echo "Waiting for instance $$IP to finish cloud-init and install Docker..."; \
	for i in $$(seq 1 60); do \
		if ssh $(SSH_OPTS) -i $(SSH_KEY) $(SERVER_USER)@$$IP "cloud-init status --wait >/dev/null 2>&1 && command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1" >/dev/null 2>&1; then \
			echo "Instance $$IP is ready."; \
			exit 0; \
		fi; \
		sleep 10; \
	done; \
	echo "Timed out waiting for instance $$IP readiness."; \
	exit 1

deploy-prepare-env:
	@set -e; \
	if [ ! -f "$(ENV_FILE)" ]; then \
		cp .env.deploy.example "$(ENV_FILE)"; \
		chmod 600 "$(ENV_FILE)"; \
		echo "Created $(ENV_FILE) from .env.deploy.example"; \
	fi; \
	gen_secret() { \
		if command -v openssl >/dev/null 2>&1; then \
			openssl rand -base64 32; \
		else \
			head -c 48 /dev/urandom | base64; \
		fi | tr -d '\n' | tr '/+=' 'xyz' | cut -c1-40; \
	}; \
	set_env_var() { \
		key="$$1"; \
		value="$$2"; \
		escaped_value="$$(printf '%s\n' "$$value" | sed 's/[&|]/\\&/g')"; \
		if grep -qE "^$${key}=" "$(ENV_FILE)"; then \
			sed -i "s|^$${key}=.*|$${key}=$${escaped_value}|" "$(ENV_FILE)"; \
		else \
			echo "$${key}=$${value}" >> "$(ENV_FILE)"; \
		fi; \
	}; \
	for pair in \
		"MARIADB_PASSWORD:change-me" \
		"MARIADB_ROOT_PASSWORD:change-root-password" \
		"RABBITMQ_DEFAULT_PASS:change-rabbit-password"; do \
		key="$${pair%%:*}"; \
		placeholder="$${pair##*:}"; \
		current="$$(grep -E "^$${key}=" "$(ENV_FILE)" | cut -d= -f2- || true)"; \
		if [ -z "$${current}" ] || [ "$${current}" = "$${placeholder}" ]; then \
			secret="$$(gen_secret)"; \
			set_env_var "$${key}" "$${secret}"; \
			echo "Generated bootstrap value for $${key} in $(ENV_FILE)"; \
		fi; \
	done; \
	for pair in \
		"ADMIN_BASIC_AUTH_PASSWORD:change-admin-password" \
		"APP_SECRET:change-app-secret"; do \
		key="$${pair%%:*}"; \
		placeholder="$${pair##*:}"; \
		current="$$(grep -E "^$${key}=" "$(ENV_FILE)" | cut -d= -f2- || true)"; \
		if [ "$(FORCE_ROTATE_APP)" = "1" ] || [ -z "$${current}" ] || [ "$${current}" = "$${placeholder}" ]; then \
			secret="$$(gen_secret)"; \
			set_env_var "$${key}" "$${secret}"; \
			echo "Generated random value for $${key} in $(ENV_FILE)"; \
		fi; \
	done; \
	admin_user="$$(grep -E '^ADMIN_BASIC_AUTH_USER=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	if [ -z "$$admin_user" ]; then \
		admin_user=admin; \
		set_env_var ADMIN_BASIC_AUTH_USER "$$admin_user"; \
	fi; \
	db_name="$$(grep -E '^MARIADB_DATABASE=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	db_user="$$(grep -E '^MARIADB_USER=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	db_pass="$$(grep -E '^MARIADB_PASSWORD=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	rabbit_user="$$(grep -E '^RABBITMQ_DEFAULT_USER=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	rabbit_pass="$$(grep -E '^RABBITMQ_DEFAULT_PASS=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	set_env_var DATABASE_URL "mysql://$${db_user}:$${db_pass}@mariadb:3306/$${db_name}?serverVersion=11.4.2-MariaDB&charset=utf8mb4"; \
	set_env_var MESSENGER_PROVISIONING_TRANSPORT_DSN "amqp://$${rabbit_user}:$${rabbit_pass}@rabbitmq:5672/%2f/messages"; \
	set_env_var MESSENGER_PROJECTION_TRANSPORT_DSN "amqp://$${rabbit_user}:$${rabbit_pass}@rabbitmq:5672/%2f/messages"; \
	admin_pass="$$(grep -E '^ADMIN_BASIC_AUTH_PASSWORD=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	if command -v htpasswd >/dev/null 2>&1; then \
		htpasswd -nbB "$$admin_user" "$$admin_pass" > docker/nginx/.htpasswd; \
	elif command -v openssl >/dev/null 2>&1; then \
		admin_hash="$$(openssl passwd -apr1 "$$admin_pass")"; \
		printf '%s:%s\n' "$$admin_user" "$$admin_hash" > docker/nginx/.htpasswd; \
	else \
		echo "Missing htpasswd or openssl command to generate docker/nginx/.htpasswd"; \
		exit 1; \
	fi; \
	chmod 644 docker/nginx/.htpasswd; \
	aws_profile="$${AWS_PROFILE_NAME:-infratak-prod}"; \
	aws_region="$$(grep -E '^AWS_REGION=' "$(ENV_FILE)" | cut -d= -f2- || true)"; \
	aws_access_key=""; \
	aws_secret_key=""; \
	aws_session_token=""; \
	if [ -f .env.infra ]; then \
		aws_access_key="$$(grep -E '^AWS_ACCESS_KEY_ID=' .env.infra | cut -d= -f2- || true)"; \
		aws_secret_key="$$(grep -E '^AWS_SECRET_ACCESS_KEY=' .env.infra | cut -d= -f2- || true)"; \
		aws_session_token="$$(grep -E '^AWS_SESSION_TOKEN=' .env.infra | cut -d= -f2- || true)"; \
	fi; \
	if [ -z "$$aws_access_key" ]; then aws_access_key="$$(grep -E '^AWS_ACCESS_KEY_ID=' "$(ENV_FILE)" | cut -d= -f2- || true)"; fi; \
	if [ -z "$$aws_secret_key" ]; then aws_secret_key="$$(grep -E '^AWS_SECRET_ACCESS_KEY=' "$(ENV_FILE)" | cut -d= -f2- || true)"; fi; \
	if [ -z "$$aws_session_token" ]; then aws_session_token="$$(grep -E '^AWS_SESSION_TOKEN=' "$(ENV_FILE)" | cut -d= -f2- || true)"; fi; \
	if [ -n "$$aws_access_key" ] && [ -n "$$aws_secret_key" ]; then \
		mkdir -p var/share/.aws; \
		chmod 700 var/share/.aws; \
		{ \
			echo "[$$aws_profile]"; \
			echo "aws_access_key_id=$$aws_access_key"; \
			echo "aws_secret_access_key=$$aws_secret_key"; \
			if [ -n "$$aws_session_token" ]; then echo "aws_session_token=$$aws_session_token"; fi; \
		} > var/share/.aws/credentials; \
		{ \
			echo "[profile $$aws_profile]"; \
			echo "region=$$aws_region"; \
			echo "output=json"; \
		} > var/share/.aws/config; \
		chmod 600 var/share/.aws/credentials var/share/.aws/config; \
		set_env_var AWS_PROFILE "$$aws_profile"; \
		echo "Prepared AWS profile '$$aws_profile' for SDK/CLI in var/share/.aws"; \
	elif [ -f "$$HOME/.aws/credentials" ]; then \
		mkdir -p var/share/.aws; \
		chmod 700 var/share/.aws; \
		cp "$$HOME/.aws/credentials" var/share/.aws/credentials; \
		if [ -f "$$HOME/.aws/config" ]; then cp "$$HOME/.aws/config" var/share/.aws/config; fi; \
		if ! grep -qE "^\[$$aws_profile\]" var/share/.aws/credentials; then aws_profile=default; fi; \
		set_env_var AWS_PROFILE "$$aws_profile"; \
		chmod 600 var/share/.aws/credentials var/share/.aws/config 2>/dev/null || true; \
		echo "Prepared AWS profile '$$aws_profile' from local ~/.aws for SDK/CLI"; \
	else \
		echo "Warning: AWS profile not prepared (missing AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY)."; \
	fi; \
	hosted_zone_id="$$(grep -E '^ROUTE53_ZONE_ID=' .env.infra 2>/dev/null | cut -d= -f2- || true)"; \
	if [ -z "$$hosted_zone_id" ]; then hosted_zone_id="$$(grep -E '^AWS_ROUTE53_HOSTED_ZONE_ID=' .env.infra 2>/dev/null | cut -d= -f2- || true)"; fi; \
	if [ -n "$$hosted_zone_id" ]; then \
		set_env_var AWS_ROUTE53_HOSTED_ZONE_ID "$$hosted_zone_id"; \
		echo "Set AWS_ROUTE53_HOSTED_ZONE_ID from .env.infra"; \
	fi; \
	echo "Prepared docker/nginx/.htpasswd for user $$admin_user"; \
	echo "Updated runtime Symfony env vars in $(ENV_FILE)"

deploy-rotate-secrets:
	@echo "Rotating stateless secrets only (APP_SECRET, ADMIN_BASIC_AUTH_PASSWORD)."
	@$(MAKE) deploy-prepare-env FORCE_ROTATE_APP=1

remote-tls-cert:
	$(SSH) "cd $(APP_DIR) && if sudo test -s var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem && sudo openssl x509 -in var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem -noout -issuer 2>/dev/null | grep -qi \"Let's Encrypt\" && sudo openssl x509 -checkend 2592000 -in var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem >/dev/null 2>&1; then echo 'Cert valid (LE, >30 days), skipping certbot'; else $(REMOTE_COMPOSE) stop landing_nginx && CERTBOT_FLAGS='--keep-until-expiring'; if ! sudo test -s var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem || ! sudo test -s var/letsencrypt/live/$(TLS_DOMAIN)/privkey.pem; then CERTBOT_FLAGS='--force-renewal'; elif ! sudo openssl x509 -in var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem -noout -issuer 2>/dev/null | grep -qi \"Let's Encrypt\"; then CERTBOT_FLAGS='--force-renewal'; fi; if docker run --rm --network host -v \"$$PWD/var/letsencrypt:/etc/letsencrypt\" -v \"$$PWD/var/certbot:/var/lib/letsencrypt\" certbot/certbot certonly --standalone --preferred-challenges http -d $(TLS_DOMAIN) --email $(LETSENCRYPT_EMAIL) --agree-tos --non-interactive $$CERTBOT_FLAGS; then echo 'Certbot OK'; else echo 'Certbot failed; restoring bootstrap cert'; sudo mkdir -p var/letsencrypt/live/$(TLS_DOMAIN); if ! sudo test -s var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem || ! sudo test -s var/letsencrypt/live/$(TLS_DOMAIN)/privkey.pem; then if sudo test -L var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem || sudo test -L var/letsencrypt/live/$(TLS_DOMAIN)/privkey.pem; then echo 'Refusing to overwrite Certbot symlink targets with bootstrap cert'; exit 1; fi; sudo openssl req -x509 -nodes -newkey rsa:2048 -keyout var/letsencrypt/live/$(TLS_DOMAIN)/privkey.pem -out var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem -days 1 -subj '/CN=$(TLS_DOMAIN)' >/dev/null 2>&1; fi; fi && $(REMOTE_COMPOSE) start landing_nginx; fi"

tls-renew: deploy-check
	@$(MAKE) remote-tls-cert
	@$(MAKE) tls-status

tls-status:
	$(SSH) "cd $(APP_DIR) && if [ -s var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem ]; then openssl x509 -in var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem -noout -issuer -subject -dates; else echo 'Missing certificate file: var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem'; exit 1; fi"

deploy-prod: deploy-prepare-env deploy-check
	$(RSYNC) ./ $(SERVER_USER)@$(SERVER_IP):$(APP_DIR)
	$(SCP) $(ENV_FILE) $(SERVER_USER)@$(SERVER_IP):$(APP_DIR)/$(ENV_FILE)
	$(SSH) "cd $(APP_DIR) && sudo mkdir -p var/letsencrypt/live/$(TLS_DOMAIN) var/certbot && if ! sudo test -s var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem || ! sudo test -s var/letsencrypt/live/$(TLS_DOMAIN)/privkey.pem; then if sudo test -L var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem || sudo test -L var/letsencrypt/live/$(TLS_DOMAIN)/privkey.pem; then echo 'Refusing to overwrite Certbot symlink targets with bootstrap cert'; exit 1; fi; sudo openssl req -x509 -nodes -newkey rsa:2048 -keyout var/letsencrypt/live/$(TLS_DOMAIN)/privkey.pem -out var/letsencrypt/live/$(TLS_DOMAIN)/fullchain.pem -days 1 -subj '/CN=$(TLS_DOMAIN)' >/dev/null 2>&1; fi"
	$(SSH) "cd $(APP_DIR) && $(REMOTE_COMPOSE) up -d --build --remove-orphans"
	@$(MAKE) remote-tls-cert
	$(SSH) "cd $(APP_DIR) && $(REMOTE_COMPOSE) exec -T landing_php php bin/console doctrine:migrations:migrate --no-interaction"
	$(SSH) "cd $(APP_DIR) && $(REMOTE_COMPOSE) exec -T landing_php php bin/console cache:clear --env=prod"

setup: infra-up infra-wait-ready deploy-prod
	@echo "One-command setup finished successfully."

deploy-one: setup

playwright-install:
	npm install
	npx playwright install chromium

playwright-test:
	docker compose up -d nginx php mariadb rabbitmq
	npm run pw:test

playwright-test-ui:
	docker compose up -d nginx php mariadb rabbitmq
	npm run pw:test:ui

playwright-test-headed:
	docker compose up -d nginx php mariadb rabbitmq
	npm run pw:test:headed
