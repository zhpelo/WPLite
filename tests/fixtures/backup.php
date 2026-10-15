<?php
declare(strict_types=1);

require getenv('WPLITE_TEST_SITE') . '/wp-load.php';
require ABSPATH . 'wplite/src/Backup.php';

function expectFailure(callable $action, string $message): void
{
    try {
        $action();
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), $message)) {
            throw new RuntimeException('Unexpected rejection: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$pdo = $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo();
$data = dirname(DB_PATH);
$prefix = $GLOBALS['table_prefix'];
$pdo->exec('PRAGMA wal_autocheckpoint=0');
// Keep an independent connection open so committed content remains in the WAL.
$reader = new PDO('sqlite:' . DB_PATH);
$id = wp_insert_post(['post_title' => 'Committed in WAL', 'post_status' => 'publish']);
if (!is_file(DB_PATH . '-wal') || filesize(DB_PATH . '-wal') === 0) {
    throw new RuntimeException('Test did not exercise a live WAL database.');
}
$report = \WPLite\Backup::create($pdo, ABSPATH, $data, WPLITE_SITE_ID, $prefix, null, WPLITE_PUBLIC_ROOT);
$directory = $report['directory'];
$state = \WPLite\Environment::readState($data);
$key = \WPLite\Backup::backupKey($state);
\WPLite\Backup::verify($directory, $key, WPLITE_SITE_ID);
$later = wp_insert_post(['post_title' => 'Written after snapshot', 'post_status' => 'publish']);
$snapshot = new PDO('sqlite:file:' . str_replace('%2F', '/', rawurlencode($directory . '/database.sqlite')) . '?mode=ro&immutable=1');
if ($snapshot->query('SELECT post_title FROM "' . $prefix . 'posts" WHERE ID = ' . (int) $id)->fetchColumn() !== 'Committed in WAL'
    || $snapshot->query('SELECT post_title FROM "' . $prefix . 'posts" WHERE ID = ' . (int) $later)->fetchColumn() !== false) {
    throw new RuntimeException('Snapshot lost committed WAL data or changed after creation.');
}
unset($snapshot, $reader);

$manifestFile = $directory . '/manifest.json';
$manifestText = file_get_contents($manifestFile);
if (str_contains($manifestText, $key) || str_contains($manifestText, 'backup_key')) {
    throw new RuntimeException('Backup exposed its private authentication key.');
}
expectFailure(fn () => \WPLite\Backup::verify($directory, str_repeat('0', 64), WPLITE_SITE_ID), 'authentication failed');
expectFailure(fn () => \WPLite\Backup::verify($directory, $key, str_repeat('0', 48)), 'site identity');
$manifest = json_decode($manifestText, true);
$manifest['created_at'] = 'tampered';
file_put_contents($manifestFile, json_encode($manifest));
expectFailure(fn () => \WPLite\Backup::verify($directory, $key, WPLITE_SITE_ID), 'authentication failed');
file_put_contents($manifestFile, $manifestText);

$databaseFile = $directory . '/database.sqlite';
$original = file_get_contents($databaseFile);
file_put_contents($databaseFile, 'X' . substr($original, 1));
expectFailure(fn () => \WPLite\Backup::verify($directory, $key, WPLITE_SITE_ID), 'SHA-256');
file_put_contents($databaseFile, $original);
\WPLite\Backup::verify($directory, $key, WPLITE_SITE_ID);

expectFailure(fn () => \WPLite\Backup::create($pdo, ABSPATH, $data, WPLITE_SITE_ID, $prefix, $directory, WPLITE_PUBLIC_ROOT), 'Refusing to overwrite');
expectFailure(fn () => \WPLite\Backup::create($pdo, ABSPATH, $data, WPLITE_SITE_ID, $prefix, ABSPATH . 'public-backup', WPLITE_PUBLIC_ROOT), 'public document root');
$pdo->beginTransaction();
expectFailure(fn () => \WPLite\Backup::create($pdo, ABSPATH, $data, WPLITE_SITE_ID, $prefix, null, WPLITE_PUBLIC_ROOT), 'active transaction');
$pdo->rollBack();
$operation = fopen($data . '/.operations.lock', 'c');
flock($operation, LOCK_EX);
expectFailure(fn () => \WPLite\Backup::create($pdo, ABSPATH, $data, WPLITE_SITE_ID, $prefix, null, WPLITE_PUBLIC_ROOT), 'maintenance operation');
flock($operation, LOCK_UN);
fclose($operation);
$pdo->exec('PRAGMA wal_autocheckpoint=1000');
echo json_encode(['status' => 'passed', 'directory' => $directory,
    'checks' => ['committed-wal-data', 'snapshot-isolation', 'readonly-verification', 'private-key',
        'manifest-tampering', 'database-tampering', 'wrong-key', 'foreign-site',
        'overwrite-refusal', 'public-root-refusal', 'active-transaction', 'operation-lock']], JSON_THROW_ON_ERROR) . PHP_EOL;
