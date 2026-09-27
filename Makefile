SHELL := /usr/bin/env bash
.DEFAULT_GOAL := help

COMPOSE := docker compose
db ?= commerce
PHP ?= 8.4

.PHONY: help doctor up down ps logs restart tools clean topics consume psql mysql mongo redis-cli aws flags proxies php packages-check

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z0-9_-]+:.*## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

doctor: ## Check that Docker has enough resources for the stack
	@scripts/doctor.sh

up: ## Start the stack and wait until it is healthy (APP_ENV=local|staging|production)
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

proxies: ## List Toxiproxy proxies and their active toxics
	@curl -s localhost:8474/proxies | jq 'to_entries | map({name: .key, listen: .value.listen, upstream: .value.upstream, enabled: .value.enabled, toxics: [.value.toxics[].name]})'

php: ## Run a command in the PHP tools image (dir=<path> c="<command>" PHP=8.3|8.4 net=<docker network>)
	@docker image inspect chaos-playground/php-tools:$(PHP) >/dev/null 2>&1 || \
		docker build -q -t chaos-playground/php-tools:$(PHP) --build-arg PHP_VERSION=$(PHP) infra/php-tools >/dev/null
	docker run --rm $(if $(net),--network $(net),) -v "$(CURDIR)":/app -v chaos-playground-composer-cache:/tmp/composer/cache \
		-w /app/$(dir) chaos-playground/php-tools:$(PHP) sh -c '$(c)'

packages-check: ## Lint, analyse and test the PHP packages on PHP 8.3 and 8.4
	@for package in shared-kernel feature-flags; do \
		for version in 8.3 8.4; do \
			echo "== $$package on PHP $$version"; \
			$(MAKE) --no-print-directory php PHP=$$version dir=packages/php/$$package c="composer install -q && composer check" || exit 1; \
		done; \
	done
