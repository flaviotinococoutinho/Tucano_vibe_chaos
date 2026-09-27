#!/usr/bin/env bash
# Sanity checks before running the stack: the full compose needs a Docker VM
# with at least ~4 GB of memory and a few CPUs to stay responsive.
set -euo pipefail

ok()   { printf '  [ok]   %s\n' "$1"; }
warn() { printf '  [warn] %s\n' "$1"; }
fail() { printf '  [fail] %s\n' "$1"; exit 1; }

command -v docker >/dev/null || fail "docker not found"
docker info >/dev/null 2>&1 || fail "docker daemon is not running"
ok "docker $(docker version --format '{{.Server.Version}}')"

compose_version=$(docker compose version --short 2>/dev/null) || fail "docker compose v2 not found"
ok "compose $compose_version"

memory_mb=$(( $(docker info --format '{{.MemTotal}}') / 1024 / 1024 ))
memory_gb=$(LC_NUMERIC=C awk "BEGIN {printf \"%.1f\", $memory_mb / 1024}")
cpus=$(docker info --format '{{.NCPU}}')

# A 4 GB VM reports a bit less than 4096 MB once the kernel takes its share.
if (( memory_mb >= 3584 )); then ok "memory ${memory_gb} GB"; else warn "memory ${memory_gb} GB (4 GB recommended, e.g. colima start --memory 4)"; fi
if (( cpus >= 4 )); then ok "cpus ${cpus}"; else warn "cpus ${cpus} (4 recommended, e.g. colima start --cpu 4)"; fi

free_gb=$(docker run --rm alpine:3 df -Pk / | awk 'NR==2 {print int($4 / 1024 / 1024)}')
if (( free_gb >= 12 )); then ok "vm disk free ${free_gb} GB"; else warn "vm disk free ${free_gb} GB (images need ~10 GB)"; fi
