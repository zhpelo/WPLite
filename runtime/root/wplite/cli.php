<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/src/Environment.php';

/** Return a named CLI argument without accepting duplicate/unknown flags. */
function wpliteArguments(array $arguments, ?array $accepted = null): array
{
    $accepted ??= ['url', 'title', 'admin-user', 'admin-email', 'password-env', 'data-dir', 'public-root', 'table-prefix'];
    $result = [];
    for ($i = 0; $i < count($arguments); ++$i) {
        if (!preg_match('/^--([a-z-]+)$/D', $arguments[$i], $match)
            || !in_array($match[1], $accepted, true) || isset($result[$match[1]])
            || !isset($arguments[$i + 1]) || str_starts_with($arguments[$i + 1], '--')) {
            throw new RuntimeException('Unknown, repeated or incomplete CLI argument.');
        }
        $result[$match[1]] = $arguments[++$i];
    }
    return $result;
}

try {
    $command = $argv[1] ?? 'doctor';
    if ($command === 'doctor') {
        $report = \WPLite\Environment::inspect();
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($report['ok'] ? 0 : 1);
    }
    if ($command === 'backup-create' || $command === 'backup-verify') {
        require_once __DIR__ . '/src/Backup.php';
        if ($command === 'backup-verify') {
            $options = wpliteArguments(array_slice($argv, 2), ['backup-dir', 'data-dir']);
            if (empty($options['backup-dir']) || empty($options['data-dir'])) {
                throw new RuntimeException('backup-verify requires --backup-dir and the original private --data-dir.');
            }
            $state = \WPLite\Environment::readState($options['data-dir']);
            $report = \WPLite\Backup::verify($options['backup-dir'], \WPLite\Backup::backupKey($state), $state['site_id']);
        } else {
            $options = wpliteArguments(array_slice($argv, 2), ['output-dir']);
            require dirname(__DIR__) . '/wp-load.php';
            $report = \WPLite\Backup::create($GLOBALS['wpdb']->get_driver()->get_sqlite_pdo(),
                ABSPATH, dirname(DB_PATH), WPLITE_SITE_ID, $GLOBALS['table_prefix'],
                $options['output-dir'] ?? null, defined('WPLITE_PUBLIC_ROOT') ? WPLITE_PUBLIC_ROOT : ABSPATH);
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    if (in_array($command, ['restore', 'restore-status', 'restore-rollback', 'restore-complete'], true)) {
        require_once __DIR__ . '/src/Restore.php';
        $options = wpliteArguments(array_slice($argv, 2), $command === 'restore' ? ['data-dir', 'backup-dir'] : ['data-dir']);
        if (empty($options['data-dir']) || ($command === 'restore' && empty($options['backup-dir']))) {
            throw new RuntimeException('Recovery requires the private --data-dir, and restore also requires --backup-dir.');
        }
        $report = match ($command) {
            'restore' => \WPLite\Restore::restore(dirname(__DIR__), $options['data-dir'], $options['backup-dir']),
            'restore-status' => \WPLite\Maintenance::status($options['data-dir']),
            'restore-rollback' => \WPLite\Restore::rollback(dirname(__DIR__), $options['data-dir']),
            'restore-complete' => \WPLite\Restore::complete(dirname(__DIR__), $options['data-dir']),
        };
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    if ($command !== 'install') {
        throw new RuntimeException('Commands: doctor, install, backup-create, backup-verify, restore, restore-status, restore-rollback, restore-complete.');
    }
    \WPLite\Environment::requireSupported();
    $options = wpliteArguments(array_slice($argv, 2));
    foreach (['url', 'title', 'admin-user', 'admin-email', 'data-dir'] as $required) {
        if (empty($options[$required])) {
            throw new RuntimeException('Required option: --' . $required);
        }
    }
    $url = rtrim($options['url'], '/');
    $parsed = parse_url($url);
    if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || empty($parsed['host'])
        || isset($parsed['query']) || isset($parsed['fragment']) || isset($parsed['user']) || isset($parsed['pass'])) {
        throw new RuntimeException('Use an absolute HTTP(S) site URL without credentials, query or fragment.');
    }
    if (!filter_var($options['admin-email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid administrator email address.');
    }
    $passwordKey = $options['password-env'] ?? 'WPLITE_ADMIN_PASSWORD';
    $password = getenv($passwordKey);
    if ($password === false || strlen($password) < 12) {
        throw new RuntimeException('Supply an administrator password of at least 12 bytes through the password environment variable.');
    }
    $prefix = $options['table-prefix'] ?? 'wp_';
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
        throw new RuntimeException('Table prefix must contain only letters, numbers and underscores.');
    }
    $root = dirname(__DIR__);
    $publicRoot = realpath($options['public-root'] ?? $root);
    if ($publicRoot === false || !\WPLite\Environment::isWithin($root, $publicRoot)) {
        throw new RuntimeException('--public-root must identify the real public document root containing this installation.');
    }
    $data = $options['data-dir'];
    if (!str_starts_with($data, '/') || str_contains($data, "\0") || in_array('..', explode('/', $data), true)) {
        throw new RuntimeException('Use an absolute data directory without parent traversal.');
    }
    $parent = realpath(dirname($data));
    if ($parent === false || \WPLite\Environment::isWithin($parent . '/' . basename($data), $publicRoot)) {
        throw new RuntimeException('The data directory must have an existing parent outside the public document root.');
    }
    $data = $parent . '/' . basename($data);
    $lock = fopen(__DIR__ . '/.install.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another installation is running.');
    }
    if (file_exists($root . '/wp-config.php') || file_exists($data) || is_link($data)) {
        throw new RuntimeException('Refusing to overwrite an existing configuration or data directory.');
    }
    if (!mkdir($data, 0700)) {
        throw new RuntimeException('Cannot create the private data directory.');
    }
    $siteId = bin2hex(random_bytes(24));
    $database = $data . '/site.sqlite';
    $siteState = ['site_id' => $siteId, 'status' => 'installing', 'database' => $database,
        'root' => $root, 'public_root' => $publicRoot, 'table_prefix' => $prefix];
    \WPLite\Environment::writeState($data, $siteState);
    $config = "<?php\n// Generated by WPLite. Keep this file private.\n";
    $constants = ['DB_ENGINE' => 'sqlite', 'DB_NAME' => 'wplite', 'DB_PATH' => $database,
        'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '', 'DB_CHARSET' => 'utf8mb4',
        'DB_COLLATE' => '', 'WPLITE_SITE_ID' => $siteId, 'WP_HOME' => $url, 'WP_SITEURL' => $url,
        'WPLITE_PUBLIC_ROOT' => $publicRoot,
        'WP_DEBUG' => false, 'WP_DEBUG_DISPLAY' => false, 'WP_AUTO_UPDATE_CORE' => false];
    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $salt) {
        $constants[$salt] = bin2hex(random_bytes(48));
    }
    foreach ($constants as $key => $value) {
        $config .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
    }
    $config .= '$table_prefix = ' . var_export($prefix, true) . ";\n";
    $config .= "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n";
    $config .= "require __DIR__ . '/wplite/bootstrap.php';\nrequire_once ABSPATH . 'wp-settings.php';\n";
    $configFile = fopen($root . '/wp-config.php', 'x');
    if ($configFile === false) {
        throw new RuntimeException('Cannot create wp-config.php.');
    }
    chmod($root . '/wp-config.php', 0600);
    if (fwrite($configFile, $config) !== strlen($config)) {
        fclose($configFile);
        throw new RuntimeException('Could not write the complete configuration. Restore before retrying.');
    }
    fclose($configFile);
    define('WPLITE_INSTALL_AUTHORIZED', true);
    define('WP_INSTALLING', true);
    $_SERVER['HTTP_HOST'] = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $_SERVER['REQUEST_URI'] = ($parsed['path'] ?? '') . '/wp-admin/install.php';
    $_SERVER['HTTPS'] = $parsed['scheme'] === 'https' ? 'on' : '';
    require $root . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $result = wp_install($options['title'], $options['admin-user'], $options['admin-email'], true, '', $password);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    update_option('wplite_site_id', $siteId);
    $siteState['status'] = 'installed';
    \WPLite\Environment::writeState($data, $siteState);
    flock($lock, LOCK_UN);
    fclose($lock);
    echo json_encode(['status' => 'installed', 'url' => $url, 'admin_user' => $options['admin-user']], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'WPLite: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
