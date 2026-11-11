<?php
declare(strict_types=1);

namespace WPLite;

final class Updater
{
    private const UPDATE_ROOT = 'https://zhpelo.github.io/WPLite/updates/';
    private const POINTER_HEADER = "<?php exit; __halt_compiler();\n";

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'notice']);
        add_action('admin_post_wplite_upgrade', [self::class, 'upgrade']);
    }

    private static function version(): string
    {
        $data = get_file_data(dirname(__DIR__) . '/wplite.php', ['Version' => 'Version']);
        return $data['Version'];
    }

    private static function release(bool $force = false): array
    {
        $cached = get_site_transient('wplite_release');
        if (!$force && is_array($cached)) { return $cached; }
        $response = wp_safe_remote_get(self::UPDATE_ROOT . 'latest.json', ['timeout' => 8, 'limit_response_size' => 4096]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient('wplite_release', [], HOUR_IN_SECONDS);
            return [];
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $version = is_array($data) ? ($data['version'] ?? null) : null;
        $url = is_array($data) ? ($data['url'] ?? null) : null;
        $digest = is_array($data) ? ($data['sha256'] ?? null) : null;
        if (!is_string($version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
            || !is_string($url) || $url !== self::UPDATE_ROOT . 'WPLite-v' . $version . '.zip'
            || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/iD', $digest)) {
            set_site_transient('wplite_release', [], HOUR_IN_SECONDS);
            return [];
        }
        $result = ['tag' => 'v' . $version, 'zip' => $url, 'sha256' => strtolower($digest)];
        set_site_transient('wplite_release', $result, 12 * HOUR_IN_SECONDS);
        return $result;
    }

    public static function notice(): void
    {
        if (!current_user_can('update_plugins') || !defined('DB_ENGINE') || DB_ENGINE !== 'sqlite') { return; }
        if (isset($_GET['wplite_upgraded']) && $_GET['wplite_upgraded'] === '1') {
            echo '<div class="notice notice-success"><p>WPLite 已升级至 ' . esc_html(self::version()) . '。</p></div>';
        }
        $release = self::release();
        if (!$release || version_compare(substr($release['tag'], 1), self::version(), '<=')) { return; }
        echo '<div class="notice notice-info"><p>WPLite ' . esc_html($release['tag']) . ' 可升级。</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wplite_upgrade">';
        wp_nonce_field('wplite_upgrade');
        submit_button('升级 WPLite', 'primary', 'submit', false);
        echo '</form></div>';
    }

    private static function download(string $url): string
    {
        $file = wp_tempnam('wplite');
        if (!$file) { throw new \RuntimeException('无法建立临时文件。'); }
        $response = wp_safe_remote_get($url, ['timeout' => 30, 'stream' => true, 'filename' => $file]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            @unlink($file); throw new \RuntimeException('无法下载 WPLite 更新包。');
        }
        if (filesize($file) > 30 * 1024 * 1024) {
            @unlink($file); throw new \RuntimeException('升级文件过大。');
        }
        return $file;
    }

    private static function package(string $zip, string $tag, string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) { throw new \RuntimeException('主机需要 ZipArchive 扩展。'); }
        $archive = new \ZipArchive();
        if ($archive->open($zip) !== true) { throw new \RuntimeException('升级包不是有效 ZIP。'); }
        try {
            $prefixes = ['wp-content/db.php', 'wp-content/mu-plugins/wplite.php', 'wp-content/mu-plugins/wplite/'];
            if ($archive->numFiles > 500) { throw new \RuntimeException('升级包文件过多。'); }
            $total = 0;
            for ($i = 0; $i < $archive->numFiles; $i++) {
                $stat = $archive->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                $name = $stat['name'] ?? '';
                $mode = ($stat['external_attributes'] ?? 0) >> 16;
                if (!is_string($name) || str_contains($name, '\\') || str_contains($name, "\0")
                    || str_starts_with($name, '/') || preg_match('~(^|/)\.\.?(/|$)~', $name)
                    || (!in_array($name, ['wp-content/', 'wp-content/mu-plugins/', ...array_slice($prefixes, 0, 2)], true)
                        && !str_starts_with($name, $prefixes[2]))
                    || str_contains($name, 'migration-state') || preg_match('~\.(sqlite|db)(-|$)~i', $name)
                    || in_array($mode & 0170000, [0120000, 0060000], true) || $total > 100 * 1024 * 1024) {
                    throw new \RuntimeException('升级包含有不允许的文件。');
                }
            }
            if (!$archive->extractTo($destination)) { throw new \RuntimeException('无法解压升级包。'); }
        } finally { $archive->close(); }
        $root = $destination . '/wp-content';
        foreach (['db.php', 'mu-plugins/wplite.php', 'mu-plugins/wplite/bootstrap.php',
            'mu-plugins/wplite/environment.php', 'mu-plugins/wplite/migration.php',
            'mu-plugins/wplite/setup.php', 'mu-plugins/wplite/updater.php',
            'mu-plugins/wplite/vendor/sqlite/wp-includes/sqlite/db.php',
            'mu-plugins/wplite/vendor/sqlite/LICENSE'] as $path) {
            if (!is_file($root . '/' . $path) || is_link($root . '/' . $path)) {
                throw new \RuntimeException('升级包缺少必要文件。');
            }
        }
        foreach (['db.php', 'mu-plugins/wplite.php', 'mu-plugins/wplite/bootstrap.php'] as $path) {
            if (!is_file(WP_CONTENT_DIR . '/' . $path)) { throw new \RuntimeException('当前加载入口不存在。'); }
            $old = (string) file_get_contents(WP_CONTENT_DIR . '/' . $path);
            $new = (string) file_get_contents($root . '/' . $path);
            if ($path === 'mu-plugins/wplite.php') {
                $old = preg_replace('/^ \* Version: .*$/m', ' * Version: VERSION', $old);
                $new = preg_replace('/^ \* Version: .*$/m', ' * Version: VERSION', $new);
            }
            if ($old !== $new) { throw new \RuntimeException('此版本修改早期加载入口，需要单独部署。'); }
        }
        $phpFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($phpFiles as $file) {
            if ($file->getExtension() === 'php') { token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE); }
        }
        $header = get_file_data($root . '/mu-plugins/wplite.php', ['Version' => 'Version', 'PHP' => 'Requires PHP', 'WP' => 'Requires at least']);
        if ($header['Version'] !== substr($tag, 1)
            || ($header['PHP'] && version_compare(PHP_VERSION, $header['PHP'], '<'))
            || ($header['WP'] && version_compare(get_bloginfo('version'), $header['WP'], '<'))) {
            throw new \RuntimeException('升级包版本或运行环境不匹配。');
        }
        require_once __DIR__ . '/environment.php';
        Environment::requireSupported();
    }

    private static function removeStage(string $stage): void
    {
        if (!is_dir($stage)) { return; }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir()) { @rmdir($item->getPathname()); }
            else { @unlink($item->getPathname()); }
        }
        @rmdir($stage);
    }

    public static function upgrade(): void
    {
        if (!current_user_can('update_plugins')) { wp_die('无权升级 WPLite。', '', ['response' => 403]); }
        check_admin_referer('wplite_upgrade');
        $lock = @fopen(WP_CONTENT_DIR . '/mu-plugins/wplite-upgrade.lock.php', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { wp_die('另一项 WPLite 升级正在进行。'); }
        $files = [];
        $stage = '';
        $versionDir = '';
        $committed = false;
        $failure = null;
        $temporary = '';
        try {
            if (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite'
                || !is_writable(WP_CONTENT_DIR . '/mu-plugins')) { throw new \RuntimeException('当前站点状态不允许升级。'); }
            $stateFile = WP_CONTENT_DIR . '/mu-plugins/wplite/migration-state.php';
            if (is_file($stateFile)) {
                $state = (string) file_get_contents($stateFile);
                $header = "<?php http_response_code(404); exit; __halt_compiler();\n";
                $data = str_starts_with($state, $header) ? json_decode(substr($state, strlen($header)), true) : null;
                if (!is_array($data) || ($data['phase'] ?? '') !== 'active') {
                    throw new \RuntimeException('数据库迁移状态未结束，不能升级。');
                }
            }
            $release = self::release(true);
            if (!$release || version_compare(substr($release['tag'], 1), self::version(), '<=')) {
                throw new \RuntimeException('没有可用的稳定版。');
            }
            $files[] = $zip = self::download($release['zip']);
            if (!hash_equals($release['sha256'], hash_file('sha256', $zip))) {
                throw new \RuntimeException('升级包 SHA-256 校验失败。');
            }
            $base = WP_CONTENT_DIR . '/mu-plugins/wplite-versions';
            if (!is_dir($base) && !mkdir($base, 0700)) { throw new \RuntimeException('无法建立版本目录。'); }
            $stage = $base . '/.stage-' . bin2hex(random_bytes(8));
            if (!mkdir($stage, 0700)) { throw new \RuntimeException('无法暂存升级包。'); }
            self::package($zip, $release['tag'], $stage);
            $destination = $base . '/' . $release['tag'];
            if (file_exists($destination)) { throw new \RuntimeException('目标版本目录已存在，请检查此前升级结果。'); }
            if (!rename($stage, $destination)) { throw new \RuntimeException('无法发布新版本目录。'); }
            $versionDir = $destination;
            $stage = '';
            $pointer = WP_CONTENT_DIR . '/mu-plugins/wplite-current.php';
            $temporary = $pointer . '.' . bin2hex(random_bytes(8));
            $code = self::POINTER_HEADER . $release['tag'] . "\n";
            if (file_put_contents($temporary, $code, LOCK_EX) !== strlen($code) || !rename($temporary, $pointer)) {
                throw new \RuntimeException('无法切换版本指针。');
            }
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($pointer, true); }
            $committed = true;
            delete_site_transient('wplite_release');
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            self::removeStage($stage);
            if (!$committed) { self::removeStage($versionDir); }
            if ($temporary !== '' && is_file($temporary)) { @unlink($temporary); }
            foreach ($files as $file) { @unlink($file); }
            flock($lock, LOCK_UN); fclose($lock);
        }
        if ($failure) { wp_die(esc_html('WPLite 升级未完成：' . $failure->getMessage())); }
        wp_safe_redirect(admin_url('plugins.php?wplite_upgraded=1'));
        exit;
    }
}
