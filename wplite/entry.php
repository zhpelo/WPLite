<?php
declare(strict_types=1);

$wpliteConfig = __DIR__ . '/site-config.php';
if (is_file($wpliteConfig) && !is_link($wpliteConfig)) {
    require $wpliteConfig;
    return;
}
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "WPLite is not configured. Run wplite/cli.php install or use the authorized Web installer.\n");
    exit(1);
}
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(409);
    exit('WPLite is not configured.');
}
$wpliteDocument = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$wpliteRoot = dirname(__DIR__);
if ($wpliteDocument === false || !str_starts_with($wpliteRoot . '/', rtrim($wpliteDocument, '/') . '/')) {
    http_response_code(503);
    exit('Cannot determine the public document root. Open wplite/install.php directly.');
}
$wplitePrefix = substr($wpliteRoot, strlen(rtrim($wpliteDocument, '/')));
header('Location: ' . $wplitePrefix . '/wplite/install.php', true, 302);
exit;
