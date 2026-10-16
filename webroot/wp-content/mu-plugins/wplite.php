<?php
/**
 * Plugin Name: WPLite
 * Plugin URI: https://github.com/zhpelo/WPLite
 * Description: Minimal SQLite integration. WordPress owns core updates.
 * Version: 0.2.0-dev
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('WP_SETUP_CONFIG') && WP_SETUP_CONFIG) {
    // Native MySQL configuration links and forms use steps 1 and 2.
    if (isset($_GET['step']) && in_array($_GET['step'], ['1', '2'], true)) {
        return;
    }
    require_once __DIR__ . '/wplite/setup.php';
    \WPLite\Setup::render();
    exit;
}

// Normal requests load SQLite earlier through wp-config.php and db.php.
if ((!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite') && !is_multisite()) {
    require_once __DIR__ . '/wplite/migration.php';
    add_action('admin_notices', [\WPLite\Migration::class, 'notice']);
    add_action('admin_post_wplite_migration', [\WPLite\Migration::class, 'handle']);
}
