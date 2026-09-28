#!/usr/bin/env python3
"""Fitness function for the configuration of the stack (Twelve-Factor, factor III).

It fails when:
  1. compose sets a variable on an app that the app never reads (a typo or a leftover);
  2. an app reads a variable that docs/operations/configuration.md does not explain;
  3. the reference lists a variable that no app reads any more;
  4. code reads the environment outside the one place each app has for it;
  5. a duration or a size does not say its unit in the name.

compose is read through `docker compose config`, so anchors and merges come resolved.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
REFERENCE = ROOT / "docs" / "operations" / "configuration.md"

PHP_ENV = re.compile(r"\benv\('([A-Z][A-Z0-9_]*)'")
TRACKING_ENV = re.compile(r"\$env(?:, |\[)'([A-Z][A-Z0-9_]*)'")
NODE_ENV = re.compile(r"'([A-Z][A-Z0-9_]*)'")

# Where each app reads its environment, and what a read looks like there.
READERS: dict[str, tuple[list[str], re.Pattern[str]]] = {
    "catalog": (["config/*.php"], PHP_ENV),
    "commerce": (["config/*.php"], PHP_ENV),
    "logistics": (["config/*.php"], PHP_ENV),
    "tracking": (["src/Platform/Config.php"], TRACKING_ENV),
    "partners-sim": (["src/config.ts"], NODE_ENV),
    "bff": (["src/config.ts"], NODE_ENV),
}

# Reading the environment anywhere else is a mistake: Laravel caches its config, and after
# `config:cache` an env() outside config/ returns null. The entry points only hand it over.
STRAY_READ = re.compile(r"\benv\(|\bgetenv\(|\$_ENV\b|\$_SERVER\[|process\.env")
ALLOWED_READS = {
    "services/tracking/bin/server.php",  # Config::fromEnvironment(getenv())
    "services/partners-sim/src/server.ts",  # loadConfig(process.env)
    "services/bff/src/server.ts",
}
SOURCE_DIRS = ["app", "src", "bin", "routes", "bootstrap", "database"]

DURATION = re.compile(
    r"(?:^|_)(?:TIMEOUT|DELAY|DELAYS|TTL|PAUSE|INTERVAL|WINDOW|AFTER|EVERY|RETENTION|WAIT|LINGER|QUIET|OPEN|TRIAL|BACKOFF|HOLD|RESERVATION)(?:_|$)"
)
UNIT = re.compile(r"_(?:MS|SECONDS|MINUTES|HOURS|DAYS)$")
COUNT = re.compile(r"_(?:ATTEMPTS|TRIES|RETRIES|STEPS|ENTRIES|LIMIT|SIZE|THRESHOLD|PERCENT)$")

TEST_ONLY_HEADING = "## Só nos testes"


def compose_services() -> dict[str, dict]:
    result = subprocess.run(
        ["docker", "compose", "--profile", "tools", "config", "--format", "json"],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=True,
    )
    return json.loads(result.stdout)["services"]


def reads_of(app: str) -> set[str]:
    patterns, reader = READERS[app]
    names: set[str] = set()
    for pattern in patterns:
        for file in sorted((ROOT / "services" / app).glob(pattern)):
            names.update(reader.findall(file.read_text()))
    return names


def documented() -> tuple[set[str], set[str]]:
    """The variables in the first column of the reference tables, and the test-only ones."""
    listed: set[str] = set()
    test_only: set[str] = set()
    in_tests = False
    for line in REFERENCE.read_text().splitlines():
        if line.startswith("## "):
            in_tests = line.strip() == TEST_ONLY_HEADING
        if not line.startswith("| `"):
            continue
        first_cell = line.split("|")[1]
        names = set(re.findall(r"`([A-Z][A-Z0-9_]*)`", first_cell))
        (test_only if in_tests else listed).update(names)
    return listed, test_only


def stray_reads() -> list[str]:
    found = []
    for app in READERS:
        for directory in SOURCE_DIRS:
            base = ROOT / "services" / app / directory
            if not base.is_dir():
                continue
            for file in sorted(base.rglob("*")):
                if file.suffix not in {".php", ".ts"} or "node_modules" in file.parts:
                    continue
                relative = file.relative_to(ROOT).as_posix()
                if relative in ALLOWED_READS or relative.endswith("src/Platform/Config.php"):
                    continue
                for number, line in enumerate(file.read_text().splitlines(), start=1):
                    if STRAY_READ.search(line) and not line.lstrip().startswith(("*", "//", "/**")):
                        found.append(f"{relative}:{number}: {line.strip()}")
    return found


def main() -> int:
    problems: list[str] = []
    reads = {app: reads_of(app) for app in READERS}
    everything_read = set().union(*reads.values())
    listed, test_only = documented()

    unread: dict[tuple[str, str], list[str]] = {}
    for name, service in sorted(compose_services().items()):
        app = service.get("image", "").removeprefix("chaos-playground/")
        if app not in READERS:
            continue
        for variable in (service.get("environment") or {}).keys():
            if variable not in reads[app]:
                unread.setdefault((variable, app), []).append(name)
    for (variable, app), names in sorted(unread.items()):
        problems.append(f"compose sets {variable} on {', '.join(names)}, and {app} never reads it")

    for variable in sorted(everything_read - listed):
        apps = ", ".join(app for app in READERS if variable in reads[app])
        problems.append(f"{variable} ({apps}) is missing from {REFERENCE.relative_to(ROOT)}")

    for variable in sorted(listed - everything_read):
        problems.append(f"{REFERENCE.relative_to(ROOT)} lists {variable}, which no app reads")

    for variable in sorted(everything_read | test_only):
        if DURATION.search(variable) and not UNIT.search(variable) and not COUNT.search(variable):
            problems.append(f"{variable} is a duration without its unit (_MS, _SECONDS, _MINUTES, _HOURS or _DAYS)")

    problems.extend(f"reads the environment outside its config: {read}" for read in stray_reads())

    if problems:
        print("The configuration does not add up:", file=sys.stderr)
        for problem in problems:
            print(f"  - {problem}", file=sys.stderr)
        return 1

    print(f"{len(everything_read)} variables, read in one place per app, documented and set only where read.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
