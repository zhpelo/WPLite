<?php
declare(strict_types=1);

namespace WPLite;

/** Browser-driven MySQL migration. No background jobs or automatic retry. */
require_once __DIR__ . '/conversion-core.php';

final class Migration
{
    use ConversionCore;
    private const STATE = WP_CONTENT_DIR . '/mu-plugins/wplite/migration-state.php';
    private const STATE_HEADER = "<?php http_response_code(404); exit; __halt_compiler();\n";
    private static $gate = null;
    private static ?\mysqli $readOnlyConnection = null;
    private static float $deadline = 0;
    private const REQUEST_SECONDS = 8;
    private const BATCH_ROWS = 1000;

    private static function beginBudget(): void
    {
        $limit = (int) ini_get('max_execution_time');
        $elapsed = microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $seconds = $limit > 0 ? min(self::REQUEST_SECONDS, max(0, $limit - $elapsed - 2)) : self::REQUEST_SECONDS;
        self::$deadline = microtime(true) + $seconds;
    }

    private static function processingExpiry(): int
    {
        return time() + 120;
    }

    private static function state(): array
    {
        if (!file_exists(self::STATE)) { return []; }
        if (is_link(self::STATE)) { throw new \RuntimeException('迁移状态文件不能是符号链接。'); }
        // Read data, not cached PHP bytecode: pause/switch decisions must be fresh.
        $code = (string) file_get_contents(self::STATE);
        if (!str_starts_with($code, self::STATE_HEADER)) { throw new \RuntimeException('迁移状态文件无效。'); }
        $state = json_decode(substr($code, strlen(self::STATE_HEADER)), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['format'] ?? null) !== 1) { throw new \RuntimeException('迁移状态文件无效。'); }
        return $state;
    }

    private static function save(array $state): void
    {
        $file = WP_CONTENT_DIR . '/mu-plugins/wplite/migration-state-' . bin2hex(random_bytes(8)) . '.php';
        $code = self::STATE_HEADER . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = @fopen($file, 'x');
        if (!$handle) { throw new \RuntimeException('无法写入迁移状态。'); }
        try {
            if (!chmod($file, 0600) || fwrite($handle, $code) !== strlen($code)) { throw new \RuntimeException('迁移状态写入失败。'); }
        } finally { fclose($handle); }
        if (!rename($file, self::STATE)) { throw new \RuntimeException('无法发布迁移状态。'); }
        clearstatcache(true, self::STATE);
    }

    private static function paused(array $state): bool
    {
        // Older in-flight migrations must stay paused until their leases expire.
        return in_array($state['phase'] ?? '', ['copying', 'ready', 'verifying'], true) && ($state['expires'] ?? 0) > time();
    }

    private static function tokenMatches(array $state): bool
    {
        $token = $_POST['wplite_migration_token'] ?? '';
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/wp-admin/admin-post.php')
            && ($_POST['action'] ?? '') === 'wplite_migration'
            && is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
            && isset($state['token_hash']) && hash_equals($state['token_hash'], hash('sha256', $token));
    }

    private static function unavailable(): never
    {
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Retry-After: 60');
        exit('WPLite: database migration in progress. Please try again shortly.');
    }

    public static function guard(): void
    {
        try {
            $state = self::state();
            if (($state['phase'] ?? '') === 'active') {
                // A stale opcode cache must never reconnect the retired MySQL site.
                if (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite') { self::unavailable(); }
                return;
            }
            if (self::paused($state) && !self::tokenMatches($state)) { self::unavailable(); }
            // The shared lock lives for the whole PHP request, including shutdown.
            // An exclusive migration lock therefore drains in-flight WP requests.
            self::$gate = @fopen(__FILE__, 'r');
            if (!self::$gate) { if (self::paused($state)) { self::unavailable(); } return; }
            if (!flock(self::$gate, LOCK_SH | LOCK_NB, $blocked)) {
                if ($blocked || self::paused($state)) { self::unavailable(); }
                return; // Unsupported locks cannot break an otherwise normal MySQL site.
            }
            $state = self::state();
            if (($state['phase'] ?? '') === 'active' && (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite')) { self::unavailable(); }
            if (self::paused($state) && !self::tokenMatches($state)) { self::unavailable(); }
            if (self::paused($state) && self::tokenMatches($state)) {
                // Progress requests still boot WordPress/plugins. Keep incidental
                // cache/session/shutdown writes from changing the paused source.
                add_filter('query', [self::class, 'readOnlyQuery'], PHP_INT_MIN);
            }
        } catch (\Throwable $error) {
            if (!is_file(self::STATE)) { return; } // No migration: keep normal MySQL available on restricted hosts.
            error_log('WPLite migration guard: ' . $error->getMessage()); self::unavailable();
        }
    }

    public static function readOnlyQuery(string $sql): string
    {
        global $wpdb;
        if (isset($wpdb) && $wpdb->dbh instanceof \mysqli) {
            // Plugins may re-enable SQL error display before each write. Keep
            // expected read-only rejections out of the migration HTML/headers;
            // wpdb still records last_error and logs the database error.
            $wpdb->hide_errors();
        }
        if (isset($wpdb) && $wpdb->dbh instanceof \mysqli && $wpdb->dbh !== self::$readOnlyConnection) {
            try {
                if (!$wpdb->dbh->query('SET SESSION TRANSACTION READ ONLY')) { throw new \RuntimeException('无法保护暂停中的 MySQL 连接。'); }
                self::$readOnlyConnection = $wpdb->dbh;
            } catch (\Throwable $error) {
                error_log('WPLite read-only migration request: ' . $error->getMessage());
                self::unavailable();
            }
        }
        return $sql;
    }

    private static function exclusive(): void
    {
        if (!function_exists('flock')) { throw new \RuntimeException('主机不支持所需文件锁，尚未开始迁移。'); }
        if (is_resource(self::$gate)) { flock(self::$gate, LOCK_UN); fclose(self::$gate); }
        self::$gate = fopen(__FILE__, 'r');
        $until = microtime(true) + 3;
        do {
            if (flock(self::$gate, LOCK_EX | LOCK_NB)) { return; }
            usleep(50000);
        } while (microtime(true) < $until);
        throw new \RuntimeException('仍有请求在运行，尚未开始迁移。请稍后重试。');
    }

    public static function notice(bool $onPage = false): void
    {
        if (!current_user_can('manage_options')) { return; }
        if (!$onPage && ($_GET['page'] ?? '') === 'wplite-migrate') { return; }
        echo '<div class="notice notice-info is-dismissible"><p>WPLite：当前站点使用 MySQL，是否迁移到 SQLite？开始后将连续完成复制、校验和自动切换，原 MySQL 数据库会保留。</p>';
        echo '<p>任务执行日志、WooCommerce 运行日志、购物车会话仅迁移表结构；WordPress 临时缓存不迁移。访客尚未结账的购物车会被清空。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wplite_migration');
        echo '<input type="hidden" name="action" value="wplite_migration"><input type="hidden" name="operation" value="start">';
        echo '<p><label><input type="checkbox" name="accept_pause" value="1" required> 我接受短暂停站及校验后自动切换，已等待部署前的旧请求结束，并停止其他程序直接写入数据库。</label></p>';
        echo '<p><button class="button button-primary">开始迁移，完成后自动切换</button> 暂不迁移可关闭此提示。</p></form></div>';
    }

    public static function menu(): void
    {
        add_management_page('WPLite 迁移到 SQLite', '迁移到 SQLite', 'manage_options', 'wplite-migrate', [self::class, 'migrationPage']);
    }

    public static function migrationPage(): void
    {
        if (!current_user_can('manage_options')) { wp_die('无权访问。', '', ['response' => 403]); }
        echo '<div class="wrap"><h1>WPLite 迁移到 SQLite</h1>';
        self::notice(true);
        echo '</div>';
    }

    private static function source(): \wpdb
    {
        $source = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $source->suppress_errors(true);
        $source->hide_errors();
        if (!$source->ready || !($source->dbh instanceof \mysqli)) { throw new \RuntimeException('仅支持标准 mysqli 连接的单站点 WordPress。'); }
        return $source;
    }

    private static function configuration(): array
    {
        $path = is_file(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(rtrim(ABSPATH, '/')) . '/wp-config.php';
        if (is_link($path) || !is_file($path) || !is_readable($path)
            || ($path === ABSPATH . 'wp-config.php' && !is_writable($path))) {
            throw new \RuntimeException('现有 wp-config.php 必须是可读取的普通文件；根目录配置还须可写。');
        }
        $code = (string) file_get_contents($path);
        $depth = 0; $found = []; $defined = [];
        $tokens = token_get_all($code, TOKEN_PARSE);
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_STRING && strcasecmp($text, 'define') === 0) {
                $j = $index + 1;
                while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { ++$j; }
                if (($tokens[$j] ?? null) === '(') {
                    ++$j;
                    while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { ++$j; }
                    $name = $tokens[$j] ?? null;
                    if (!is_array($name) || $name[0] !== T_CONSTANT_ENCAPSED_STRING
                        || !preg_match('/^[\x27\x22]([A-Z][A-Z0-9_]*)[\x27\x22]$/D', $name[1], $match)) {
                        throw new \RuntimeException('wp-config.php 包含动态常量，不能安全生成新配置。');
                    }
                    $defined[$match[1]] = true;
                }
            }
            if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                $statement = '';
                for ($j = $index; $j < count($tokens); ++$j) {
                    $part = $tokens[$j];
                    if (!is_array($part) || !in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $statement .= is_array($part) ? $part[1] : $part; }
                    if ($part === ';') { break; }
                }
                if ($depth !== 0 || !preg_match('/^require(?:_once)?\(?ABSPATH\.([\'"])wp-settings\.php\1\)?;$/D', $statement)) {
                    throw new \RuntimeException('原配置包含额外文件加载语句，不能安全生成新配置。');
                }
                $found[] = true;
            }
            if ($token === '{') { ++$depth; } elseif ($token === '}') { --$depth; }
        }
        if (count($found) !== 1) { throw new \RuntimeException('仅支持具有标准 wp-settings.php 加载语句的配置；不会猜测或重写自定义配置。'); }
        return ['path' => $path, 'sha256' => hash('sha256', $code), 'code' => $code, 'defined' => array_keys($defined)];
    }

    private static function createTarget(): array
    {
        require_once __DIR__ . '/environment.php'; Environment::requireSupported();
        $public = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $root = realpath(rtrim(ABSPATH, '/'));
        if (!$public || !$root || !Environment::isWithin($root, $public)) { throw new \RuntimeException('无法确认网站公开目录。'); }
        $content = realpath(WP_CONTENT_DIR);
        if (!$content || $content !== realpath($root . '/wp-content') || is_link($root . '/wp-content') || !is_writable($content)) {
            throw new \RuntimeException('默认 wp-content 不适合受保护存储；未修改原站点。');
        }
        $directory = $content . '/wplite-data-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('无法创建 SQLite 迁移目录。');
        }
        $result = ['database' => $directory . '/site.sqlite', 'public' => $public];
        require_once __DIR__ . '/setup.php';
        Setup::createDenyRule($directory);
        $result['storage_deny_hash'] = hash('sha256', Setup::DENY);
        $path = $directory . '/site.sqlite';
        $old = umask(0077);
        try { $pdo = new \PDO('sqlite:' . $path); $pdo->exec('PRAGMA user_version = 0'); $pdo = null; chmod($path, 0600); }
        finally { umask($old); }
        return $result;
    }

    private static function checkConfiguration(array $state): array
    {
        $config = self::configuration();
        if ($config['path'] !== $state['config_path'] || !hash_equals($state['config_hash'], $config['sha256'])) {
            throw new \RuntimeException('wp-config.php 已改变，未切换。');
        }
        return $config;
    }

    private static function publish(array $state, bool &$committed): void
    {
        $config = self::checkConfiguration($state);
        require_once __DIR__ . '/setup.php';
        global $table_prefix;
        if (!is_string($table_prefix)) { throw new \RuntimeException('无法保留原站点表前缀，未切换。'); }
        $constants = ['DB_ENGINE' => 'sqlite', 'DB_PATH' => $state['database'],
            'DB_NAME' => 'wplite', 'DB_USER' => '', 'DB_PASSWORD' => '', 'DB_HOST' => '',
            'DB_CHARSET' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
            'DB_COLLATE' => defined('DB_COLLATE') ? DB_COLLATE : '',
            'WPLITE_PUBLIC_ROOT' => $state['public'],
            'WPLITE_STORAGE_DENY_HASH' => $state['storage_deny_hash']];
        foreach ($config['defined'] as $name) {
            if ($name === 'ABSPATH' || str_starts_with($name, 'DB_')) { continue; }
            if (str_starts_with($name, 'WPLITE_') || !defined($name)) {
                throw new \RuntimeException('原配置包含不支持的常量：' . $name);
            }
            $value = constant($name);
            if (!is_scalar($value) && $value !== null) { throw new \RuntimeException('原配置包含非标量常量：' . $name); }
            $constants[$name] = $value;
        }
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            if (!isset($constants[$name]) || !is_string($constants[$name])) {
                throw new \RuntimeException('原配置缺少站点密钥：' . $name);
            }
        }
        $publishPath = ABSPATH . 'wp-config.php';
        $replacingRoot = $config['path'] === $publishPath;
        if (!is_writable(ABSPATH) || (!$replacingRoot && (file_exists($publishPath) || is_link($publishPath)))) {
            throw new \RuntimeException('网站根目录无法安全创建 wp-config.php，未切换。');
        }
        $updated = Setup::configSource($constants, $table_prefix);
        token_get_all($updated, TOKEN_PARSE);
        $backup = dirname($state['database']) . '/wp-config.mysql.bak';
        Setup::fresh($backup, $config['code']);
        $temporary = ABSPATH . '.wplite-config-' . bin2hex(random_bytes(8)) . '.php';
        Setup::fresh($temporary, $updated, fileperms($config['path']) & 0777);
        self::tick();
        if (!hash_equals($state['config_hash'], hash_file('sha256', $config['path']))
            || !($replacingRoot ? rename($temporary, $publishPath) : link($temporary, $publishPath))) {
            throw new \RuntimeException('配置发布失败，未切换。');
        }
        if (!$replacingRoot) { @unlink($temporary); }
        $committed = true;
        if (function_exists('opcache_invalidate')) { opcache_invalidate($publishPath, true); }
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_options') || is_multisite()) { wp_die('仅限单站点管理员。', '', ['response' => 403]); }
        check_admin_referer('wplite_migration');
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? $_POST['operation'] : '';
        $token = isset($_POST['wplite_migration_token']) && is_string($_POST['wplite_migration_token']) ? $_POST['wplite_migration_token'] : '';
        $owned = null; $committed = false; $waiting = false; $reserve = str_repeat('x', 65536);
        register_shutdown_function(static function () use (&$owned, &$committed, &$waiting, &$reserve): void {
            $reserve = '';
            if ($owned && !$waiting && in_array($owned['phase'] ?? '', ['copying', 'ready', 'verifying'], true)) {
                $owned['phase'] = $committed ? 'active' : 'failed'; $owned['expires'] = 0;
                try { self::save($owned); } catch (\Throwable) { /* A killed worker is covered by lease expiry. */ }
            }
        });
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !in_array($operation, ['start', 'batch', 'cancel'], true)) {
                throw new \RuntimeException('无效的迁移操作。');
            }
            self::exclusive();
            $state = self::state();
            self::beginBudget();
            if ($operation === 'start') {
                if (self::paused($state)) { throw new \RuntimeException('已有迁移正在处理，请使用原迁移页面，或等待暂停时限结束。'); }
                if (($_POST['accept_pause'] ?? '') !== '1') { throw new \RuntimeException('请先确认接受短暂停站。'); }
                if (defined('DB_ENGINE') || defined('DB_PATH') || defined('WPLITE_PUBLIC_ROOT')) { throw new \RuntimeException('当前存在自定义数据库常量，不支持自动切换，未修改配置。'); }
                if (wp_using_ext_object_cache()) { throw new \RuntimeException('请先停用并清理外部持久对象缓存，再执行迁移。'); }
                $dropin = WP_CONTENT_DIR . '/db.php';
                if (!is_file($dropin) || is_link($dropin) || !str_contains((string) file_get_contents($dropin), 'WPLITE_SQLITE_DROPIN_V3')) {
                    throw new \RuntimeException('需要先安装 WPLite 的 db.php；不要覆盖其他插件的数据库 drop-in。');
                }
                $config = self::configuration();
                $targetInfo = self::createTarget();
                $token = bin2hex(random_bytes(32));
                $owned = ['format' => 1, 'batch_format' => 3, 'phase' => 'copying', 'step' => 'schema',
                    'index' => 0, 'offset' => 0, 'cursor' => [], 'sequence' => 0, 'tables' => [],
                    'expires' => self::processingExpiry(), 'token_hash' => hash('sha256', $token),
                    'user' => get_current_user_id(), 'config_path' => $config['path'], 'config_hash' => $config['sha256']] + $targetInfo;
                self::save($owned);
                $source = self::source();
                try {
                    $owned['names'] = self::tables($source);
                    $target = self::target($owned['database'], $source);
                    $target->get_sqlite_pdo()->exec('CREATE TABLE ' . self::HASHES
                        . ' (table_name TEXT NOT NULL, pass TEXT NOT NULL, hash TEXT NOT NULL, n INTEGER NOT NULL,'
                        . ' PRIMARY KEY(table_name,pass,hash)) WITHOUT ROWID');
                    $target = null;
                } finally { $source->close(); }
            } else {
                if (!self::paused($state) || ($state['batch_format'] ?? 0) !== 3 || ($state['phase'] ?? '') !== 'copying'
                    || !self::tokenMatches($state) || ($state['user'] ?? 0) !== get_current_user_id()) {
                    throw new \RuntimeException('迁移已过期或凭据无效；不会继续使用旧副本。请重新迁移。');
                }
                $owned = $state;
                if ($operation === 'cancel') {
                    $owned['phase'] = 'cancelled'; $owned['expires'] = 0; self::save($owned);
                    wp_safe_redirect(admin_url()); return;
                }
                // Refresh/replayed POSTs only redisplay the latest state.
                if (($_POST['sequence'] ?? '') !== (string) $owned['sequence']) {
                    self::page($owned, $token); $waiting = true; return;
                }
                if (wp_using_ext_object_cache()) { throw new \RuntimeException('迁移期间不能启用外部持久对象缓存。'); }
                self::checkConfiguration($owned);
                if (!is_file($owned['database']) || is_link($owned['database'])) { throw new \RuntimeException('SQLite 副本不存在，未切换。'); }
                $source = self::source();
                try {
                    if ($owned['step'] === 'publish'
                        && self::tables($source) !== $owned['names']) { throw new \RuntimeException('源数据表已改变，未切换。'); }
                    $target = self::target($owned['database'], $source);
                    if ($owned['step'] === 'publish') {
                        // No WordPress writers have been allowed since copying began.
                        // External database writers must remain stopped for all batches.
                        $target->get_sqlite_pdo()->exec('DROP TABLE ' . self::HASHES);
                        $target = null;
                        self::publish($owned, $committed);
                        $owned = ['format' => 1, 'phase' => 'active', 'expires' => 0]; self::save($owned);
                        wp_safe_redirect(admin_url()); return;
                    }
                    // Share one row/time budget across tables and steps. A tiny
                    // table need not cause another WordPress bootstrap/request.
                    $until = min(microtime(true) + 2, self::$deadline - 1);
                    $remaining = self::BATCH_ROWS;
                    while ($remaining > 0 && microtime(true) < $until && $owned['step'] !== 'publish') {
                        if ($owned['step'] === 'target' && $owned['index'] === 0 && $owned['offset'] === 0
                            && self::tables($source) !== $owned['names']) { throw new \RuntimeException('源数据表已改变，未切换。'); }
                        $remaining -= self::advance($owned, $source, $target, $remaining, $until);
                    }
                    $target = null;
                } finally { $source->close(); }
                ++$owned['sequence'];
            }
            self::tick();
            $owned['expires'] = self::processingExpiry();
            self::save($owned);
            self::page($owned, $token);
            $waiting = true;
        } catch (\Throwable $error) {
            if ($owned) {
                $owned['phase'] = $committed ? 'active' : 'failed'; $owned['expires'] = 0;
                try { self::save($owned); } catch (\Throwable) { /* The lease also bounds an interrupted pause. */ }
            }
            wp_die(esc_html(($committed ? '配置已切换到 SQLite。' : '未切换，原站点继续使用 MySQL。') . $error->getMessage()), 'WPLite 迁移', ['response' => 500]);
        }
    }

    private static function page(array $state, string $token): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $nonce = bin2hex(random_bytes(16));
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-" . $nonce . "'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WPLite 迁移</title><body style="font:16px/1.7 system-ui;max-width:720px;margin:40px auto;padding:20px">';
        echo '<h1>正在分批复制及校验，完成后自动切换</h1>';
        $table = $state['names'][$state['index']] ?? '';
        echo '<p>当前表：' . esc_html($table) . '；本轮已处理 ' . (int) $state['offset'] . ' 行。</p>';
        echo '<p>请保持此页面打开，完成后自动进入后台，无需再次确认。浏览器会连续提交短请求；中断后不自动重试，最后一次请求后的暂停租期为 2 分钟。</p>';
        $skipped = array_keys(array_filter($state['tables'], static fn (array $table): bool => $table['skip_content']));
        if ($skipped) { echo '<p>以下表只迁移结构：' . esc_html(implode('、', $skipped)) . '。WordPress 临时缓存不迁移。</p>'; }
        echo '<form id="wplite-batch" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wplite_migration');
        echo '<input type="hidden" name="action" value="wplite_migration"><input type="hidden" name="wplite_migration_token" value="' . esc_attr($token) . '">';
        echo '<input type="hidden" name="sequence" value="' . (int) $state['sequence'] . '">';
        echo '<button name="operation" value="batch" id="wplite-next">继续处理本批</button> ';
        echo '<button name="operation" value="cancel" id="wplite-cancel">取消并恢复 MySQL 站点</button></form>';
        echo '<script nonce="' . $nonce . '">const timer=setTimeout(()=>document.getElementById("wplite-next").click(),150);document.getElementById("wplite-batch").addEventListener("submit",()=>clearTimeout(timer));</script>';
        echo '</body></html>';
    }
}
