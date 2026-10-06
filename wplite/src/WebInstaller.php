<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/Installer.php';

/** No ambient-cookie authentication: every install POST needs the owner secret. */
final class WebInstaller
{
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function authorize(string $root, array $post): void
    {
        $keyFile = $root . '/wplite/setup-key.php';
        if (!is_file($keyFile) || is_link($keyFile) || filesize($keyFile) > 4096
            || filemtime($keyFile) < time() - 3600 || filemtime($keyFile) > time() + 60) {
            throw new \RuntimeException('Upload a fresh owner setup-key.php file; setup keys expire after one hour.');
        }
        if (!defined('WPLITE_SETUP_READING')) {
            define('WPLITE_SETUP_READING', true);
        }
        $key = require $keyFile;
        $secret = $post['setup-key'] ?? '';
        if (!is_array($key) || !is_string($key['sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $key['sha256']) || !is_string($secret)
            || !preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $secret)
            || !hash_equals($key['sha256'], hash('sha256', $secret))) {
            throw new \RuntimeException('Owner setup key is invalid.');
        }
    }

    private static function origin(string $root, array $server, string $url): array
    {
        $tls = in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['on', '1'], true);
        $host = $server['HTTP_HOST'] ?? '';
        if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9a-fA-F:]+\])(?::[0-9]{1,5})?$/D', $host)) {
            throw new \RuntimeException('Invalid request host.');
        }
        $scheme = $tls ? 'https' : 'http';
        $origin = $scheme . '://' . strtolower($host);
        $parsed = parse_url($origin);
        $local = in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            && in_array(strtolower($parsed['host'] ?? ''), ['127.0.0.1', '[::1]', 'localhost'], true);
        if (!$tls && !$local) {
            throw new \RuntimeException('HTTPS is required for Web installation. Untrusted proxy headers are not used as proof of TLS.');
        }
        $public = realpath($server['DOCUMENT_ROOT'] ?? '');
        if ($public === false || !Environment::isWithin($root, $public)) {
            throw new \RuntimeException('The server must expose the actual DOCUMENT_ROOT. Aliased installations require explicit server configuration.');
        }
        $prefix = substr($root, strlen(rtrim($public, '/')));
        if (($server['SCRIPT_NAME'] ?? '') !== $prefix . '/wplite/install.php'
            || $url !== $origin . $prefix) {
            throw new \RuntimeException('Site URL must exactly match this installer origin and installation path.');
        }
        if (isset($server['HTTP_ORIGIN']) && strtolower($server['HTTP_ORIGIN']) !== $origin) {
            throw new \RuntimeException('Cross-origin installation requests are refused.');
        }
        return [$origin, $public];
    }

    public static function handle(string $root): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        if (file_exists($root . '/wplite/site-config.php') || is_link($root . '/wplite/site-config.php')) {
            http_response_code(410);
            echo 'WPLite installation is closed. Existing or incomplete installations are never overwritten.';
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384 || !empty($_GET)) {
                    throw new \RuntimeException('Use a small form POST without query parameters.');
                }
                self::authorize($root, $_POST);
            } catch (\Throwable $error) {
                http_response_code(403);
                self::page($error->getMessage());
                return;
            }
            try {
                $options = [];
                foreach (['url', 'title', 'admin-user', 'admin-email', 'data-dir', 'table-prefix'] as $name) {
                    if (!is_string($_POST[$name] ?? null)) {
                        throw new \RuntimeException('Missing or invalid installation field: ' . $name);
                    }
                    $options[$name] = trim($_POST[$name]);
                }
                [$origin, $public] = self::origin($root, $_SERVER, $options['url']);
                $options['public-root'] = $public;
                if ($options['data-dir'] === '') {
                    $options['data-dir'] = $root . '/.wplite-data';
                }
                $password = $_POST['admin-password'] ?? '';
                if (!is_string($password)) {
                    throw new \RuntimeException('Invalid administrator password.');
                }
                // WordPress may emit installation notices; never leak a key,
                // raw SQL, private path or half-built success page to the browser.
                ob_start();
                try {
                    $result = Installer::install($root, $options, $password, $origin);
                } finally {
                    ob_end_clean();
                }
                if (!unlink($root . '/wplite/setup-key.php')) {
                    error_log('WPLite: remove the used setup-key.php file through the hosting file manager.');
                }
                http_response_code(201);
                echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>WPLite 安装完成</title>';
                echo '<h1>WPLite 安装完成</h1><p>SQLite 已启用。安装入口已关闭。</p><p><a href="'
                    . self::escape($result['url'] . '/wp-login.php') . '">登录网站</a></p></html>';
                return;
            } catch (\Throwable $error) {
                http_response_code(400);
                error_log('WPLite setup: ' . $error->getMessage());
                self::page($error->getMessage());
                return;
            }
        }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD, POST');
            return;
        }
        self::page();
    }

    private static function page(string $error = ''): void
    {
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>WPLite · SQLite 安装</title><style>body{font:16px/1.65 system-ui,sans-serif;max-width:720px;margin:48px auto;padding:0 24px;color:#18202b}label{display:block;margin:16px 0}input{box-sizing:border-box;display:block;width:100%;padding:10px;font:inherit}button{padding:12px 24px;font:inherit;background:#205a46;color:white;border:0;border-radius:5px}.error{padding:12px;background:#fff0ed}small{color:#505c68}</style>';
        echo '<h1>WPLite · SQLite 安装</h1><p>无需 MySQL。请先通过主机文件管理器上传一次性 <code>wplite/setup-key.php</code>，并在一小时内完成安装。请使用 HTTPS。</p>';
        echo '<p><strong>开发版本，不用于真实商店。</strong>优先填写公开目录之外的绝对数据路径；留空会检查受保护的 <code>.wplite-data</code> 目录。HTTP 保护未经证实时不会创建数据库。</p>';
        if ($error !== '') {
            echo '<p role="alert" class="error">' . self::escape($error) . '</p>';
        }
        echo '<form method="post" autocomplete="off">';
        foreach ([['setup-key', '所有者安装密钥', 'password'], ['url', '完整站点 URL（与当前域名和路径一致）', 'url'],
            ['title', '站点标题', 'text'], ['admin-user', '管理员用户名', 'text'], ['admin-email', '管理员邮箱', 'email'],
            ['admin-password', '管理员密码（至少 12 字节）', 'password'], ['data-dir', '数据目录绝对路径（推荐私有目录；留空检查公开目录保护）', 'text'],
            ['table-prefix', '数据库表前缀', 'text']] as [$name, $label, $type]) {
            echo '<label>' . self::escape($label) . '<input name="' . $name . '" type="' . $type . '"'
                . ($name === 'data-dir' ? '' : ' required') . ($name === 'table-prefix' ? ' value="wp_"' : '') . '></label>';
        }
        echo '<button type="submit">检查环境并安装 SQLite 网站</button></form><p><small>密钥及密码不会写入 URL 或回显；已有配置和数据不会被覆盖。配置 Nginx 等服务器时，必须拒绝整个数据目录及所有子目录。</small></p></html>';
    }
}
