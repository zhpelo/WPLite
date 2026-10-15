<?php
declare(strict_types=1);
require getenv('WPLITE_TEST_SITE') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$id = wp_insert_post(['post_title' => 'WPLite SQLite 中文 😀', 'post_content' => 'A genuine SQLite-backed WordPress post.', 'post_status' => 'publish'], true);
check(!is_wp_error($id) && $id > 0, 'Post creation failed');
check(get_post($id)->post_title === 'WPLite SQLite 中文 😀', 'Unicode round-trip failed');
wp_update_post(['ID' => $id, 'post_content' => 'Updated content']);
check(get_post($id)->post_content === 'Updated content', 'Post update failed');
update_option('wplite_test_value', ['中文' => '😀']);
check(get_option('wplite_test_value') === ['中文' => '😀'], 'Serialized option round-trip failed');

global $wpdb;
$table = $wpdb->prefix . 'wplite_test';
dbDelta("CREATE TABLE {$table} (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 label varchar(191) NOT NULL,
 PRIMARY KEY  (id),
 KEY label (label)
) " . $wpdb->get_charset_collate() . ';');
check($wpdb->insert($table, ['label' => 'committed']) === 1, 'Plugin-style custom table insert failed');
check($wpdb->query('START TRANSACTION') !== false, 'Transaction start failed');
check($wpdb->insert($table, ['label' => 'rolled back']) === 1, 'Transactional insert failed');
check($wpdb->query('ROLLBACK') !== false, 'Transaction rollback failed');
check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === 1, 'Rollback did not preserve custom table state');
check($wpdb->last_error === '', 'Unexpected SQL error');

$pdo = $wpdb->get_driver()->get_sqlite_pdo();
check($pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite integrity failure');
check(strtolower($pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', 'WAL not enabled');
check((int) $pdo->query('PRAGMA synchronous')->fetchColumn() === 2, 'FULL not enabled');
check((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'Lock wait not configured');
check(is_wp_error(apply_filters('upgrader_pre_install', true, ['type' => 'core'])), 'Direct core update not blocked');
check(apply_filters('upgrader_pre_install', true, ['type' => 'plugin']) === true, 'Plugin update unexpectedly blocked');
echo json_encode(['status' => 'ok', 'post_id' => $id, 'sqlite' => $pdo->query('SELECT sqlite_version()')->fetchColumn()], JSON_THROW_ON_ERROR) . PHP_EOL;
