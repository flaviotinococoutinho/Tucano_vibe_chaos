SHELL := /usr/bin/env bash
.DEFAULT_GOAL := help

COMPOSE := docker compose
db ?= commerce
PHP ?= 8.4

.PHONY: help doctor base up down ps logs restart tools clean topics consume psql mysql mongo redis-cli aws flags proxies php packages-check kong-reload check lint-workflows

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z0-9_-]+:.*## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

doctor: ## Check that Docker has enough resources for the stack
	@scripts/doctor.sh

base: ## Build the PHP base images (8.3 and 8.4); cached after the first run
	@for version in 8.3 8.4; do \
		docker build -q -t chaos-playground/php-base:$$version --build-arg PHP_VERSION=$$version infra/php-base >/dev/null \
		&& echo "php-base:$$version ready"; \
	done

up: base ## Start the stack and wait until it is healthy (APP_ENV=local|staging|production)
	$(COMPOSE) up -d --build --wait

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

kong-reload: ## Apply infra/kong/kong.yml to the running Kong without downtime
	@curl -s -o /dev/null -w "kong config reloaded (HTTP %{http_code})\n" -X POST localhost:8001/config -F config=@infra/kong/kong.yml

lint-workflows: ## Validate the GitHub Actions workflows with actionlint
	docker run --rm -v "$(CURDIR)":/repo -w /repo rhysd/actionlint:1.7.12 -color

proxies: ## List Toxiproxy proxies and their active toxics
	@curl -s localhost:8474/proxies | jq 'to_entries | map({name: .key, listen: .value.listen, upstream: .value.upstream, enabled: .value.enabled, toxics: [.value.toxics[].name]})'

php: ## Run a command in the PHP base image (dir=<path> c="<command>" PHP=8.3|8.4 net=<docker network>)
	@docker image inspect chaos-playground/php-base:$(PHP) >/dev/null 2>&1 || $(MAKE) --no-print-directory base
	docker run --rm $(if $(net),--network $(net),) -v "$(CURDIR)":/app -v chaos-playground-composer-cache:/tmp/composer/cache \
		-w /app/$(dir) chaos-playground/php-base:$(PHP) sh -c '$(c)'

packages-check: ## Lint, analyse and test the PHP packages on PHP 8.3 and 8.4
	@for package in shared-kernel feature-flags messaging read-models; do \
		for version in 8.3 8.4; do \
			echo "== $$package on PHP $$version"; \
			$(MAKE) --no-print-directory php PHP=$$version dir=packages/php/$$package c="composer install -q && composer check" || exit 1; \
		done; \
	done

# Hostnames the PHP services use when their tests run inside the compose network.
check-env-catalog := -e DB_HOST=mysql -e REDIS_HOST=redis
check-php-catalog := 8.3
check-env-commerce := -e DB_HOST=postgres -e DB_DATABASE=commerce_test -e REDIS_HOST=redis \
	-e MONGO_URI=mongodb://mongo:27017/?directConnection=true -e MONGO_DATABASE=commerce_read_test
check-env-logistics := -e DB_HOST=postgres -e DB_DATABASE=logistics_test -e REDIS_HOST=redis \
	-e MONGO_URI=mongodb://mongo:27017/?directConnection=true -e MONGO_DATABASE=logistics_read_test

check: ## Lint, analyse and test one PHP service against the running stack (s=commerce)
	@scripts/test-databases.sh
	docker run --rm --network chaos-playground_backend -v "$(CURDIR)":/app \
		-v chaos-playground-composer-cache:/tmp/composer/cache $(check-env-$(s)) \
		-w /app/services/$(s) chaos-playground/php-base:$(or $(check-php-$(s)),$(PHP)) sh -c 'composer install -q && composer check'
