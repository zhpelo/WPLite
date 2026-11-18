<?php
declare(strict_types=1);

// Never load wp-config.php, plugins, or the site's request/bootstrap lifecycle.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
require_once __DIR__ . '/copy-runner.php';
try {
    if ($argc !== 1) { throw new RuntimeException('stdin_required'); }
    $input = stream_get_contents(STDIN, 65537);
    if (strlen($input) > 65536) { throw new RuntimeException('input_too_large'); }
    $config = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config)) { throw new RuntimeException('invalid_input'); }
    $result = \WPLite\CopyRunner::run($config);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    // Driver errors may contain SQL values or connection details. Report only a stage code.
    fwrite(STDERR, json_encode(['status' => 'failed', 'stage' => \WPLite\CopyRunner::$stage]) . "\n");
    exit(1);
}
