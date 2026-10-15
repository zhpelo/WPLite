<?php
declare(strict_types=1);
require dirname(__DIR__) . '/plugin/wp-content/mu-plugins/wplite/environment.php';

foreach (['3.44.6', '3.50.7', '3.51.3', '3.53.4'] as $version) {
    if (!\WPLite\Environment::sqliteSupported($version)) {
        throw new RuntimeException('Expected supported SQLite: ' . $version);
    }
}
foreach (['3.37.0', '3.45.2', '3.51.2', '3.52.0', 'latest', "3.53.4\n"] as $version) {
    if (\WPLite\Environment::sqliteSupported($version)) {
        throw new RuntimeException('Expected unsupported SQLite: ' . $version);
    }
}
if (!\WPLite\Environment::isWithin('/site/data', '/site')
    || \WPLite\Environment::isWithin('/site-other', '/site')) {
    throw new RuntimeException('Public-root boundary failed.');
}
\WPLite\Environment::requireSupported();
echo "PASS: PHP/PDO SQLite policy and path boundaries\n";
