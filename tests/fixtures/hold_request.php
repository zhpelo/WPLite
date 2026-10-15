<?php
declare(strict_types=1);

$root = getenv('WPLITE_TEST_SITE');
$data = getenv('WPLITE_TEST_DATA_DIR');
require $root . '/wplite/src/Maintenance.php';
$GLOBALS['wplite_request_lock'] = \WPLite\Maintenance::guard($data);
register_shutdown_function(static function () use ($data): void {
    $deadline = microtime(true) + 10;
    while (!is_file($data . '/maintenance.json')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Restore did not enter request-draining maintenance.');
        }
        usleep(10000);
    }
    usleep(100000);
    file_put_contents($data . '/guard-finished', 'shutdown finished');
});
file_put_contents($data . '/guard-ready', 'shutdown pending');
