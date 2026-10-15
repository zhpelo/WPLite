<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/Storage.php';

/** One SQLite installer for CLI and authenticated Web requests. */
final class Installer
{
    public static function install(string $root, array $options, string $password, ?string $origin = null): array
    {
        Environment::requireSupported();
        foreach (['url', 'title', 'admin-user', 'admin-email', 'data-dir'] as $required) {
            if (!is_string($options[$required] ?? null) || trim($options[$required]) === '') {
                throw new \RuntimeException('Required option: ' . $required);
            }
        }
        $url = rtrim($options['url'], '/');
        $parsed = parse_url($url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || empty($parsed['host'])
            || preg_match('/[\x00-\x20\\\\]/', $url) || isset($parsed['query']) || isset($parsed['fragment'])
            || isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \RuntimeException('Use an absolute HTTP(S) site URL without credentials, query or fragment.');
        }
        if (!filter_var($options['admin-email'], FILTER_VALIDATE_EMAIL)
            || !preg_match('/^[A-Za-z0-9_.@-]{1,60}$/D', $options['admin-user'])) {
            throw new \RuntimeException('Invalid administrator email address or login name.');
        }
        if (strlen($password) < 12 || strlen($password) > 1024 || str_contains($password, "\0")) {
            throw new \RuntimeException('Supply an administrator password of 12 to 1024 bytes.');
        }
        $prefix = $options['table-prefix'] ?? 'wp_';
        if (!is_string($prefix) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $prefix)) {
            throw new \RuntimeException('Table prefix must contain 1 to 64 letters, numbers or underscores.');
        }
        [$public, $data] = Storage::location($root, $options['public-root'] ?? $root, $options['data-dir']);
        $lock = fopen($root . '/wplite/.install.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another installation is running.');
        }
        try {
            $manifest = json_decode(file_get_contents($root . '/wplite/distribution.json'), true, flags: JSON_THROW_ON_ERROR);
            $entry = $root . '/wp-config.php';
            $configPath = $root . '/wplite/site-config.php';
            if (!isset($manifest['files']['wp-config.php']) || !is_file($entry) || is_link($entry)
                || !hash_equals($manifest['files']['wp-config.php'], hash_file('sha256', $entry))
                || file_exists($configPath) || is_link($configPath)) {
                throw new \RuntimeException('Refusing to overwrite an existing configuration or altered entry point.');
            }
            $storage = Storage::prepare($root, $public, $data, $origin);
            $siteId = bin2hex(random_bytes(24));
            $database = $data . '/site.sqlite';
            $state = ['site_id' => $siteId, 'status' => 'installing', 'database' => $database,
                'root' => $root, 'public_root' => $public, 'table_prefix' => $prefix, 'storage' => $storage];
            Environment::writeState($data, $state);
            $constants = ['DB_ENGINE' => 'sqlite', 'DB_NAME' => 'wplite', 'DB_PATH' => $database,
                'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '', 'DB_CHARSET' => 'utf8mb4',
                'DB_COLLATE' => '', 'WPLITE_SITE_ID' => $siteId, 'WP_HOME' => $url, 'WP_SITEURL' => $url,
                'WPLITE_PUBLIC_ROOT' => $public, 'WP_DEBUG' => false, 'WP_DEBUG_DISPLAY' => false, 'WP_AUTO_UPDATE_CORE' => false];
            foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $salt) {
                $constants[$salt] = bin2hex(random_bytes(48));
            }
            $config = "<?php\n// Generated private site configuration; never distribute this file.\n";
            $config .= "if (!defined('WPLITE_CONFIG_LOADING')) { http_response_code(404); exit; }\n";
            foreach ($constants as $key => $value) {
                $config .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
            }
            $config .= '$table_prefix = ' . var_export($prefix, true) . ";\n";
            $config .= "if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }\n";
            $config .= "require __DIR__ . '/bootstrap.php';\nrequire_once ABSPATH . 'wp-settings.php';\n";
            $file = fopen($configPath, 'x');
            if ($file === false) {
                throw new \RuntimeException('Cannot create the private site configuration.');
            }
            try {
                chmod($configPath, 0600);
                if (fwrite($file, $config) !== strlen($config) || !fflush($file)
                    || (function_exists('fsync') && !fsync($file))) {
                    throw new \RuntimeException('Could not write the complete configuration. Inspect this incomplete install before retrying.');
                }
            } finally {
                fclose($file);
            }
            define('WPLITE_INSTALL_AUTHORIZED', true);
            define('WP_INSTALLING', true);
            $_SERVER['HTTP_HOST'] = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            $_SERVER['REQUEST_URI'] = ($parsed['path'] ?? '') . '/wplite/install.php';
            $_SERVER['HTTPS'] = $parsed['scheme'] === 'https' ? 'on' : '';
            require $root . '/wp-load.php';
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $result = wp_install($options['title'], $options['admin-user'], $options['admin-email'], true, '', $password);
            if (is_wp_error($result) || empty($result['user_id'])) {
                throw new \RuntimeException('WordPress could not create the administrator.');
            }
            update_option('wplite_site_id', $siteId);
            $state['status'] = 'installed';
            Environment::writeState($data, $state);
            return ['status' => 'installed', 'url' => $url, 'admin_user' => $options['admin-user'], 'storage' => $storage['mode']];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
