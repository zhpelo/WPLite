<?php
declare(strict_types=1);

// Upload-only new-site setup. Does not require a configured WordPress database.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/wp-content/mu-plugins/wplite/setup.php';

function wpliteEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$error = null;
$success = null;
try {
    if (is_file(__DIR__ . '/wp-config.php') || is_link(__DIR__ . '/wp-config.php')
        || @is_file(dirname(__DIR__) . '/wp-config.php') || @is_link(dirname(__DIR__) . '/wp-config.php')) {
        http_response_code(410);
        throw new RuntimeException('已有 WordPress 配置，安装入口已禁用，不会覆盖站点。');
    }
    [$origin, $prefix] = \WPLite\Setup::address();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        foreach ($_POST as $value) { if (!is_string($value) || strlen($value) > 4096) { throw new RuntimeException('无效的表单数据。'); } }
        $wpliteSetupUrl = $origin . $prefix;
        $wpliteSetupConfig = \WPLite\Setup::prepare(__DIR__, $_POST, $wpliteSetupUrl);
        // Load WordPress in GLOBAL scope, just like wp-config.php. Publish the
        // configuration only after the real administrator has been created.
        define('ABSPATH', __DIR__ . '/');
        define('WP_INSTALLING', true);
        $table_prefix = 'wp_';
        require_once __DIR__ . '/wp-content/mu-plugins/wplite/bootstrap.php';
        require ABSPATH . 'wp-settings.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $result = wp_install($_POST['title'], $_POST['admin-user'], $_POST['admin-email'], true, '', $_POST['admin-password']);
        if (empty($result['user_id'])) { throw new RuntimeException('WordPress 未能完成安装，请通过主机面板检查本次未完成安装。'); }
        update_option('siteurl', $wpliteSetupUrl);
        update_option('home', $wpliteSetupUrl);
        \WPLite\Setup::fresh(__DIR__ . '/wp-config.php', $wpliteSetupConfig);
        $success = $wpliteSetupUrl . '/wp-login.php';
        http_response_code(201);
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        throw new RuntimeException('仅接受 GET / POST。');
    }
} catch (Throwable $exception) {
    if (http_response_code() < 400) { http_response_code(400); }
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WPLite 安装</title>
<style>body{font:16px/1.65 system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;color:#17212d}input,select,button{box-sizing:border-box;width:100%;padding:10px;margin:5px 0 14px;font:inherit}code{overflow-wrap:anywhere}.notice{padding:14px;background:#eef4fa;border-left:4px solid #375e85}.error{border-color:#b42318}button{background:#235a80;color:white;border:0;cursor:pointer}small{color:#526171}</style>
<h1>WPLite 安装</h1>
<p>仅适用于全新 WordPress。只需上传文件和浏览器；无需终端、Python、Composer 或 MySQL。</p>
<?php if ($error !== null): ?>
<p class="notice error"><?= wpliteEscape($error) ?></p>
<p>如已开始写入后失败，请通过主机文件管理器检查本次新建文件。安装锁不会自动清除；不会自动覆盖、恢复或迁移已有数据。</p>
<?php elseif ($success !== null): ?>
<p class="notice">安装完成，管理员已创建。此入口已禁用。</p>
<p><a href="<?= wpliteEscape($success) ?>">登录 WordPress</a></p>
<p>请通过文件管理器删除 <code>wplite-setup.php</code>。保留安装锁即可。</p>
<?php else: ?>
<p class="notice">此安装入口对所有访客开放，请上传后立即完成安装。建议使用 HTTPS，避免明文传输管理员密码。</p>
<h2>创建 SQLite 站点</h2>
<form method="post" autocomplete="off">
<label>站点标题<input name="title" required maxlength="200"></label>
<label>管理员账号<input name="admin-user" required maxlength="60" autocomplete="username"></label>
<label>管理员邮箱<input name="admin-email" type="email" required maxlength="254"></label>
<label>管理员密码<input name="admin-password" type="password" required minlength="6" maxlength="200" autocomplete="new-password"></label>
<details><summary>数据库存储（默认自动选择）</summary>
<select name="storage"><option value="auto">优先私有目录；不可用时验证站内保护</option><option value="private">仅允许网站公开目录外</option><option value="protected">验证过的站内受保护目录</option></select>
<label>指定私有目录（可留空，填写时必须是不存在的绝对路径）<input name="data-dir" placeholder="/home/account/private/my-site"></label>
<small>站内存储仅在服务器真实拒绝数据库下载后启用；Nginx 或禁止本站 HTTP 验证的主机可能需要私有目录。不会以随机目录名代替访问保护。</small>
</details>
<p><small>PHP 必须支持 PDO SQLite 并有配置/数据写入权限。驱动已包含，无需本站联网下载。当前不提供迁移、备份或恢复功能。</small></p>
<button type="submit">安装 WordPress（SQLite）</button>
</form>
<?php endif; ?>
</html>
