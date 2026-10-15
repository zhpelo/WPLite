<?php
// Tests run only in their own extracted ZIP and private data directory.
$site = getenv('WPLITE_TEST_SITE');
$wpliteTestAction = $argv[1] ?? '';
if (in_array($wpliteTestAction, ['maintenance', 'finish-maintenance'], true)) {
    require $site . '/wplite/src/Environment.php';
    require $site . '/wplite/src/Maintenance.php';
    $lease = \WPLite\Maintenance::acquire(getenv('WPLITE_TEST_DATA_DIR'), resume: $wpliteTestAction === 'finish-maintenance');
    if ($wpliteTestAction === 'finish-maintenance') {
        $lease->finish();
    }
    echo '{}';
    exit;
}
require $site . '/wp-load.php';
require_once $site . '/wplite/src/Diagnostics.php';
require_once $site . '/wplite/src/CronProbe.php';
switch ($wpliteTestAction) {
    case 'seed':
        $user = wp_insert_user(['user_login' => 'diagnostic-reader', 'user_pass' => getenv('WPLITE_TEST_READER_PASSWORD'),
            'user_email' => 'reader@example.invalid', 'role' => 'subscriber']);
        if (is_wp_error($user)) {
            throw new RuntimeException('Could not seed diagnostic reader.');
        }
        break;
    case 'inspect':
        $record = get_option(\WPLite\CronProbe::OPTION, []);
        $count = 0;
        foreach (_get_cron_array() as $hooks) {
            $count += count($hooks[\WPLite\CronProbe::HOOK] ?? []);
        }
        echo json_encode(['events' => $count, 'record' => $record,
            'autoloaded' => array_key_exists(\WPLite\CronProbe::OPTION, wp_load_alloptions())], JSON_THROW_ON_ERROR);
        exit;
    case 'reset':
        wp_unschedule_hook(\WPLite\CronProbe::HOOK);
        delete_option(\WPLite\CronProbe::OPTION);
        delete_transient('doing_cron');
        break;
    case 'outside-cron':
        $record = get_option(\WPLite\CronProbe::OPTION);
        do_action(\WPLite\CronProbe::HOOK, $record['id']);
        break;
    case 'age':
        $record = get_option(\WPLite\CronProbe::OPTION);
        $record['requested_at'] -= 180;
        $record['deadline'] -= 180;
        update_option(\WPLite\CronProbe::OPTION, $record, false);
        break;
    case 'stale-release':
        $record = get_option(\WPLite\CronProbe::OPTION);
        $record['distribution'] = str_repeat('0', 64);
        update_option(\WPLite\CronProbe::OPTION, $record, false);
        break;
    case 'old-observation':
        $record = get_option(\WPLite\CronProbe::OPTION);
        foreach (['requested_at', 'deadline', 'executed_at'] as $field) {
            $record[$field] -= 90000;
        }
        update_option(\WPLite\CronProbe::OPTION, $record, false);
        break;
    case 'invalid-record':
        update_option(\WPLite\CronProbe::OPTION, ['private' => 'not-a-probe-record'], false);
        break;
    case 'unsafe-connection':
        $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo()->exec('PRAGMA synchronous = NORMAL');
        echo json_encode(\WPLite\Diagnostics::site(), JSON_THROW_ON_ERROR);
        exit;
    case 'wal-size':
        $pdo = $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo();
        $statement = $pdo->prepare('INSERT INTO "' . $GLOBALS['table_prefix'] . 'options" (option_name,option_value,autoload) VALUES (\'wplite_diagnostic_wal_size\',?,\'off\')');
        $statement->execute([str_repeat('w', 2 * 1024 * 1024)]);
        $statement->closeCursor();
        clearstatcache();
        $report = \WPLite\Diagnostics::offline(rtrim(ABSPATH, '/'), dirname(DB_PATH));
        $pdo->exec('DELETE FROM "' . $GLOBALS['table_prefix'] . 'options" WHERE option_name=\'wplite_diagnostic_wal_size\'');
        echo json_encode($report, JSON_THROW_ON_ERROR);
        exit;
    default:
        throw new RuntimeException('Unknown diagnostic test action.');
}
echo '{}';
