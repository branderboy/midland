#!/bin/bash
# Run the runtime smoke test for the real-smart-seo plugin against the local
# WordPress + SQLite harness that the SessionStart hook provisions.
#
# Usage:  tools/smoke-test.sh   (env is set up by .claude/hooks/session-start.sh)
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_CORE="${RSSEO_WP_CORE:-$HOME/.wp-smoke/wp-core}"
WP_CLI="${RSSEO_WP_CLI:-$HOME/.wp-smoke/wp}"

if [ ! -f "$WP_CORE/wp-load.php" ] || [ ! -f "$WP_CLI" ]; then
    echo "WordPress test harness not found at $WP_CORE."
    echo "Run .claude/hooks/session-start.sh first (or it runs automatically on web sessions)."
    exit 1
fi

# Make the working-tree plugin the one under test.
ln -sfn "$REPO/plugins/real-smart-seo" "$WP_CORE/wp-content/plugins/real-smart-seo"
php "$WP_CLI" --allow-root plugin activate real-smart-seo --path="$WP_CORE" >/dev/null 2>&1 || true

OUT="$(php "$WP_CLI" --allow-root eval-file "$REPO/tools/smoke-test.php" --path="$WP_CORE" 2>&1)"
echo "$OUT"

if echo "$OUT" | grep -q "SMOKE PASS"; then
    exit 0
fi
exit 1
