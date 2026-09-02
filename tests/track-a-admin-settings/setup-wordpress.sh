#!/usr/bin/env bash
# Bootstraps a headless WordPress install (SQLite-backed, no MySQL needed)
# with all 5 Credoq plugins active, ready for the Track A PHP CLI test
# scripts in this directory to run against.
#
# Usage: ./setup-wordpress.sh /path/to/wpsite /path/to/plugins-dir
set -euo pipefail

WPSITE="${1:?Usage: setup-wordpress.sh <wpsite-dir> <plugins-dir>}"
PLUGINS_SRC="${2:?Usage: setup-wordpress.sh <wpsite-dir> <plugins-dir>}"

echo "==> Cloning WordPress core (develop trunk, src/ only)"
rm -rf /tmp/wp-develop-src
git clone --depth 1 --branch trunk https://github.com/WordPress/wordpress-develop.git /tmp/wp-develop-src

echo "==> Cloning the official SQLite Database Integration drop-in"
rm -rf /tmp/wp-sqlite-src
git clone --depth 1 https://github.com/WordPress/sqlite-database-integration.git /tmp/wp-sqlite-src

echo "==> Assembling the site at $WPSITE"
rm -rf "$WPSITE"
mkdir -p "$WPSITE"
cp -r /tmp/wp-develop-src/src/* "$WPSITE/"

mkdir -p "$WPSITE/wp-content/plugins/sqlite-database-integration"
cp -r /tmp/wp-sqlite-src/packages/plugin-sqlite-database-integration/* "$WPSITE/wp-content/plugins/sqlite-database-integration/"
mkdir -p "$WPSITE/wp-content/plugins/mysql-on-sqlite"
cp -r /tmp/wp-sqlite-src/packages/mysql-on-sqlite/src "$WPSITE/wp-content/plugins/mysql-on-sqlite/src"

cat > "$WPSITE/wp-content/db.php" << 'PHPEOF'
<?php
require_once __DIR__ . '/plugins/sqlite-database-integration/wp-includes/sqlite/db.php';
PHPEOF

mkdir -p "$WPSITE/wp-content/database"

cat > "$WPSITE/wp-config.php" << 'PHPEOF'
<?php
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define('AUTH_KEY', 'ci-key-1'); define('SECURE_AUTH_KEY', 'ci-key-2');
define('LOGGED_IN_KEY', 'ci-key-3'); define('NONCE_KEY', 'ci-key-4');
define('AUTH_SALT', 'ci-salt-1'); define('SECURE_AUTH_SALT', 'ci-salt-2');
define('LOGGED_IN_SALT', 'ci-salt-3'); define('NONCE_SALT', 'ci-salt-4');
$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://localhost:8080' );
define( 'WP_SITEURL', 'http://localhost:8080' );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once ABSPATH . 'wp-settings.php';
PHPEOF

echo "==> Installing WordPress"
cat > "$WPSITE/cli-install.php" << 'PHPEOF'
<?php
define('WP_INSTALLING', true);
require_once __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	$result = wp_install( 'Credoq Test Site', 'admin', 'admin@example.test', true, '', 'admin_password_123' );
	if ( is_wp_error( $result ) ) { fwrite(STDERR, "INSTALL FAILED: " . $result->get_error_message() . "\n"); exit(1); }
	echo "WordPress installed OK.\n";
} else {
	echo "Already installed.\n";
}
PHPEOF
php "$WPSITE/cli-install.php"

echo "==> Copying the 5 Credoq plugins"
for p in credoq-engine-v3 credoq-appointments credoq-events-v3 credoq-seats credoq-membership-v3; do
    cp -r "$PLUGINS_SRC/$p" "$WPSITE/wp-content/plugins/$p"
    rm -rf "$WPSITE/wp-content/plugins/$p/react-widget/node_modules"
done

echo "==> Activating all plugins"
cat > "$WPSITE/cli-activate.php" << 'PHPEOF'
<?php
define('WP_ADMIN', true);
require_once __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugins = [
    'sqlite-database-integration/load.php',
    'credoq-engine-v3/credoq-engine.php',
    'credoq-appointments/credoq-appointments.php',
    'credoq-events-v3/credoq-events.php',
    'credoq-seats/credoq-seats.php',
    'credoq-membership-v3/credoq-membership.php',
];
$failed = false;
foreach ($plugins as $p) {
    if (is_plugin_active($p)) { echo "[already active] $p\n"; continue; }
    $result = activate_plugin($p);
    if (is_wp_error($result)) {
        echo "[FAILED] $p: " . $result->get_error_message() . "\n";
        $failed = true;
    } else {
        echo "[OK] $p activated\n";
    }
}
if ($failed) exit(1);
PHPEOF
php "$WPSITE/cli-activate.php"

echo "==> WordPress test environment ready at $WPSITE"
