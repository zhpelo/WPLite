<?php
// Test-only adversarial HTTP policy. Never include in a distribution.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$mode = getenv('WPLITE_TEST_PROBE_MODE');
if ($mode === 'bad-control' && str_starts_with($path, '/wplite-probe-')) {
    http_response_code(403);
    exit;
}
if (str_starts_with($path, '/.wplite-data/')) {
    if ($mode === 'redirect') {
        header('Location: /', true, 302);
        exit;
    }
    if ($mode === 'server-error') {
        http_response_code(500);
        exit;
    }
    if ($mode === 'sqlite-only' && !str_ends_with($path, '.sqlite')) {
        return false;
    }
    if ($mode === 'backups-exposed' && str_starts_with($path, '/.wplite-data/backups/')) {
        return false;
    }
    if (str_starts_with($path, '/.wplite-data/backups/') && str_contains($path, '/site/')
        && (($mode === 'backup-php-exposed' && str_ends_with($path, '.php'))
            || ($mode === 'backup-images-exposed' && str_ends_with($path, '.jpg')))) {
        return false;
    }
    http_response_code(403);
    if ($mode === 'denied-but-leaking') {
        $file = realpath(getenv('WPLITE_TEST_SITE') . $path);
        if ($file !== false && str_starts_with($file, getenv('WPLITE_TEST_SITE') . '/.wplite-data/') && is_file($file)) {
            readfile($file);
        }
    }
    exit;
}
return false;
