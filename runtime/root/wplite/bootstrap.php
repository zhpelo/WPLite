<?php
declare(strict_types=1);

// This file is required by wp-config.php before wp-settings.php. MU plugins load
// too late to prevent a missing/replaced db.php from falling back to MySQL.
require_once __DIR__ . '/src/Environment.php';
require_once __DIR__ . '/src/Maintenance.php';

try {
    \WPLite\Environment::requireSupported();
    if (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite' || !defined('DB_PATH')
        || !defined('WPLITE_SITE_ID')) {
        throw new RuntimeException('The WPLite SQLite configuration is incomplete.');
    }
    $wpliteManifest = json_decode(file_get_contents(__DIR__ . '/distribution.json'), true, flags: JSON_THROW_ON_ERROR);
    $wpliteDropin = dirname(__DIR__) . '/wp-content/db.php';
    if (!is_file($wpliteDropin)
        || !hash_equals($wpliteManifest['files']['wp-content/db.php'], hash_file('sha256', $wpliteDropin))) {
        throw new RuntimeException('The WPLite database drop-in is missing or has been replaced. Restore it from the matching release.');
    }
    if (defined('WP_CONTENT_DIR') && realpath(WP_CONTENT_DIR) !== realpath(dirname(__DIR__) . '/wp-content')) {
        throw new RuntimeException('Custom WP_CONTENT_DIR is not supported by this development build.');
    }
    $wpliteState = \WPLite\Environment::readState(dirname(DB_PATH));
    if (!hash_equals(WPLITE_SITE_ID, $wpliteState['site_id'] ?? '') || ($wpliteState['database'] ?? '') !== DB_PATH) {
        throw new RuntimeException('The database path or site identity does not match its installation state.');
    }
    $wpliteInstalling = defined('WPLITE_INSTALL_AUTHORIZED') && WPLITE_INSTALL_AUTHORIZED;
    $GLOBALS['wplite_request_lock'] = \WPLite\Maintenance::guard(dirname(DB_PATH));
    if (($wpliteState['status'] ?? '') !== 'installed' && !$wpliteInstalling) {
        throw new RuntimeException('Installation is incomplete. Resume recovery using the private installation tools.');
    }
    if (!$wpliteInstalling && (!is_file(DB_PATH) || filesize(DB_PATH) === 0)) {
        throw new RuntimeException('The installed database is missing or empty. Restore a backup; a new database will not be created.');
    }
    define('WPLITE_BOOTSTRAPPED', true);
    unset($wpliteManifest, $wpliteDropin, $wpliteState, $wpliteInstalling);
} catch (Throwable $wpliteError) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'WPLite: ' . $wpliteError->getMessage() . PHP_EOL);
    } else {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 30');
        echo 'WPLite cannot start safely. Check the server error log and restore the matching database components.';
        error_log('WPLite: ' . $wpliteError->getMessage());
    }
    exit(1);
}
