#!/usr/bin/env bash
# Creates a throwaway WordPress + WooCommerce site for the integration tests:
# SQLite database (no MySQL/Docker needed), plugin symlinked from this repository.
#
# Usage: bin/install-wp-tests.sh [wp-version] [wc-version]
# Env:   WP_CLI   command to run WP-CLI (default: wp)
#        TEST_DIR target directory (default: tests/.wp)
#        DB=mysql with DB_HOST, DB_NAME, DB_USER, DB_PASS: a real MySQL/MariaDB database
#                 instead of SQLite (the database is emptied first; never a shop's database)
set -euo pipefail

WP_VERSION="${1:-latest}"
WC_VERSION="${2:-latest-stable}"
WP_CLI="${WP_CLI:-wp}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEST_DIR="${TEST_DIR:-$ROOT/tests/.wp}"
SITE="$TEST_DIR/wordpress"
DL="$TEST_DIR/downloads"

mkdir -p "$DL"
rm -rf "$SITE"

fetch() { # url file
	[ -f "$DL/$2" ] || curl -fsSL "$1" -o "$DL/$2"
}

if [ "$WP_VERSION" = "latest" ]; then
	fetch "https://wordpress.org/latest.zip" "wordpress-latest.zip"
	unzip -q "$DL/wordpress-latest.zip" -d "$TEST_DIR"
else
	fetch "https://wordpress.org/wordpress-$WP_VERSION.zip" "wordpress-$WP_VERSION.zip"
	unzip -q "$DL/wordpress-$WP_VERSION.zip" -d "$TEST_DIR"
fi

fetch "https://downloads.wordpress.org/plugin/woocommerce.$WC_VERSION.zip" "woocommerce-$WC_VERSION.zip"
unzip -q "$DL/woocommerce-$WC_VERSION.zip" -d "$SITE/wp-content/plugins"
DB="${DB:-sqlite}"
if [ "$DB" = sqlite ]; then
	fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip" "sqlite.zip"
	unzip -q "$DL/sqlite.zip" -d "$SITE/wp-content/plugins"
	SQLITE="$SITE/wp-content/plugins/sqlite-database-integration"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
		"$SQLITE/db.copy" > "$SITE/wp-content/db.php"
	DBARGS=(--dbname=wp --dbuser=wp --dbpass=wp)
else
	DBARGS=(--dbname="${DB_NAME:-price_history_tests}" --dbuser="${DB_USER:-root}" --dbpass="${DB_PASS:-}" --dbhost="${DB_HOST:-127.0.0.1}")
fi
ln -s "$ROOT" "$SITE/wp-content/plugins/pnscripts-price-history"

$WP_CLI --path="$SITE" config create "${DBARGS[@]}" --skip-check --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP
[ "$DB" = sqlite ] || $WP_CLI --path="$SITE" db reset --yes
$WP_CLI --path="$SITE" core install --url=http://localhost --title="Price History tests" \
	--admin_user=admin --admin_password="$(head -c 18 /dev/urandom | base64)" --admin_email=admin@example.test --skip-email
$WP_CLI --path="$SITE" option update timezone_string Europe/Sofia
$WP_CLI --path="$SITE" plugin activate woocommerce pnscripts-price-history
$WP_CLI --path="$SITE" option update woocommerce_currency EUR

echo "Test site ready: $SITE"
