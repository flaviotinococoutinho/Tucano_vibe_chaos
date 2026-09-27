#!/usr/bin/env bash
# Creates the <service>_test databases used by `make check`, so running the
# tests never wipes the data of the running stack. Safe to run again.
set -euo pipefail

compose=(docker compose)

for service in commerce logistics; do
  exists=$("${compose[@]}" exec -T postgres psql -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '${service}_test'")
  if [[ "$exists" != "1" ]]; then
    "${compose[@]}" exec -T postgres psql -U postgres -qc "CREATE DATABASE ${service}_test OWNER ${service}"
    "${compose[@]}" exec -T postgres psql -U postgres -d "${service}_test" -qc "ALTER SCHEMA public OWNER TO ${service}"
  fi
done

# MYSQL_PWD instead of -p keeps the password off the process list.
"${compose[@]}" exec -T -e MYSQL_PWD=root mysql mysql -uroot \
  -e "CREATE DATABASE IF NOT EXISTS catalog_test; GRANT ALL PRIVILEGES ON catalog_test.* TO 'catalog'@'%';"
