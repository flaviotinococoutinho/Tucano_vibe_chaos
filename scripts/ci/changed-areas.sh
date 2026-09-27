#!/usr/bin/env bash
# Prints which areas of the monorepo the current change touches, as a JSON
# array, so each CI job can skip itself when its area is untouched.
set -euo pipefail

ALL_AREAS="docs compose contracts shared-kernel feature-flags messaging catalog commerce logistics tracking bff partners-sim web"
PHP_AREAS="shared-kernel feature-flags messaging catalog commerce logistics tracking"

changed_files() {
  if [[ "${EVENT:-}" == "pull_request" ]]; then
    git diff --name-only "origin/${BASE_REF}...HEAD"
  elif [[ -n "${BEFORE:-}" && ! "${BEFORE}" =~ ^0+$ ]]; then
    git diff --name-only "${BEFORE}..HEAD"
  else
    git ls-files
  fi
}

area_of() {
  case "$1" in
    .github/*|scripts/ci/*)         echo "$ALL_AREAS" ;;
    packages/php/*)                 echo "$PHP_AREAS" ;;
    contracts/*)                    echo "contracts catalog commerce logistics bff" ;;
    services/*)                     echo "$1" | cut -d/ -f2 ;;
    infra/flags/*)                  echo "compose feature-flags" ;;
    compose*.yaml|infra/*|Makefile) echo "compose" ;;
    docs/*|*.md)                    echo "docs" ;;
  esac
}

changed_files \
  | while IFS= read -r file; do area_of "$file"; done \
  | tr ' ' '\n' \
  | jq -R 'select(length > 0)' \
  | jq -sc 'unique' \
  | sed 's/^/areas=/'
