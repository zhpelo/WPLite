<?php
// WPLITE_SQLITE_DROPIN_V3 — database integration, not WordPress core code.
declare(strict_types=1);

if (!defined('ABSPATH')) {
    http_response_code(404);
    exit;
}
// The native configuration page must reach MU loading before SQLite is set up.
// WordPress supplies its unconnected wpdb placeholder during this phase.
if (defined('WP_SETUP_CONFIG') && WP_SETUP_CONFIG) { return; }
if (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite' || is_file(__DIR__ . '/mu-plugins/wplite/migration-state.php')) {
    require_once __DIR__ . '/mu-plugins/wplite/migration.php';
    \WPLite\Migration::guard();
}
// Installing WPLite on an existing MySQL site must not change its connection.
if (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite') { return; }
require_once __DIR__ . '/mu-plugins/wplite/bootstrap.php';
try {
    $wpliteDriverRoot = __DIR__ . '/mu-plugins/wplite/vendor/sqlite';
    if (extension_loaded('pdo_sqlite')) {
        require $wpliteDriverRoot . '/wp-includes/sqlite/db.php';
    } else {
        // Some static PHP builds provide PDO SQLite without this extension name.
        // Use the same upstream components, without its name-only entry check.
        require_once $wpliteDriverRoot . '/wp-includes/database/version.php';
        require_once $wpliteDriverRoot . '/constants.php';
        require_once $wpliteDriverRoot . '/wp-includes/sqlite/class-wp-sqlite-storage.php';
        $wpliteStorage = WP_SQLite_Storage::with_explicit_path(DB_PATH);
        $wpliteStorage->initialize();
        if (!defined('FQDB')) { define('FQDB', DB_PATH); }
        require_once $wpliteDriverRoot . '/wp-includes/database/load.php';
        require_once $wpliteDriverRoot . '/wp-includes/sqlite/class-wp-sqlite-db.php';
        require_once $wpliteDriverRoot . '/wp-includes/sqlite/install-functions.php';
        $GLOBALS['wpdb'] = new WP_SQLite_DB(DB_NAME);
        require_once $wpliteDriverRoot . '/integrations/query-monitor/boot.php';
        unset($wpliteStorage);
    }
    if (!empty($GLOBALS['wpdb']->last_error)) { throw new RuntimeException($GLOBALS['wpdb']->last_error); }
    $pdo = $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo();
    $pdo->exec('PRAGMA synchronous = FULL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if (SQLITE_JOURNAL_MODE === 'WAL') { $pdo->exec('PRAGMA wal_autocheckpoint = 1000'); }
    if (strtoupper((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()) !== SQLITE_JOURNAL_MODE
        || (int) $pdo->query('PRAGMA synchronous')->fetchColumn() !== 2) {
        throw new RuntimeException('SQLite journal/FULL settings could not be applied.');
    }
    unset($pdo, $wpliteDriverRoot);
} catch (Throwable $error) {
    error_log('WPLite: ' . $error->getMessage());
    http_response_code(503);
    exit('WPLite cannot initialize SQLite safely.');
}
