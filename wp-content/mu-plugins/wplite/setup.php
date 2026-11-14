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
        $origin = ($https ? 'https://' : 'http://') . $host;
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (!str_ends_with($script, '/wp-admin/setup-config.php') || preg_match('/[\x00-\x20\\\\?#]/', $script)) {
            throw new \RuntimeException('请通过 WordPress 官方配置入口访问。');
        }
        return [$origin, substr($script, 0, -strlen('/wp-admin/setup-config.php'))];
    }

    public static function createDenyRule(string $data): void
    {
        self::fresh($data . '/.htaccess', self::DENY);
    }

    public static function prepare(string $root, array $input): void
    {
        Environment::requireSupported();
        $public = @realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($public === false || !Environment::isWithin($root, $public) || is_link($root . '/wp-content')
            || !is_file($root . '/wp-settings.php') || !is_dir($root . '/wp-admin')) {
            throw new \RuntimeException('无法确认官方 WordPress 目录及真实 Web 根目录；未修改核心文件。');
        }
        foreach ([$root . '/wp-config.php', dirname($root) . '/wp-config.php', $root . '/wplite'] as $existing) {
            if (@file_exists($existing) || @is_link($existing)) { throw new \RuntimeException('仅支持全新站点：已有配置或数据库 drop-in，不会覆盖。'); }
        }
        $dropin = $root . '/wp-content/db.php';
        if (!is_file(__DIR__ . '/vendor/sqlite/wp-includes/sqlite/db.php') || !is_file($dropin) || is_link($dropin)
            || !str_contains((string) file_get_contents($dropin), 'WPLITE_SQLITE_DROPIN_V3')) {
            throw new \RuntimeException('安装文件不完整。请上传已包含 SQLite 驱动的完整安装包，无需运行 Python 或服务器下载。');
        }
        // Storage is automatic; never accept a filesystem path from the browser.
        $data = $root . '/wp-content/wplite-data-' . bin2hex(random_bytes(8));
        if (@file_exists($data) || @is_link($data)) {
            throw new \RuntimeException('数据库目录必须是新的、未被已有数据占用的目录。');
        }
        // A single exclusive claim prevents concurrent/repeated partial installs.
        // Failed attempts are inspected via the hosting file manager, not resumed.
        self::fresh($root . '/wplite-setup.lock.php', "<?php http_response_code(404); exit;\n");
        if (!@mkdir($data, 0700)) { throw new \RuntimeException('PHP 无权创建数据库目录。请通过主机面板检查权限及安装锁。'); }
        self::createDenyRule($data);
        $oldMask = umask(0077);
        try {
            $pdo = new \PDO('sqlite:' . $data . '/site.sqlite');
            $pdo->exec('PRAGMA user_version = 0');
            $pdo = null;
            if (!chmod($data . '/site.sqlite', 0600)) { throw new \RuntimeException('无法保护数据库文件权限。'); }
        } finally { umask($oldMask); }
        $constants = ['DB_ENGINE' => 'sqlite', 'DB_PATH' => $data . '/site.sqlite', 'DB_NAME' => 'wplite',
            'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '', 'DB_CHARSET' => 'utf8mb4',
            'DB_COLLATE' => '', 'WPLITE_PUBLIC_ROOT' => $public];
        $constants['WPLITE_STORAGE_DENY_HASH'] = hash('sha256', self::DENY);
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $constants[$name] = bin2hex(random_bytes(32));
        }
        $config = self::configSource($constants, 'wp_');
        self::fresh($root . '/wp-config.php', $config);
    }

    public static function configSource(array $constants, string $prefix): string
    {
        $database = $constants['DB_PATH'] ?? '';
        $directory = is_string($database) ? basename(dirname($database)) : '';
        if (!preg_match('/^wplite-data-[a-f0-9]{16}$/D', $directory)
            || !preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            throw new \RuntimeException('SQLite 数据库位置或表前缀不符合标准配置。');
        }
        $code = "<?php\n// WPLite; WordPress core is unmodified.\n";
        $code .= "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n";
        foreach ($constants as $name => $value) {
            $isSalt = in_array($name, ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
                'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'], true);
            if ($name === 'DB_PATH') { $expression = 'ABSPATH . ' . var_export('wp-content/' . $directory . '/site.sqlite', true); }
            elseif ($isSalt) { $expression = var_export($value, true); }
            elseif ($name === 'WPLITE_PUBLIC_ROOT') { $expression = 'ABSPATH'; }
            elseif (is_string($value) && str_starts_with($value, ABSPATH)) {
                $expression = 'ABSPATH . ' . var_export(substr($value, strlen(ABSPATH)), true);
            }
            elseif (is_string($value) && str_starts_with($value, '/')) {
                throw new \RuntimeException('原配置包含站点目录外的绝对路径：' . $name);
            }
            else { $expression = var_export($value, true); }
            $code .= 'define(' . var_export($name, true) . ', ' . $expression . ");\n";
        }
        $code .= '$table_prefix = ' . var_export($prefix, true) . ";\n";
        $code .= "require_once ABSPATH . 'wp-content/mu-plugins/wplite/bootstrap.php';\nrequire_once ABSPATH . 'wp-settings.php';\n";
        return $code;
    }

    public static function render(): void
    {
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $error = null;
        try {
            $root = rtrim(ABSPATH, '/');
            [$origin, $prefix] = self::address();
            $url = $origin . $prefix;
            if (is_file($root . '/wp-config.php') || is_link($root . '/wp-config.php')
                || @is_file(dirname($root) . '/wp-config.php') || @is_link(dirname($root) . '/wp-config.php')) {
                http_response_code(409);
                throw new \RuntimeException('已有 WordPress 配置，不会覆盖。请继续访问 install.php 完成安装，或登录已有站点。');
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                // MU loading precedes wp_magic_quotes(); these are raw values.
                $input = $_POST;
                foreach ($input as $value) {
                    if (!is_string($value) || strlen($value) > 4096) { throw new \RuntimeException('无效的表单数据。'); }
                }
                $engine = $input['database'] ?? 'sqlite';
                if ($engine === 'mysql') {
                    header('Location: ' . $url . '/wp-admin/setup-config.php?step=1', true, 303);
                    return;
                }
                if ($engine !== 'sqlite') { throw new \RuntimeException('请选择 SQLite 或 MySQL。'); }
                self::prepare($root, $input);
                header('Location: ' . $url . '/wp-admin/install.php', true, 303);
                return;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
                http_response_code(405);
                throw new \RuntimeException('仅接受 GET / POST。');
            }
        } catch (\Throwable $exception) {
            if (http_response_code() < 400) { http_response_code(400); }
            $error = $exception->getMessage();
        }
        ?>
<!doctype html>
<html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WordPress 数据库配置</title>
<style>body{font:16px/1.65 system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 20px;color:#17212d}input,select,button{box-sizing:border-box;width:100%;padding:10px;margin:5px 0 14px;font:inherit}.notice{padding:14px;background:#eef4fa}button{background:#235a80;color:white;border:0;cursor:pointer}</style>
<h1>WordPress 数据库配置</h1>
<?php if ($error !== null): ?>
<p class="notice"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
<p>如已开始写入后失败，请通过主机面板检查本次新建文件和安装锁；不会自动覆盖或删除已有数据。</p>
<?php else: ?>
<p>默认使用 SQLite，无需数据库账号，存储位置自动设置。也可以选择 MySQL，填写已有数据库的连接信息。配置完成后，将进入 WordPress 官方安装页面设置站点和管理员。</p>
<p class="notice">此入口公开访问，请上传后立即完成全部安装步骤。建议使用 HTTPS。服务器必须禁止下载 SQLite 数据库目录中的文件；WPLite 不再自动验证此项。</p>
<form method="post">
<label>数据库类型<select name="database"><option value="sqlite" selected>SQLite（推荐，无需单独的数据库服务）</option><option value="mysql">MySQL</option></select></label>
<button type="submit">继续安装 WordPress</button>
</form>
<?php endif; ?>
</html>
        <?php
    }
}
