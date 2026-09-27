SHELL := /usr/bin/env bash
.DEFAULT_GOAL := help

.PHONY: help doctor

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z0-9_-]+:.*## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

doctor: ## Check that Docker has enough resources for the stack
	@scripts/doctor.sh
