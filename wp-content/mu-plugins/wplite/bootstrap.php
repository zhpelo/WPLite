<?php
declare(strict_types=1);

// Required explicitly by wp-config.php before wp-settings.php: MU loading is too
// late to prevent a missing drop-in from attempting the default MySQL connection.
if (!defined('ABSPATH')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/environment.php';

try {
    define('SQLITE_JOURNAL_MODE', \WPLite\Environment::journalMode(\WPLite\Environment::requireSupported()));
    if (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite' || !defined('DB_PATH')
        || !is_string(DB_PATH) || !defined('WPLITE_PUBLIC_ROOT')) {
        throw new RuntimeException('Missing SQLite configuration. Use the WPLite new-site setup instructions.');
    }
    $public = realpath(WPLITE_PUBLIC_ROOT);
    $parent = realpath(dirname(DB_PATH));
    $database = realpath(DB_PATH);
    if ($public === false || $parent === false || $database !== DB_PATH || is_link(DB_PATH)
        || !is_file(DB_PATH) || filesize(DB_PATH) === 0) {
        throw new RuntimeException('The existing SQLite database must be a regular file. It will not be recreated.');
    }
    $served = PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])
        ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if (\WPLite\Environment::isWithin($database, $public)
        || $served !== false && $served !== '' && \WPLite\Environment::isWithin($database, $served)) {
        $deny = $parent . '/.htaccess';
        if (!defined('WPLITE_STORAGE_DENY_HASH') || !is_file($deny) || is_link($deny)
            || !hash_equals(hash('sha256', "Require all denied\n"), WPLITE_STORAGE_DENY_HASH)
            || !hash_equals(WPLITE_STORAGE_DENY_HASH, hash_file('sha256', $deny))) {
            throw new RuntimeException('Public-root storage has no matching verified deny rule.');
        }
    }
    $content = dirname(__DIR__, 2);
    if (!is_file($content . '/db.php') || is_link($content . '/db.php')
        || !is_file(__DIR__ . '/vendor/sqlite/wp-includes/sqlite/db.php')) {
        throw new RuntimeException('The WPLite database drop-in or driver is missing.');
    }
    define('WPLITE_READY', true);
} catch (Throwable $error) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'WPLite: ' . $error->getMessage() . PHP_EOL);
    } else {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'WPLite is unavailable. Ask the site owner to check the configuration and database files.';
        error_log('WPLite: ' . $error->getMessage());
    }
    exit(1);
}
