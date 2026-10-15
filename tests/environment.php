<?php
declare(strict_types=1);
require dirname(__DIR__) . '/runtime/root/wplite/src/Environment.php';

foreach (['3.44.6', '3.50.7', '3.51.3', '3.53.4'] as $version) {
    if (!\WPLite\Environment::sqliteSupported($version)) {
        throw new RuntimeException('Expected supported version: ' . $version);
    }
}
foreach (['3.37.0', '3.45.2', '3.51.2', '3.52.0', 'latest', "3.53.4\n"] as $version) {
    if (\WPLite\Environment::sqliteSupported($version)) {
        throw new RuntimeException('Expected unsupported version: ' . $version);
    }
}
if (!\WPLite\Environment::isWithin('/site/data', '/site')
    || \WPLite\Environment::isWithin('/site-other', '/site')) {
    throw new RuntimeException('Public-root boundary failed.');
}
echo "Environment policy checks passed.\n";
