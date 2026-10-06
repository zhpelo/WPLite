<?php
// WPLITE_SQLITE_DROPIN_V1 — mandatory database bootstrap, not a normal plugin.
declare(strict_types=1);

if (!defined('WPLITE_BOOTSTRAPPED') || !WPLITE_BOOTSTRAPPED) {
    http_response_code(503);
    echo 'WPLite requires its matching wp-config.php bootstrap.';
    exit(1);
}

define('SQLITE_JOURNAL_MODE', 'WAL');
require dirname(__DIR__) . '/wplite/vendor/sqlite/wp-includes/sqlite/db.php';

// The upstream WordPress integration currently defaults to NORMAL and does not
// expose the standalone driver's synchronous option. Set and verify FULL before
// any WordPress business writes are allowed. Driver metadata initialization runs
// first; user content/commerce transactions do not.
try {
    $wplitePDO = $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo();
    $wplitePDO->exec('PRAGMA synchronous = FULL');
    $wplitePDO->exec('PRAGMA busy_timeout = 5000');
    $wplitePDO->exec('PRAGMA wal_autocheckpoint = 1000');
    if (strtolower((string) $wplitePDO->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal'
        || (int) $wplitePDO->query('PRAGMA synchronous')->fetchColumn() !== 2) {
        throw new RuntimeException('WPLite requires WAL and synchronous=FULL on a local filesystem.');
    }
    unset($wplitePDO);
} catch (Throwable $wpliteError) {
    error_log('WPLite database settings: ' . $wpliteError->getMessage());
    http_response_code(503);
    echo 'WPLite cannot verify safe database settings. See the server error log.';
    exit(1);
}
