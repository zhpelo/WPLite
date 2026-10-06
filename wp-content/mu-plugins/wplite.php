<?php
/**
 * Plugin Name: WPLite System
 * Description: Mandatory SQLite distribution diagnostics and update guard.
 * Version: 0.1.0-dev
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Development releases must not install an unvalidated WordPress core over the
// distribution. Plugin/theme update channels remain available. The signed bundle
// updater will replace this guard when that milestone is complete.
add_filter('allow_dev_auto_core_updates', '__return_false');
add_filter('allow_minor_auto_core_updates', '__return_false');
add_filter('allow_major_auto_core_updates', '__return_false');
add_filter('auto_update_core', '__return_false');
add_filter('upgrader_pre_install', static function ($result, $extra) {
    if (($extra['type'] ?? '') === 'core') {
        return new WP_Error('wplite_bundle_required', 'Install a validated WPLite release; direct WordPress core updates are not supported.');
    }
    return $result;
}, 10, 2);

add_filter('debug_information', static function (array $information): array {
    $manifest = json_decode(file_get_contents(ABSPATH . 'wplite/distribution.json'), true);
    $information['wplite'] = [
        'label' => 'WPLite',
        'fields' => [
            'version' => ['label' => 'Distribution', 'value' => $manifest['wplite_version']],
            'core' => ['label' => 'WordPress stable tag', 'value' => $manifest['wordpress']['tag']],
            'driver' => ['label' => 'SQLite driver', 'value' => $manifest['sqlite']['tag']],
            'sqlite' => ['label' => 'SQLite library', 'value' => $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo()->query('SELECT sqlite_version()')->fetchColumn()],
        ],
    ];
    return $information;
});
