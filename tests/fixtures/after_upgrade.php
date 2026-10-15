<?php
declare(strict_types=1);
require getenv('WPLITE_TEST_SITE') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_upgrade(); // Native core's loopback call is blocked in the offline fixture.
if ($GLOBALS['wp_version'] !== getenv('WPLITE_TEST_TARGET')
    || (int) get_option('db_version') !== (int) $GLOBALS['wp_db_version']
    || get_post((int) getenv('WPLITE_TEST_POST'))->post_title !== 'WPLite SQLite 中文 😀'
    || get_option('wplite_test_value') !== ['中文' => '😀']) {
    throw new RuntimeException('Core/database upgrade did not preserve the original content');
}
global $wpdb;
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wplite_test WHERE label = 'committed'") !== 1) {
    throw new RuntimeException('Native upgrade did not preserve the plugin-style custom table');
}
echo "PASS: native wp_upgrade completed and preserved content/options\n";
