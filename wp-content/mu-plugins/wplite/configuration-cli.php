<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077);
try {
    if ($argc !== 1) { throw new RuntimeException('stdin_required'); }
    $raw = stream_get_contents(STDIN, 1048577);
    if (strlen($raw) > 1048576) { throw new RuntimeException('input_too_large'); }
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['configuration'] ?? null)
        || !is_string($input['prefix'] ?? null) || !is_string($input['storage'] ?? null)) { throw new RuntimeException('invalid_input'); }
    define('ABSPATH', '/wplite-migration-copy/');
    require_once __DIR__ . '/copy-configuration.php';
    fwrite(STDOUT, \WPLite\CopyConfiguration::render($input['configuration'], $input['prefix'], $input['storage']));
} catch (Throwable $error) {
    fwrite(STDERR, "configuration_generation_failed\n"); exit(1);
}
