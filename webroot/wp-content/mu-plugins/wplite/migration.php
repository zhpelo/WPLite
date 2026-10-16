<?php
declare(strict_types=1);

namespace WPLite;

/** Explicit, small-site MySQL migration. No background jobs or automatic retry. */
final class Migration
{
    private const STATE = __DIR__ . '/migration-state.php';
    private const STATE_HEADER = "<?php http_response_code(404); exit; __halt_compiler();\n";
    private static $gate = null;
    private static float $deadline = 0;

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
        $file = __DIR__ . '/migration-state-' . bin2hex(random_bytes(8)) . '.php';
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
        return in_array($state['phase'] ?? '', ['copying', 'ready'], true) && ($state['expires'] ?? 0) > time();
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
        } catch (\Throwable $error) {
            if (!is_file(self::STATE)) { return; } // No migration: keep normal MySQL available on restricted hosts.
            error_log('WPLite migration guard: ' . $error->getMessage()); self::unavailable();
        }
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

    public static function notice(): void
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="notice notice-info is-dismissible"><p>WPLite：当前站点使用 MySQL，是否迁移到 SQLite？原 MySQL 数据库会保留，复制校验后还需单独确认切换。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wplite_migration');
        echo '<input type="hidden" name="action" value="wplite_migration"><input type="hidden" name="operation" value="start">';
        echo '<p><label><input type="checkbox" name="accept_pause" value="1" required> 我接受短暂停站，已等待部署前的旧请求结束，并停止其他程序直接写入数据库。</label></p>';
        echo '<p><button class="button button-primary">复制并校验数据</button> 暂不迁移可关闭此提示。</p></form></div>';
    }

    private static function tick(): void
    {
        if (microtime(true) > self::$deadline) { throw new \RuntimeException('迁移超过本次请求的安全时限，未切换数据库。当前仅支持能在 20 秒内完成复制校验的小型站点。'); }
    }

    private static function identifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

    private static function query(\wpdb $source, string $sql): void
    {
        self::tick();
        if ($source->query($sql) === false) { throw new \RuntimeException('MySQL 读取或锁定失败：' . $source->last_error); }
    }

    private static function source(): \wpdb
    {
        $source = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $source->suppress_errors(true);
        $source->hide_errors();
        if (!$source->ready || !($source->dbh instanceof \mysqli)) { throw new \RuntimeException('仅支持标准 mysqli 连接的单站点 WordPress。'); }
        return $source;
    }

    private static function tables(\wpdb $source): array
    {
        global $wpdb;
        self::query($source, 'SHOW FULL TABLES');
        $tables = [];
        foreach ($source->last_result as $item) {
            $values = array_values((array) $item);
            if (!str_starts_with((string) $values[0], $wpdb->prefix)) { continue; }
            if ($values[1] !== 'BASE TABLE') { throw new \RuntimeException('站点包含视图，当前不支持迁移；原站点未改变。'); }
            $tables[] = (string) $values[0];
        }
        sort($tables, SORT_STRING);
        if (!$tables || !in_array($wpdb->users, $tables, true)) { throw new \RuntimeException('未找到当前站点的数据表。'); }
        self::query($source, "SHOW TRIGGERS WHERE `Table` LIKE '" . $source->esc_like($wpdb->prefix) . "%'");
        if ($source->num_rows) { throw new \RuntimeException('站点包含 MySQL 触发器，当前不支持迁移。'); }
        return $tables;
    }

    private static function schema(\wpdb $source, string $table): array
    {
        $name = self::identifier($table);
        self::query($source, 'SHOW CREATE TABLE ' . $name);
        $ddl = array_values((array) $source->last_result[0])[1];
        if (preg_match('/\bFOREIGN\s+KEY\b/i', $ddl)) { throw new \RuntimeException('当前不支持含外键的表：' . $table); }
        self::query($source, 'SHOW FULL COLUMNS FROM ' . $name);
        $columns = [];
        foreach ($source->last_result as $column) {
            // DEFAULT_GENERATED describes an expression default such as
            // CURRENT_TIMESTAMP; it is not a generated column.
            if (preg_match('/\b(?:VIRTUAL|STORED)\s+GENERATED\b/i', (string) $column->Extra)) {
                throw new \RuntimeException('当前不支持生成列：' . $table);
            }
            $columns[$column->Field] = $column->Type;
        }
        return ['ddl' => $ddl, 'columns' => $columns];
    }

    private static function rowHash(array $row, array $columns): string
    {
        $hash = hash_init('sha256');
        foreach ($columns as $name => $type) {
            $value = $row[$name];
            if ($value === null) { hash_update($hash, 'N'); continue; }
            $value = (string) $value;
            // Ignore insignificant DECIMAL trailing zeros, never round values.
            if (preg_match('/^(decimal|numeric|float|double|real)\b/i', $type)
                && preg_match('/^-?\d+\.\d+$/D', $value)) { $value = rtrim(rtrim($value, '0'), '.'); }
            hash_update($hash, 'V' . strlen($value) . ':' . $value);
        }
        return hash_final($hash);
    }

    private static function digest(array $hashes): string
    {
        sort($hashes, SORT_STRING); // Multiset comparison preserves duplicate rows.
        return hash('sha256', implode('', $hashes));
    }

    private static function sourceDigest(\wpdb $source, string $table, array $columns, ?\WP_MySQL_On_SQLite $target = null): array
    {
        self::tick();
        $rows = $source->dbh->query('SELECT * FROM ' . self::identifier($table), MYSQLI_USE_RESULT);
        if (!$rows) { throw new \RuntimeException('无法读取表：' . $table); }
        $hashes = [];
        $insert = 'INSERT INTO ' . self::identifier($table) . ' (' . implode(',', array_map(self::identifier(...), array_keys($columns))) . ') VALUES (';
        try {
            while ($row = $rows->fetch_assoc()) {
                self::tick();
                if ($target) {
                    $values = [];
                    foreach ($columns as $name => $type) {
                        $binary = preg_match('/\b(blob|binary|varbinary|bit)\b/i', $type);
                        $values[] = $row[$name] === null ? 'NULL' : $target->quote($row[$name], $binary ? \PDO::PARAM_LOB : \PDO::PARAM_STR);
                    }
                    $target->exec($insert . implode(',', $values) . ')');
                }
                $hashes[] = self::rowHash($row, $columns);
            }
        } finally { $rows->free(); }
        return ['rows' => count($hashes), 'sha256' => self::digest($hashes)];
    }

    private static function targetDigest(\WP_MySQL_On_SQLite $target, string $table, array $columns): array
    {
        $rows = $target->query('SELECT * FROM ' . self::identifier($table));
        $hashes = [];
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) { self::tick(); $hashes[] = self::rowHash($row, $columns); }
        return ['rows' => count($hashes), 'sha256' => self::digest($hashes)];
    }

    private static function target(string $path, \wpdb $source): \WP_MySQL_On_SQLite
    {
        require_once __DIR__ . '/environment.php';
        $version = Environment::requireSupported();
        require_once __DIR__ . '/vendor/sqlite/wp-includes/database/load.php';
        $target = new \WP_MySQL_On_SQLite('mysql-on-sqlite:path=' . str_replace(';', ';;', $path) . ';dbname=' . str_replace(';', ';;', DB_NAME), null, null,
            ['sqlite_journal_mode' => Environment::journalMode($version), 'sqlite_synchronous' => 'FULL']);
        // Match WordPress's MySQL session rules, including valid legacy zero dates.
        self::query($source, 'SELECT @@SESSION.sql_mode');
        $mode = array_values((array) $source->last_result[0])[0];
        $target->exec('SET SESSION sql_mode = ' . $target->quote($mode));
        return $target;
    }

    private static function configuration(): array
    {
        $path = is_file(ABSPATH . 'wp-config.php') ? ABSPATH . 'wp-config.php' : dirname(rtrim(ABSPATH, '/')) . '/wp-config.php';
        if (is_link($path) || !is_file($path) || !is_writable($path) || !is_writable(dirname($path))) { throw new \RuntimeException('现有 wp-config.php 必须是可写的普通文件。'); }
        $code = (string) file_get_contents($path);
        $offset = 0; $depth = 0; $found = [];
        $tokens = token_get_all($code, TOKEN_PARSE);
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE], true) && $depth === 0) {
                $statement = '';
                for ($j = $index; $j < count($tokens); ++$j) {
                    $part = $tokens[$j];
                    if (!is_array($part) || !in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $statement .= is_array($part) ? $part[1] : $part; }
                    if ($part === ';') { break; }
                }
                if (preg_match('/^require(?:_once)?\(?ABSPATH\.([\'"])wp-settings\.php\1\)?;$/D', $statement)) { $found[] = $offset; }
            }
            if ($token === '{') { ++$depth; } elseif ($token === '}') { --$depth; }
            $offset += strlen($text);
        }
        if (count($found) !== 1) { throw new \RuntimeException('仅支持具有标准 wp-settings.php 加载语句的配置；不会猜测或重写自定义配置。'); }
        return ['path' => $path, 'sha256' => hash('sha256', $code), 'offset' => $found[0], 'code' => $code];
    }

    private static function createTarget(): array
    {
        require_once __DIR__ . '/environment.php'; Environment::requireSupported();
        $public = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if (!$public || !Environment::isWithin(rtrim(ABSPATH, '/'), $public)) { throw new \RuntimeException('无法确认网站公开目录。'); }
        $parent = realpath(dirname($public));
        if (!$parent || !is_writable($parent)) { throw new \RuntimeException('迁移需要网站公开目录外的可写私有目录；当前主机不满足要求，未修改原站点。'); }
        $directory = $parent . '/wplite-data-' . bin2hex(random_bytes(8));
        if (Environment::isWithin($directory, $public) || !mkdir($directory, 0700)) { throw new \RuntimeException('无法创建私有迁移目录。'); }
        $path = $directory . '/site.sqlite';
        $old = umask(0077);
        try { $pdo = new \PDO('sqlite:' . $path); $pdo->exec('PRAGMA user_version = 0'); $pdo = null; chmod($path, 0600); }
        finally { umask($old); }
        return ['database' => $path, 'public' => $public];
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
            if ($owned && !$waiting && in_array($owned['phase'] ?? '', ['copying', 'ready'], true)) {
                $owned['phase'] = $committed ? 'active' : 'failed'; $owned['expires'] = 0;
                try { self::save($owned); } catch (\Throwable) { /* The short lease also expires after an interrupted request. */ }
            }
        });
        try {
            if (!in_array($operation, ['start', 'switch', 'cancel'], true)) { throw new \RuntimeException('无效的迁移操作。'); }
            self::exclusive();
            $state = self::state();
            self::$deadline = microtime(true) + 20;
            if (wp_using_ext_object_cache()) { throw new \RuntimeException('请先停用并清理外部持久对象缓存，再执行迁移；不会自动更改缓存配置。'); }
            if ($operation === 'start') {
                if (self::paused($state)) { throw new \RuntimeException('已有迁移正在等待处理，请使用原确认页面，或等待暂停时限结束。'); }
                if (($_POST['accept_pause'] ?? '') !== '1') { throw new \RuntimeException('请先确认接受短暂停站。'); }
                if (defined('DB_ENGINE') || defined('DB_PATH') || defined('WPLITE_PUBLIC_ROOT')) { throw new \RuntimeException('当前存在自定义数据库常量，不支持自动切换，未修改配置。'); }
                $dropin = WP_CONTENT_DIR . '/db.php';
                if (!is_file($dropin) || is_link($dropin) || !str_contains((string) file_get_contents($dropin), 'WPLITE_SQLITE_DROPIN_V3')) { throw new \RuntimeException('需要先安装 WPLite 的 db.php；不要覆盖其他插件的数据库 drop-in。'); }
                $config = self::configuration();
                $targetInfo = self::createTarget();
                $token = bin2hex(random_bytes(32));
                $owned = ['format' => 1, 'phase' => 'copying', 'expires' => time() + 120, 'token_hash' => hash('sha256', $token), 'user' => get_current_user_id(), 'config_path' => $config['path'], 'config_hash' => $config['sha256']] + $targetInfo;
                self::save($owned);
                $source = self::source();
                $tables = self::tables($source);
                self::query($source, 'SET SESSION lock_wait_timeout = 5');
                self::query($source, 'LOCK TABLES ' . implode(', ', array_map(static fn ($table) => self::identifier($table) . ' READ', $tables)));
                try {
                    $target = self::target($owned['database'], $source);
                    $details = [];
                    foreach ($tables as $table) {
                        $schema = self::schema($source, $table);
                        $target->exec($schema['ddl']);
                        $target->beginTransaction();
                        $digest = self::sourceDigest($source, $table, $schema['columns'], $target);
                        $target->commit();
                        if (self::targetDigest($target, $table, $schema['columns']) !== $digest) { throw new \RuntimeException('数据校验不一致，未切换：' . $table); }
                        $details[$table] = ['schema' => hash('sha256', $schema['ddl']), 'columns' => $schema['columns']] + $digest;
                    }
                    if ($target->get_sqlite_pdo()->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { throw new \RuntimeException('SQLite 完整性检查失败。'); }
                    $target = null;
                } finally { $source->query('UNLOCK TABLES'); $source->close(); }
                $owned['tables'] = $details; $owned['phase'] = 'ready'; $owned['expires'] = time() + 600;
                self::save($owned);
                $waiting = true;
                self::confirmation($owned, $token);
                return;
            }
            if (!self::paused($state) || ($state['phase'] ?? '') !== 'ready' || !self::tokenMatches($state) || ($state['user'] ?? 0) !== get_current_user_id()) {
                throw new \RuntimeException('确认已过期或凭据无效；不会使用可能过时的数据副本。请重新迁移。');
            }
            $owned = $state;
            if ($operation === 'cancel') {
                $owned['phase'] = 'cancelled'; $owned['expires'] = 0; self::save($owned);
                wp_safe_redirect(admin_url()); return;
            }
            // Final confirmation rechecks source schema/data and the candidate.
            $config = self::configuration();
            if ($config['path'] !== $owned['config_path'] || !hash_equals($owned['config_hash'], $config['sha256'])) { throw new \RuntimeException('wp-config.php 已改变，未切换。'); }
            $source = self::source(); $tables = self::tables($source);
            if ($tables !== array_keys($owned['tables'])) { throw new \RuntimeException('源数据表已改变，未切换。'); }
            self::query($source, 'SET SESSION lock_wait_timeout = 5');
            self::query($source, 'LOCK TABLES ' . implode(', ', array_map(static fn ($table) => self::identifier($table) . ' READ', $tables)));
            try {
                if (!is_file($owned['database']) || is_link($owned['database'])) { throw new \RuntimeException('SQLite 副本不存在，未切换。'); }
                $target = self::target($owned['database'], $source);
                foreach ($owned['tables'] as $table => $detail) {
                    $schema = self::schema($source, $table);
                    $expected = ['rows' => $detail['rows'], 'sha256' => $detail['sha256']];
                    if (hash('sha256', $schema['ddl']) !== $detail['schema'] || self::sourceDigest($source, $table, $detail['columns']) !== $expected
                        || self::targetDigest($target, $table, $detail['columns']) !== $expected) { throw new \RuntimeException('确认前数据发生改变或副本不一致，未切换：' . $table); }
                }
                if ($target->get_sqlite_pdo()->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { throw new \RuntimeException('副本完整性检查失败。'); }
                $target = null;
                require_once __DIR__ . '/setup.php';
                $helper = dirname($owned['database']) . '/wordpress-sqlite.php';
                $code = "<?php\n// WPLite: the original MySQL database is retained.\n";
                foreach (['DB_ENGINE' => 'sqlite', 'DB_PATH' => $owned['database'], 'WPLITE_PUBLIC_ROOT' => $owned['public']] as $name => $value) { $code .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n"; }
                $code .= 'require_once ' . var_export(__DIR__ . '/bootstrap.php', true) . ";\n";
                Setup::fresh($helper, $code);
                $updated = substr_replace($config['code'], 'require_once ' . var_export($helper, true) . ";\n", $config['offset'], 0);
                token_get_all($updated, TOKEN_PARSE);
                $temporary = dirname($config['path']) . '/.wplite-config-' . bin2hex(random_bytes(8)) . '.php';
                Setup::fresh($temporary, $updated, fileperms($config['path']) & 0777);
                if (!hash_equals($owned['config_hash'], hash_file('sha256', $config['path'])) || !rename($temporary, $config['path'])) { throw new \RuntimeException('配置发布失败，未切换。'); }
                $committed = true; // Atomic rename is the only database-selection commit point.
                if (function_exists('opcache_invalidate')) { opcache_invalidate($config['path'], true); }
            } finally { $source->query('UNLOCK TABLES'); $source->close(); }
            $owned = ['format' => 1, 'phase' => 'active', 'expires' => 0]; self::save($owned);
            wp_safe_redirect(admin_url());
        } catch (\Throwable $error) {
            if ($owned) {
                $owned['phase'] = $committed ? 'active' : 'failed'; $owned['expires'] = 0;
                try { self::save($owned); } catch (\Throwable) { /* No source data is deleted; the lease bounds an interrupted pause. */ }
            }
            wp_die(esc_html(($committed ? '配置已切换到 SQLite。' : '未切换，原站点继续使用 MySQL。') . $error->getMessage()), 'WPLite 迁移', ['response' => 500]);
        }
    }

    private static function confirmation(array $state, string $token): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>确认切换 SQLite</title><body style="font:16px/1.7 system-ui;max-width:720px;margin:40px auto;padding:20px"><h1>复制及校验完成，尚未切换</h1>';
        echo '<p>已校验 ' . count($state['tables']) . ' 张表、' . array_sum(array_column($state['tables'], 'rows')) . ' 行数据。原 MySQL 数据库保留。</p>';
        echo '<p>网站暂停中，请在 10 分钟内确认切换或取消；超时后原 MySQL 站点恢复，旧副本不能用于切换。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wplite_migration');
        echo '<input type="hidden" name="action" value="wplite_migration"><input type="hidden" name="wplite_migration_token" value="' . esc_attr($token) . '">';
        echo '<button name="operation" value="switch">确认切换到 SQLite</button> <button name="operation" value="cancel">取消并恢复 MySQL 站点</button></form></body></html>';
    }
}
