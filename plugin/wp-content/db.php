<?php
// WPLITE_SQLITE_DROPIN_V2 — supported WordPress database drop-in, not core code.
declare(strict_types=1);

if (!defined('ABSPATH')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/mu-plugins/wplite/bootstrap.php';
define('SQLITE_JOURNAL_MODE', 'WAL');
require __DIR__ . '/mu-plugins/wplite/vendor/sqlite/wp-includes/sqlite/db.php';

try {
    $pdo = $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo();
    $pdo->exec('PRAGMA synchronous = FULL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA wal_autocheckpoint = 1000');
    if (strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal'
        || (int) $pdo->query('PRAGMA synchronous')->fetchColumn() !== 2) {
        throw new RuntimeException('SQLite WAL/FULL settings could not be applied.');
    }
    unset($pdo);
} catch (Throwable $error) {
    error_log('WPLite: ' . $error->getMessage());
    http_response_code(503);
    exit('WPLite cannot initialize SQLite safely.');
}
