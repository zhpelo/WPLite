<?php
declare(strict_types=1);

// One-time, local CLI helper for NEW sites. Not a Web installer or updater.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/plugin/wp-content/mu-plugins/wplite/environment.php';

function freshFile(string $path, string $contents, int $mode): void
{
    $handle = fopen($path, 'x');
    if ($handle === false) { throw new RuntimeException('Refusing to overwrite: ' . $path); }
    try {
        chmod($path, $mode);
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException('Incomplete setup write: ' . $path);
        }
    } finally { fclose($handle); }
}

try {
    $options = [];
    for ($index = 1; $index < $argc; $index += 2) {
        $name = $argv[$index];
        if (!in_array($name, ['--package', '--wordpress-root', '--public-root', '--data-dir'], true)
            || isset($options[$name]) || !isset($argv[$index + 1])) {
            throw new RuntimeException('Use --package DIR --wordpress-root DIR --public-root DIR --data-dir NEW-PRIVATE-DIR.');
        }
        $options[$name] = $argv[$index + 1];
    }
    if (count($options) !== 4) { throw new RuntimeException('All four setup arguments are required.'); }
    \WPLite\Environment::requireSupported();
    $root = realpath($options['--wordpress-root']);
    $public = realpath($options['--public-root']);
    $package = realpath($options['--package']);
    $parent = realpath(dirname($options['--data-dir']));
    $leaf = basename($options['--data-dir']);
    if ($root === false || $public === false || $parent === false || $package === false
        || !\WPLite\Environment::isWithin($root, $public) || in_array($leaf, ['.', '..', ''], true)) {
        throw new RuntimeException('Use existing canonical WordPress/public/package/parent directories.');
    }
    $data = $parent . '/' . $leaf;
    if (\WPLite\Environment::isWithin($data, $public)) {
        throw new RuntimeException('The new database directory must be outside the public document root.');
    }
    foreach ([$root . '/wp-config.php', dirname($root) . '/wp-config.php', $root . '/wplite',
              $root . '/wp-content/db.php', $root . '/wp-content/mu-plugins/wplite.php',
              $root . '/wp-content/mu-plugins/wplite', $data] as $existing) {
        if (file_exists($existing) || is_link($existing)) {
            throw new RuntimeException('New sites only; refusing to overwrite an existing configuration, integration or data directory.');
        }
    }
    if (!is_file($root . '/wp-settings.php') || !is_dir($root . '/wp-admin')
        || !is_dir($root . '/wp-content') || is_link($root . '/wp-content')
        || is_link($root . '/wp-content/mu-plugins')
        || !is_file($package . '/wp-content/db.php')
        || !is_file($package . '/wp-content/mu-plugins/wplite/vendor/sqlite/wp-includes/sqlite/db.php')) {
        throw new RuntimeException('Expected an unconfigured official WordPress tree and a prepared WPLite package.');
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile()) { throw new RuntimeException('The package must contain regular files only.'); }
        $relative = substr($file->getPathname(), strlen($package) + 1);
        if ($relative !== 'wp-content/db.php' && !str_starts_with($relative, 'wp-content/mu-plugins/wplite/')
            && $relative !== 'wp-content/mu-plugins/wplite.php') {
            throw new RuntimeException('Unexpected package file: ' . $relative);
        }
        if (file_exists($root . '/' . $relative) || is_link($root . '/' . $relative)) {
            throw new RuntimeException('A package destination already exists.');
        }
        $files[$relative] = $file->getPathname();
    }
    // Fail before mutation if any source file cannot be read.
    foreach ($files as $source) {
        if (!is_readable($source)) { throw new RuntimeException('An integration source is unreadable.'); }
    }
    $previousMask = umask(0077);
    if (!mkdir($data, 0700)) { throw new RuntimeException('Cannot create the private database directory.'); }
    $pdo = new PDO('sqlite:' . $data . '/site.sqlite');
    $pdo->exec('PRAGMA user_version = 0'); // Materialize a valid empty DB, not a zero-byte placeholder.
    $pdo = null;
    chmod($data . '/site.sqlite', 0600);
    umask($previousMask);
    foreach ($files as $relative => $source) {
        $destination = $root . '/' . $relative;
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
            throw new RuntimeException('Cannot create the MU plugin directory.');
        }
        freshFile($destination, (string) file_get_contents($source), 0644);
    }
    $config = "<?php\n// WPLite SQLite configuration; WordPress core is unmodified.\n";
    foreach (['DB_ENGINE' => 'sqlite', 'DB_PATH' => $data . '/site.sqlite', 'DB_NAME' => 'wplite',
              'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '', 'DB_CHARSET' => 'utf8mb4',
              'DB_COLLATE' => '', 'WPLITE_PUBLIC_ROOT' => $public] as $name => $value) {
        $config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
    }
    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
              'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
        $config .= 'define(' . var_export($name, true) . ', ' . var_export(bin2hex(random_bytes(32)), true) . ");\n";
    }
    $config .= "\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n";
    $config .= "require_once ABSPATH . 'wp-content/mu-plugins/wplite/bootstrap.php';\n";
    $config .= "require_once ABSPATH . 'wp-settings.php';\n";
    freshFile($root . '/wp-config.php', $config, 0600);
    echo "Configured a new SQLite site. Complete installation using WordPress's own wp-admin/install.php.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WPLite setup: ' . $error->getMessage() . "\nNo existing site files are intentionally overwritten. Inspect any new partial setup before retrying.\n");
    exit(1);
}
