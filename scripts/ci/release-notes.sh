#!/usr/bin/env bash
# Prints the CHANGELOG section of one version (without its heading).
# It becomes the body of the GitHub Release.
set -euo pipefail

version="${1:?usage: release-notes.sh <version>}"

notes=$(awk -v version="$version" '
  index($0, "## [" version "]") == 1 { printing = 1; next }
  printing && /^## \[/ { exit }
  printing { print }
' CHANGELOG.md)

if [[ -z "${notes//[[:space:]]/}" ]]; then
  echo "no CHANGELOG section for $version" >&2
  exit 1
fi

printf '%s\n' "$notes"
