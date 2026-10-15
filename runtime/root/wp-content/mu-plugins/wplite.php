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

if (!(defined('WPLITE_INSTALL_AUTHORIZED') && WPLITE_INSTALL_AUTHORIZED)
    && get_option('wplite_site_id') !== WPLITE_SITE_ID) {
    wp_die('The SQLite database does not belong to this WPLite installation.', 'WPLite site mismatch', ['response' => 503]);
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

// Register callbacks without loading diagnostic classes or querying probe state
// on ordinary front-end requests. Events exist only after an explicit probe.
add_action('wplite_diagnostic_canary', static function ($id): void {
    require_once ABSPATH . 'wplite/src/CronProbe.php';
    \WPLite\CronProbe::observe($id);
}, 10, 1);
add_action('admin_menu', static function (): void {
    add_management_page('WPLite 运行诊断', 'WPLite 诊断', 'manage_options', 'wplite-diagnostics', static function (): void {
        require ABSPATH . 'wplite/admin.php';
    });
});
add_action('admin_post_wplite_cron_probe', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('Administrator permission is required.', 'WPLite', ['response' => 403]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('Use an authenticated form POST.', 'WPLite', ['response' => 405]);
    }
    check_admin_referer('wplite_cron_probe');
    $transport = $_POST['transport'] ?? '';
    if (!is_string($transport) || !in_array($transport, ['schedule', 'loopback'], true)) {
        wp_die('Unknown Cron diagnostic transport.', 'WPLite', ['response' => 400]);
    }
    require_once ABSPATH . 'wplite/src/CronProbe.php';
    try {
        \WPLite\CronProbe::start($transport);
    } catch (Throwable $error) {
        error_log('WPLite: the requested Cron diagnostic could not start.');
        wp_die('Cron probe could not start. Inspect the CLI report and filesystem permissions.', 'WPLite', ['response' => 503]);
    }
    wp_safe_redirect(admin_url('tools.php?page=wplite-diagnostics'));
    exit;
});

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
