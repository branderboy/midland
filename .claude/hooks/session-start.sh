#!/bin/bash
# Provision a local WordPress + SQLite harness so PHP linting and the plugin
# runtime smoke test (tools/smoke-test.sh) work in Claude Code on the web.
#
# wordpress.org is blocked in the web sandbox, so WordPress core and the SQLite
# drop-in are fetched from their GitHub mirrors. Idempotent: re-runs are cheap.
set -euo pipefail

# Only provision in the remote (web) environment.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

WP_DIR="$HOME/.wp-smoke"
WP_CORE="$WP_DIR/wp-core"
WP_CLI="$WP_DIR/wp"
SQLITE_DIR="$WP_CORE/wp-content/plugins/sqlite-database-integration"
SQLITE_TAG="v2.1.13"

mkdir -p "$WP_DIR"

# wp-cli (from the GitHub builds mirror)
if [ ! -f "$WP_CLI" ]; then
    curl -fsSL -o "$WP_CLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod +x "$WP_CLI"
fi

# WordPress core (GitHub mirror; wordpress.org is blocked here)
if [ ! -f "$WP_CORE/wp-load.php" ]; then
    git clone --depth 1 https://github.com/WordPress/WordPress.git "$WP_CORE"
fi

# SQLite database drop-in
if [ ! -d "$SQLITE_DIR" ]; then
    git clone --depth 1 --branch "$SQLITE_TAG" \
        https://github.com/WordPress/sqlite-database-integration.git "$SQLITE_DIR"
fi
if [ ! -f "$WP_CORE/wp-content/db.php" ]; then
    cp "$SQLITE_DIR/db.copy" "$WP_CORE/wp-content/db.php"
    sed -i \
        -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE_DIR#g" \
        -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
        "$WP_CORE/wp-content/db.php"
fi

# Config + install (idempotent)
if [ ! -f "$WP_CORE/wp-config.php" ]; then
    php "$WP_CLI" --allow-root config create \
        --dbname=wp --dbuser=root --dbpass= --dbhost=localhost \
        --path="$WP_CORE" --skip-check
fi
if ! php "$WP_CLI" --allow-root core is-installed --path="$WP_CORE" 2>/dev/null; then
    php "$WP_CLI" --allow-root core install \
        --url=http://localhost --title="RSSEO Smoke" \
        --admin_user=admin --admin_password=admin --admin_email=admin@example.com \
        --skip-email --path="$WP_CORE"
fi

# Expose the harness paths to the session so tools/smoke-test.sh can find it.
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    {
        echo "export RSSEO_WP_CORE=\"$WP_CORE\""
        echo "export RSSEO_WP_CLI=\"$WP_CLI\""
    } >> "$CLAUDE_ENV_FILE"
fi

echo "WordPress smoke-test harness ready at $WP_CORE"
