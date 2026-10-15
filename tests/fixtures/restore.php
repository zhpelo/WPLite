<?php
declare(strict_types=1);

$root = getenv('WPLITE_TEST_SITE');
$data = getenv('WPLITE_TEST_DATA_DIR');
require $root . '/wplite/src/Restore.php';
$state = \WPLite\Environment::readState($data);
$mode = $argv[1];
$backup = $argv[2] ?? '';

function scalar(array $state, string $query)
{
    $pdo = new PDO('sqlite:file:' . str_replace('%2F', '/', rawurlencode($state['database'])) . '?mode=rw');
    return $pdo->query($query)->fetchColumn();
}

if ($mode === 'restore') {
    $before = scalar($state, 'SELECT COUNT(*) FROM "' . $state['table_prefix'] . 'posts" WHERE post_title = \'Written after snapshot\'');
    if ((int) $before !== 1) {
        throw new RuntimeException('Restore test did not start with post-snapshot content.');
    }
    $report = \WPLite\Restore::restore($root, $data, $backup);
    if ((int) scalar($state, 'SELECT COUNT(*) FROM "' . $state['table_prefix'] . 'posts" WHERE post_title = \'Written after snapshot\'') !== 0) {
        throw new RuntimeException('Restore did not replace the database with the snapshot.');
    }
    if (!is_file($report['rollback_directory'] . '/' . basename($state['database']))) {
        throw new RuntimeException('Restore did not preserve the original database.');
    }
} elseif ($mode === 'prepare-failure') {
    $pdo = new PDO('sqlite:file:' . str_replace('%2F', '/', rawurlencode($state['database'])) . '?mode=rw');
    $pdo->exec('INSERT OR REPLACE INTO "' . $state['table_prefix'] . 'options" (option_name,option_value,autoload) VALUES (\'wplite_rollback_probe\',\'keep-me\',\'off\')');
    unset($pdo);
    copy($root . '/wp-content/db.php', $data . '/dropin-before-test.php');
    file_put_contents($root . '/wp-content/db.php', "\n// Force a runtime health-check failure.\n", FILE_APPEND);
    try {
        \WPLite\Restore::restore($root, $data, $backup);
        throw new RuntimeException('Expected restore health failure did not happen.');
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), 'maintenance still active')) {
            throw $error;
        }
    }
    if (\WPLite\Maintenance::status($data)['phase'] !== 'failed') {
        throw new RuntimeException('Failed restore lost its maintenance journal.');
    }
    $report = ['status' => 'failed-safely'];
} elseif ($mode === 'verify-rollback') {
    if (scalar($state, 'SELECT option_value FROM "' . $state['table_prefix'] . 'options" WHERE option_name = \'wplite_rollback_probe\'') !== 'keep-me'
        || \WPLite\Maintenance::status($data)['phase'] !== 'inactive') {
        throw new RuntimeException('Rollback did not preserve the original logical content or reopen the site.');
    }
    $report = ['status' => 'verified'];
} elseif ($mode === 'verify-complete') {
    if (scalar($state, 'SELECT option_value FROM "' . $state['table_prefix'] . 'options" WHERE option_name = \'wplite_rollback_probe\'') !== false
        || \WPLite\Maintenance::status($data)['phase'] !== 'inactive') {
        throw new RuntimeException('Completing recovery did not keep the restored snapshot.');
    }
    $report = ['status' => 'verified'];
} else {
    throw new RuntimeException('Unknown restore fixture mode.');
}
echo json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL;
