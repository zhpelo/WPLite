<?php
// Integration regression; operates only on a caller-created temporary MySQL DB.
declare(strict_types=1);
$root = dirname(__DIR__);
$config = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
require $root . '/wp-content/mu-plugins/wplite/copy-runner.php';
$report = \WPLite\CopyRunner::run($config);
$pdo = new PDO('sqlite:' . $config['output'] . '/site.sqlite');
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
check($pdo->query('SELECT COUNT(*) FROM custom_options')->fetchColumn() === 1, 'transients retained');
check($pdo->query('SELECT COUNT(*) FROM custom_duplicate')->fetchColumn() === 3, 'duplicate rows lost');
check($pdo->query('SELECT hex(payload) FROM custom_orders')->fetchColumn() === '0001FF00', 'binary changed');
check((string)$pdo->query('SELECT id FROM custom_orders')->fetchColumn() === '9007199254740993', 'bigint changed');
check((string)$pdo->query('SELECT amount FROM custom_orders')->fetchColumn() === '123.45', 'decimal changed');
check($pdo->query('SELECT option_value FROM custom_options')->fetchColumn() === 'a:1:{s:4:"test";s:6:"中文";}', 'serialized data changed');
foreach (['actionscheduler_logs', 'woocommerce_log', 'woocommerce_sessions'] as $table) {
    check($pdo->query('SELECT COUNT(*) FROM custom_' . $table)->fetchColumn() === 0, 'skip rule changed');
}
check($pdo->query('SELECT COUNT(*) FROM custom_actionscheduler_actions')->fetchColumn() === 1, 'task lost');
foreach ($report['tables'] as $table) { check(strlen($table['schema_sha256']) === 64, 'schema hash absent'); }
check(!str_contains(file_get_contents($config['output'] . '/report.json'), 'password'), 'credential in report');

// Run the browser's exact copy/verification entry points on the same fixture,
// without entering its HTTP handlers or publish/configuration mutation methods.
define('WP_CONTENT_DIR', $root . '/wp-content');
require $root . '/wp-content/mu-plugins/wplite/migration.php';
$class = new ReflectionClass(\WPLite\Migration::class);
$class->getProperty('deadline')->setValue(null, microtime(true) + 60);
$source = new \WPLite\CopySource($config);
$GLOBALS['wpdb'] = $source;
$target = $class->getMethod('target')->invoke(null, $config['output'] . '/browser.sqlite', $source);
$browserPDO = $target->get_sqlite_pdo();
$browserPDO->exec('CREATE TABLE _wp_sqlite_wplite_migration_hashes (table_name TEXT NOT NULL, pass TEXT NOT NULL, hash TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY(table_name,pass,hash)) WITHOUT ROWID');
$state = ['phase'=>'copying','step'=>'schema','index'=>0,'offset'=>0,'cursor'=>[], 'tables'=>[], 'names'=>$class->getMethod('tables')->invoke(null,$source)];
while ($state['step'] !== 'publish') { $args = [&$state, $source, $target, 1000, microtime(true)+2]; $class->getMethod('advance')->invokeArgs(null,$args); }
foreach ($state['names'] as $table) {
    $quote = '"'.str_replace('"','""',$table).'"';
    $left = $pdo->query('SELECT * FROM '.$quote)->fetchAll(PDO::FETCH_ASSOC);
    $right = $browserPDO->query('SELECT * FROM '.$quote)->fetchAll(PDO::FETCH_ASSOC);
    check($left === $right, 'browser/CLI mismatch: '.$table);
}
$source->query('SELECT COUNT(*) AS n FROM custom_options');
check((int) $source->last_result[0]->n === 3, 'source transient rows changed');
$source->query('SELECT COUNT(*) AS n FROM custom_woocommerce_sessions');
check((int) $source->last_result[0]->n === 1, 'source sessions changed');
$source->close();
echo "PASS: CLI data, omissions, hashes, and browser-core equivalence\n";
