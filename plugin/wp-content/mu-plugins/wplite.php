<?php
/**
 * Plugin Name: WPLite SQLite
 * Plugin URI: https://github.com/zhpelo/WPLite
 * Description: Minimal SQLite integration and Site Health information. WordPress owns core updates.
 * Version: 0.2.0-dev
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// No update interception, background tasks, request locks or recovery framework.
add_filter('debug_information', static function (array $information): array {
    $pdo = $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo();
    $information['wplite'] = [
        'label' => 'WPLite SQLite',
        'fields' => [
            'version' => ['label' => 'WPLite', 'value' => '0.2.0-dev'],
            'driver' => ['label' => 'SQLite driver', 'value' => defined('SQLITE_DRIVER_VERSION') ? SQLITE_DRIVER_VERSION : 'unknown'],
            'sqlite' => ['label' => 'SQLite', 'value' => $pdo->query('SELECT sqlite_version()')->fetchColumn()],
            'journal' => ['label' => 'Journal mode', 'value' => $pdo->query('PRAGMA journal_mode')->fetchColumn()],
        ],
    ];
    return $information;
});
