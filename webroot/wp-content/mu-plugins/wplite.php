<?php
/**
 * Plugin Name: WPLite SQLite
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

// Database integration loads earlier through wp-config.php and the db.php drop-in.
// No settings pages, diagnostics, background tasks or update interception.
