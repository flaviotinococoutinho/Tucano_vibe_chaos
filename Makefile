SHELL := /usr/bin/env bash
.DEFAULT_GOAL := help

COMPOSE := docker compose
db ?= commerce
PHP ?= 8.4

.PHONY: help doctor setup setup-check trim base up down ps logs restart tools clean topics consume psql mysql mongo redis-cli aws flags flag flag-reset proxies stalled php packages-check kong-reload check config-check lint-workflows

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z0-9_-]+:.*## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

doctor: ## Check that Docker has enough resources for the stack (and, on a Mac, that the Mac disk has room)
	@scripts/doctor.sh

setup: ## Prepare this machine with Ansible (tools, Docker VM, .env) and bring the stack up
	@command -v ansible-playbook >/dev/null || { echo "Ansible first: brew install ansible (macOS) or pipx install --include-deps ansible"; exit 1; }
	cd infra/ansible && ansible-playbook playbooks/setup.yml

setup-check: ## Check the machine and the running stack with Ansible, changing nothing
	@command -v ansible-playbook >/dev/null || { echo "Ansible first: brew install ansible (macOS) or pipx install --include-deps ansible"; exit 1; }
	cd infra/ansible && ansible-playbook playbooks/check.yml

trim: ## Give the space freed inside the Colima VM back to the Mac (after docker image prune)
	@command -v colima >/dev/null || { echo "trim is for Colima: elsewhere Docker writes straight to the host disk"; exit 0; }
	colima ssh -- sudo fstrim -av

base: ## Build the PHP base images (8.3 and 8.4); cached after the first run
	@for version in 8.3 8.4; do \
		docker build -q -t chaos-playground/php-base:$$version --build-arg PHP_VERSION=$$version infra/php-base >/dev/null \
		&& echo "php-base:$$version ready"; \
	done

up: base ## Start the stack and wait until it is healthy (APP_ENV=local|staging|production)
	@# Build first, then up without --build: up --build labels each container with the digest the
	@# build returns, which changes on every build even from cache, and recreates every service.
	$(COMPOSE) build
	$(COMPOSE) up -d --wait

down: ## Stop the stack, keeping the data
	$(COMPOSE) --profile tools down

ps: ## Show containers and their health
	$(COMPOSE) ps -a

logs: ## Follow logs (s=<service>)
	$(COMPOSE) logs -f --tail=100 $(s)

restart: ## Restart one service (s=<service>)
	$(COMPOSE) restart $(s)

tools: ## Start the stack plus Kafka UI, Adminer and DynamoDB Admin
	$(COMPOSE) --profile tools up -d --wait

clean: ## Stop the stack and delete every volume (asks first)
	@read -r -p "This deletes all local data of the stack. Type 'yes' to continue: " answer; [ "$$answer" = yes ]
	$(COMPOSE) --profile tools down -v --remove-orphans

topics: ## List Kafka topics
	$(COMPOSE) exec kafka /opt/kafka/bin/kafka-topics.sh --bootstrap-server kafka:9092 --list

consume: ## Read a topic from the beginning (t=<topic>)
	$(COMPOSE) exec kafka /opt/kafka/bin/kafka-console-consumer.sh --bootstrap-server kafka:9092 \
		--topic $(t) --from-beginning --property print.key=true --property print.headers=true

psql: ## Open psql as a service role (db=commerce|logistics)
	$(COMPOSE) exec -e PGPASSWORD=$(db) postgres psql -U $(db) -d $(db)

mysql: ## Open the MySQL client on the catalog database
	$(COMPOSE) exec mysql mysql -ucatalog -pcatalog catalog

mongo: ## Open mongosh
	$(COMPOSE) exec mongo mongosh

redis-cli: ## Open redis-cli
	$(COMPOSE) exec redis redis-cli

aws: ## Run an AWS CLI command against Floci (c="s3 ls")
	$(COMPOSE) exec floci aws $(c)

flags: ## Evaluate every feature flag (key=<targeting key>)
	@curl -s -X POST localhost:8016/ofrep/v1/evaluate/flags -H 'Content-Type: application/json' \
		-d '{"context": {"targetingKey": "$(or $(key),anonymous)"}}' | jq '.flags | map({(.key): .value}) | add'

# flagd watches a runtime copy of the environment file (flag-runtime volume); these edit that copy.
flag-runtime := docker run --rm -e APP_ENV=$(or $(APP_ENV),local) -v chaos-playground_flag-runtime:/runtime \
	-v "$(CURDIR)/infra/flags/environments":/environments:ro -v "$(CURDIR)/scripts/flags":/scripts:ro \
	chaos-playground/php-base:$(PHP) php /scripts/runtime.php

flag: ## Serve another variant of a flag, no restart (key=<flag> variant=<variant>)
	@$(flag-runtime) set $(key) $(variant)

flag-reset: ## Put a flag back as it is in the repository (key=<flag>)
	@$(flag-runtime) reset $(key)

