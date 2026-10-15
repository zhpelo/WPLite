<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Use wplite/cli.php for command-line installation.\n");
    exit(1);
}
require_once __DIR__ . '/src/WebInstaller.php';
\WPLite\WebInstaller::handle(dirname(__DIR__));
