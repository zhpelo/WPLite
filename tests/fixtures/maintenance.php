<?php
declare(strict_types=1);

$root = getenv('WPLITE_TEST_SITE');
$data = getenv('WPLITE_TEST_DATA_DIR');
require $root . '/wplite/src/Restore.php';

if (($argv[1] ?? '') === 'hold') {
    $lease = \WPLite\Maintenance::acquire($data);
    file_put_contents($data . '/maintenance-ready', 'ready for process termination');
    while (true) {
        usleep(100000);
    }
}

$guard = \WPLite\Maintenance::guard($data);
try {
    \WPLite\Maintenance::acquire($data, timeout: 0.05);
    throw new RuntimeException('Drain timeout did not reject an active request.');
} catch (Throwable $error) {
    if (!str_contains($error->getMessage(), 'Timed out')) {
        throw $error;
    }
}
flock($guard, LOCK_UN);
fclose($guard);
if (\WPLite\Maintenance::status($data)['phase'] !== 'inactive') {
    throw new RuntimeException('Drain timeout left a maintenance marker despite no database mutations.');
}

$lease = \WPLite\Maintenance::acquire($data);
unset($lease); // Simulate an interrupted owner; marker must remain.
$versionFile = $root . '/wp-includes/version.php';
$original = file_get_contents($versionFile);
file_put_contents($versionFile, "<?php\n\$wp_version = '0.0.0';\n");
try {
    \WPLite\Restore::complete($root, $data);
    throw new RuntimeException('Wrong core version was reopened.');
} catch (Throwable $error) {
    if (!str_contains($error->getMessage(), 'core version')) {
        throw $error;
    }
} finally {
    file_put_contents($versionFile, $original);
}
\WPLite\Restore::complete($root, $data);
echo json_encode(['status' => 'passed', 'checks' => ['drain-timeout', 'durable-marker', 'core-release-gate']]) . PHP_EOL;
