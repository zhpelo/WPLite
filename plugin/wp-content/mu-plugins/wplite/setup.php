<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/environment.php';

/** First installation only. Never included by normal WordPress requests. */
final class Setup
{
    public const DENY = "Require all denied\n";

    public static function fresh(string $path, string $contents, int $mode = 0600): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) { throw new \RuntimeException('无法新建文件；不会覆盖已有文件。'); }
        try {
            if (!chmod($path, $mode) || fwrite($handle, $contents) !== strlen($contents)) {
                throw new \RuntimeException('文件写入或权限设置失败，请通过主机面板检查本次未完成安装。');
            }
        } finally { fclose($handle); }
    }

    public static function address(): array
    {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/D', $host)) {
            throw new \RuntimeException('无法确认网站地址。');
        }
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $hostname = parse_url('http://' . $host, PHP_URL_HOST);
        if (!$https && !in_array($hostname, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new \RuntimeException('安装必须使用 HTTPS。请先在主机面板启用 HTTPS；不信任客户端转发头。');
        }
        $origin = ($https ? 'https://' : 'http://') . $host;
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (!str_ends_with($script, '/wplite-setup.php') || preg_match('/[\x00-\x20\\\\?#]/', $script)) {
            throw new \RuntimeException('请直接打开上传的 wplite-setup.php。');
        }
        return [$origin, rtrim(dirname($script), '/')];
    }

    private static function get(string $url): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5,
            'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true,
            'header' => "Connection: close\r\nCache-Control: no-cache\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $response = @file_get_contents($url, false, $context, 0, 4096);
        if ($response === false || !isset($http_response_header[0])
            || !preg_match('~^HTTP/\S+ ([0-9]{3})~', $http_response_header[0], $match)) {
            throw new \RuntimeException('主机不能完成本站 HTTP(S) 安全验证。请使用网站公开目录之外的私有数据库路径。');
        }
        return [(int) $match[1], $response];
    }

    private static function protectedStorage(string $root, string $data, string $url): void
    {
        // Fresh challenges, not real data: prove the URL maps to this site and
        // the actual sensitive names are denied before materializing SQLite.
        self::fresh($data . '/.htaccess', self::DENY);
        $token = bin2hex(random_bytes(24));
        $control = 'wplite-probe-' . bin2hex(random_bytes(8)) . '.txt';
        $paths = ['site.sqlite', 'site.sqlite-wal', 'site.sqlite-shm', '.ht.sqlite.lock', '.ht.sqlite.maintenance'];
        $created = [];
        try {
            self::fresh($root . '/' . $control, $token, 0644);
            $created[] = $root . '/' . $control;
            [$status, $body] = self::get($url . '/' . $control);
            if ($status !== 200 || $body !== $token) { throw new \RuntimeException('本站公开控制验证失败，尚未创建数据库。'); }
            $relative = '/wp-content/' . rawurlencode(basename($data));
            foreach ($paths as $path) {
                self::fresh($data . '/' . $path, $token);
                $created[] = $data . '/' . $path;
                [$status, $body] = self::get($url . $relative . '/' . rawurlencode($path));
                if (!in_array($status, [403, 404], true) || str_contains($body, $token)) {
                    throw new \RuntimeException('服务器没有可靠禁止下载数据库。已拒绝安装；请改用私有目录或由主机配置访问禁止规则。');
                }
            }
        } finally {
            foreach ($created as $file) {
                if (!@unlink($file)) { throw new \RuntimeException('安全验证文件清理失败，停止安装。'); }
            }
        }
    }

    public static function prepare(string $root, array $input, string $url): string
    {
        Environment::requireSupported();
        $public = @realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($public === false || !Environment::isWithin($root, $public) || is_link($root . '/wp-content')
            || !is_file($root . '/wp-settings.php') || !is_dir($root . '/wp-admin')) {
            throw new \RuntimeException('无法确认官方 WordPress 目录及真实 Web 根目录；未修改核心文件。');
        }
        foreach ([$root . '/wp-config.php', dirname($root) . '/wp-config.php', $root . '/wplite', $root . '/wp-content/db.php'] as $existing) {
            if (@file_exists($existing) || @is_link($existing)) { throw new \RuntimeException('仅支持全新站点：已有配置或数据库 drop-in，不会覆盖。'); }
        }
        if (!is_file(__DIR__ . '/vendor/sqlite/wp-includes/sqlite/db.php') || !is_file(__DIR__ . '/db-template.php')) {
            throw new \RuntimeException('安装文件不完整。请上传已包含 SQLite 驱动的完整安装包，无需运行 Python 或服务器下载。');
        }
        $title = $input['title'] ?? '';
        $user = $input['admin-user'] ?? '';
        $email = $input['admin-email'] ?? '';
        $password = $input['admin-password'] ?? '';
        $mode = $input['storage'] ?? 'auto';
        $requested = trim($input['data-dir'] ?? '');
        if ($title === '' || strlen($title) > 200 || !preg_match('/^[a-zA-Z0-9_.@-]{1,60}$/D', $user)
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
            || strlen($password) < 12 || strlen($password) > 200 || !in_array($mode, ['auto', 'private', 'protected'], true)) {
            throw new \RuntimeException('请填写站点标题、有效管理员账号/邮箱，以及至少 12 字节的管理员密码。');
        }
        $parent = @realpath(dirname($public));
        if ($requested !== '') {
            $parent = @realpath(dirname($requested));
            $leaf = basename($requested);
            if ($requested[0] !== '/' || in_array($leaf, ['.', '..'], true)) { throw new \RuntimeException('私有目录必须是绝对路径。'); }
            $data = ($parent ?: '') . '/' . $leaf;
            $mode = 'private';
        } elseif ($mode !== 'protected' && $parent !== false && @is_writable($parent)) {
            $data = $parent . '/wplite-data-' . bin2hex(random_bytes(8));
            $mode = 'private';
        } elseif ($mode === 'private') {
            throw new \RuntimeException('网站根目录外不可写，请通过面板指定可写私有目录，或选择验证过的站内存储。');
        } else {
            $data = $root . '/wp-content/wplite-data-' . bin2hex(random_bytes(8));
            $mode = 'protected';
        }
        if ($parent === false && $mode === 'private'
            || $mode === 'private' && Environment::isWithin($data, $public)
            || @file_exists($data) || @is_link($data)) {
            throw new \RuntimeException('数据库目录必须是新的、未被公开或已有数据占用的目录。');
        }
        // A single exclusive claim prevents concurrent/repeated partial installs.
        // Failed attempts are inspected via the hosting file manager, not resumed.
        self::fresh($root . '/wplite-setup.lock.php', "<?php http_response_code(404); exit;\n");
        if (!@mkdir($data, 0700)) { throw new \RuntimeException('PHP 无权创建数据库目录。请通过主机面板检查权限及安装锁。'); }
        if ($mode === 'protected') { self::protectedStorage($root, $data, $url); }
        $oldMask = umask(0077);
        try {
            $pdo = new \PDO('sqlite:' . $data . '/site.sqlite');
            $pdo->exec('PRAGMA user_version = 0');
            $pdo = null;
            if (!chmod($data . '/site.sqlite', 0600)) { throw new \RuntimeException('无法保护数据库文件权限。'); }
        } finally { umask($oldMask); }
        self::fresh($root . '/wp-content/db.php', (string) file_get_contents(__DIR__ . '/db-template.php'), 0644);
        $constants = ['DB_ENGINE' => 'sqlite', 'DB_PATH' => $data . '/site.sqlite', 'DB_NAME' => 'wplite',
            'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '', 'DB_CHARSET' => 'utf8mb4',
            'DB_COLLATE' => '', 'WPLITE_PUBLIC_ROOT' => $public];
        if ($mode === 'protected') { $constants['WPLITE_STORAGE_DENY_HASH'] = hash('sha256', self::DENY); }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $constants[$name] = bin2hex(random_bytes(32));
        }
        $config = "<?php\n// WPLite SQLite; WordPress core is unmodified.\n";
        foreach ($constants as $name => $value) {
            define($name, $value);
            $config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
        }
        $config .= "\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n";
        $config .= "require_once ABSPATH . 'wp-content/mu-plugins/wplite/bootstrap.php';\nrequire_once ABSPATH . 'wp-settings.php';\n";
        return $config;
    }
}
