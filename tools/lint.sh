#!/bin/bash
# Lint every PHP file in the plugins/ tree (php -l). Fast compile-error check.
# Usage:  tools/lint.sh [path]   (defaults to plugins/)
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-$REPO/plugins}"

fail=0
count=0
while IFS= read -r -d '' f; do
    count=$((count + 1))
    if ! php -l "$f" >/dev/null 2>&1; then
        echo "SYNTAX ERROR: $f"
        php -l "$f" 2>&1 | tail -2
        fail=1
    fi
done < <(find "$TARGET" -name '*.php' -print0)

echo "Linted $count PHP files."
exit $fail
