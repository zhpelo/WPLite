<?php
declare(strict_types=1);
define('ABSPATH', '/temporary-fixture/');
require dirname(__DIR__) . '/wp-content/mu-plugins/wplite/copy-configuration.php';
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$source = "<?php\ndefine('DB_NAME', 'original_database');\ndefine('DB_USER', 'original_user');\ndefine('DB_PASSWORD', 'secret-database-password');\ndefine('DB_HOST', 'private-db.example');\n";
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $salt) {
    $source .= 'define(' . var_export($salt, true) . ', ' . var_export("salts\\'\"中文$", true) . ");\n";
}
$source .= "define('WP_DEBUG', false);\ndefine('WP_MEMORY_LIMIT', '256M');\n\$table_prefix = 'custom_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
$result = \WPLite\CopyConfiguration::render($source, 'custom_', 'wplite-data-0123456789abcdef');
foreach (['original_database', 'original_user', 'secret-database-password', 'private-db.example'] as $secret) { check(!str_contains($result, $secret), 'source connection leaked'); }
check(str_contains($result, "define('DB_ENGINE', 'sqlite')"), 'missing SQLite binding');
check(str_contains($result, "define('WP_MEMORY_LIMIT', '256M')"), 'custom scalar lost');
check(str_contains($result, var_export("salts\\'\"中文$", true)), 'salt changed');
check(str_contains($result, "\$table_prefix = 'custom_'"), 'prefix changed');
token_get_all($result, TOKEN_PARSE);
foreach ([
    str_replace("'256M'", "getenv('MEMORY')", $source),
    str_replace("require_once ABSPATH . 'wp-settings.php';", "include '/another/config.php';", $source),
    str_replace("define('WP_DEBUG', false);", "define('WP_DEBUG', false); define('WP_DEBUG', true);", $source),
    str_replace("define('WP_DEBUG', false);", "define('WP_CONTENT_DIR', '/custom');", $source),
    str_replace("define('WP_DEBUG', false);", "define('MULTISITE', true);", $source),
    str_replace("\$table_prefix = 'custom_'", "\$table_prefix = 'wrong_'", $source),
] as $bad) {
    try { \WPLite\CopyConfiguration::render($bad, 'custom_', 'wplite-data-0123456789abcdef'); throw new LogicException('unsafe configuration accepted'); }
    catch (RuntimeException) {}
}
$directory = sys_get_temp_dir() . '/wplite-config-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
try {
    $bad = str_replace("define('WP_DEBUG', false);", 'file_put_contents(' . var_export($directory . '/executed', true) . ", 'bad');", $source);
    try { \WPLite\CopyConfiguration::render($bad, 'custom_', 'wplite-data-0123456789abcdef'); throw new LogicException('code accepted'); }
    catch (RuntimeException) {}
    check(!file_exists($directory . '/executed'), 'source code executed');
} finally { rmdir($directory); }
echo "PASS: static configuration, salts, credentials removal, dynamic-code rejection\n";
