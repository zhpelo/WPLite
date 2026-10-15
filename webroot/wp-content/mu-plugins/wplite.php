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
    require_once __DIR__ . '/wplite/setup.php';
    \WPLite\Setup::render();
    exit;
}

// Normal requests load SQLite earlier through wp-config.php and db.php.