experiments: ## List the chaos experiments, each with the steady state it defends
	@for file in chaos/experiments/*.json; do \
		printf '%-28s %s\n' "$$(basename $$file .json)" "$$(jq -r '."steady-state-hypothesis".title' $$file)"; \
	done

experiment: ## Run a chaos experiment against the running stack (e=<name>); rollbacks always run
	@test -n "$(e)" || { echo "Which one? make experiments lists them."; exit 1; }
	@mkdir -p chaos/results
	$(COMPOSE) --profile tools run --rm chaos --log-file /results/$(e).log \
		run --rollback-strategy always --journal-path /results/$(e).json /chaos/experiments/$(e).json

kong-reload: ## Apply infra/kong/kong.yml to the running Kong without downtime
	@curl -s -o /dev/null -w "kong config reloaded (HTTP %{http_code})\n" -X POST localhost:8001/config -F config=@infra/kong/kong.yml

config-check: ## Check that compose, the code and docs/operations/configuration.md agree on every variable
	@python3 scripts/check-config.py

lint-workflows: ## Validate the GitHub Actions workflows with actionlint
	docker run --rm -v "$(CURDIR)":/repo -w /repo rhysd/actionlint:1.7.12 -color

proxies: ## List Toxiproxy proxies and their active toxics
	@curl -s localhost:8474/proxies | jq 'to_entries | map({name: .key, listen: .value.listen, upstream: .value.upstream, enabled: .value.enabled, toxics: [.value.toxics[].name]})'

stalled: ## Run one round of the stalled journey watch now and print what it found (UC-SHP-13)
	$(COMPOSE) exec logistics-stalled-journeys-watch php artisan logistics:watch-stalled-journeys --once

php: ## Run a command in the PHP base image (dir=<path> c="<command>" PHP=8.3|8.4 net=<docker network>)
	@docker image inspect chaos-playground/php-base:$(PHP) >/dev/null 2>&1 || $(MAKE) --no-print-directory base
	docker run --rm $(if $(net),--network $(net),) -v "$(CURDIR)":/app -v chaos-playground-composer-cache:/tmp/composer/cache \
		-w /app/$(dir) chaos-playground/php-base:$(PHP) sh -c '$(c)'

# The integration tests of the packages reach the running stack. The Kafka round trip is
# left to the CI: it needs a broker that creates topics on demand, and the stack's does not.
package-env := -e FLAGD_HOST=flagd -e 'MESSAGING_PG_DSN=pgsql:host=postgres;dbname=commerce_test' \
	-e MESSAGING_PG_USER=commerce -e MESSAGING_PG_PASSWORD=commerce \
	-e 'READ_MODELS_MONGO_URI=mongodb://mongo:27017/?directConnection=true'

packages-check: ## Lint, analyse and test the PHP packages on PHP 8.3 and 8.4, against the running stack
	@scripts/test-databases.sh
	@for package in shared-kernel feature-flags messaging read-models; do \
		for version in 8.3 8.4; do \
			echo "== $$package on PHP $$version"; \
			docker run --rm --network chaos-playground_backend -v "$(CURDIR)":/app \
				-v chaos-playground-composer-cache:/tmp/composer/cache $(package-env) \
				-w /app/packages/php/$$package chaos-playground/php-base:$$version \
				sh -c 'composer install -q && composer check' || exit 1; \
		done; \
	done

# Hostnames the PHP services use when their tests run inside the compose network.
check-env-catalog := -e DB_HOST=mysql -e DB_DATABASE=catalog_test -e REDIS_HOST=redis
check-php-catalog := 8.3
check-env-tracking := -e REDIS_HOST=redis
# Swoole exists only in the service image (make up builds it).
check-image-tracking := chaos-playground/tracking
check-env-commerce := -e DB_HOST=postgres -e DB_DATABASE=commerce_test -e REDIS_HOST=redis \
	-e MONGO_URI=mongodb://mongo:27017/?directConnection=true -e MONGO_DATABASE=commerce_read_test
check-env-logistics := -e DB_HOST=postgres -e DB_DATABASE=logistics_test -e REDIS_HOST=redis \
	-e MONGO_URI=mongodb://mongo:27017/?directConnection=true -e MONGO_DATABASE=logistics_read_test \
	-e FLOCI_ENDPOINT=http://floci:4566

check: ## Lint, analyse and test one service (s=commerce); PHP runs against the stack
ifneq ($(wildcard services/$(s)/package.json),)
	docker run --rm -v "$(CURDIR)":/app -v chaos-playground-npm-cache:/root/.npm \
		-w /app/services/$(s) node:24-alpine sh -c 'npm ci --no-audit --no-fund && npm run check'
else
	@scripts/test-databases.sh
	docker run --rm --user root --network chaos-playground_backend -v "$(CURDIR)":/app \
		-v chaos-playground-composer-cache:/tmp/composer/cache $(check-env-$(s)) -w /app/services/$(s) \
		$(or $(check-image-$(s)),chaos-playground/php-base:$(or $(check-php-$(s)),$(PHP))) sh -c 'composer install -q && composer check'
endif
