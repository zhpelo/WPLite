<?php
declare(strict_types=1);

namespace WPLite;

/** Browser-driven MySQL migration. No background jobs or automatic retry. */
final class Migration
{
    private const STATE = __DIR__ . '/migration-state.php';
    private const STATE_HEADER = "<?php http_response_code(404); exit; __halt_compiler();\n";
    private static $gate = null;
    private static ?\mysqli $readOnlyConnection = null;
    private static float $deadline = 0;
    private const REQUEST_SECONDS = 8;
    private const BATCH_ROWS = 1000;
    private const EMPTY_TABLES = ['actionscheduler_logs', 'woocommerce_log', 'woocommerce_sessions'];
    private const HASHES = '_wp_sqlite_wplite_migration_hashes';

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

    public static function notice(): void
    {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="notice notice-info is-dismissible"><p>WPLite：当前站点使用 MySQL，是否迁移到 SQLite？开始后将连续完成复制、校验和自动切换，原 MySQL 数据库会保留。</p>';
        echo '<p>任务执行日志、WooCommerce 运行日志、购物车会话仅迁移表结构；WordPress 临时缓存不迁移。访客尚未结账的购物车会被清空。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wplite_migration');
        echo '<input type="hidden" name="action" value="wplite_migration"><input type="hidden" name="operation" value="start">';
        echo '<p><label><input type="checkbox" name="accept_pause" value="1" required> 我接受短暂停站及校验后自动切换，已等待部署前的旧请求结束，并停止其他程序直接写入数据库。</label></p>';
        echo '<p><button class="button button-primary">开始迁移，完成后自动切换</button> 暂不迁移可关闭此提示。</p></form></div>';
    }

    private static function tick(): void
    {
        if (microtime(true) > self::$deadline) { throw new \RuntimeException('本批处理超时，迁移已停止，未切换数据库。'); }
    }

    private static function identifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

    private static function skipContent(string $table): bool
    {
        global $wpdb;
        return in_array($table, array_map(static fn (string $name): string => $wpdb->prefix . $name, self::EMPTY_TABLES), true);
    }

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
        self::query($source, "SHOW INDEX FROM " . $name . " WHERE Key_name = 'PRIMARY'");
        $primary = [];
        foreach ($source->last_result as $key) { $primary[(int) $key->Seq_in_index] = $key->Column_name; }
        ksort($primary);
        return ['ddl' => $ddl, 'columns' => $columns, 'order' => array_values($primary), 'primary' => (bool) $primary];
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

    private static function compareHashes(\PDO $pdo, string $table, string $pass): void
    {
        // Counted SHA-256 multisets preserve duplicate rows without loading the
        // entire table or its hashes into a PHP request's memory.
        $sql = 'SELECT 1 FROM ' . self::HASHES . ' a LEFT JOIN ' . self::HASHES . ' b'
            . ' ON b.table_name=a.table_name AND b.hash=a.hash AND b.pass=?'
            . ' WHERE a.table_name=? AND a.pass=? AND (b.n IS NULL OR b.n<>a.n) LIMIT 1';
        $statement = $pdo->prepare($sql);
        foreach ([['copy', $pass], [$pass, 'copy']] as [$left, $right]) {
            $statement->execute([$right, $table, $left]);
            if ($statement->fetchColumn() !== false) { throw new \RuntimeException('数据校验不一致，未切换：' . $table); }
        }
    }

    private static function batchRows(array &$state, \wpdb $source, \WP_MySQL_On_SQLite $target, string $table, int $limit, float $until): bool
    {
        $copy = $state['step'] === 'copy';
        $fromSource = $copy;
        $columns = $state['tables'][$table]['columns'];
        $pdo = $target->get_sqlite_pdo();
        $offset = (int) $state['offset'];
        $cursor = $state['cursor'] ?? [];
        $where = [];
        $select = '*';
        $order = 'rowid';
        $rowidAlias = null;
        if ($fromSource) {
            global $wpdb;
            if ($table === $wpdb->options) {
                $where[] = "LEFT(option_name,11)<>'_transient_' AND LEFT(option_name,16)<>'_site_transient_'";
            }
            if ($state['tables'][$table]['primary']) {
                $keys = $state['tables'][$table]['order'];
                $order = implode(',', array_map(self::identifier(...), $keys));
                if ($cursor) {
                    $values = [];
                    foreach ($keys as $i => $name) {
                        $binary = preg_match('/\b(blob|binary|varbinary|bit)\b/i', $columns[$name]);
                        $value = base64_decode($cursor[$i], true);
                        $literal = $binary ? "X'" . bin2hex($value) . "'" : $target->quote($value);
                        // Row-constructor comparisons must not convert BIGINT
                        // cursor strings to floating point above 2^53.
                        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\b/i', $columns[$name])) {
                            $literal = 'CAST(' . $literal . ' AS ' . (stripos($columns[$name], 'unsigned') !== false ? 'UNSIGNED' : 'SIGNED') . ')';
                        } elseif (preg_match('/^(?:decimal|numeric)(\(\d+,\d+\))/i', $columns[$name], $precision)) {
                            $literal = 'CAST(' . $literal . ' AS DECIMAL' . $precision[1] . ')';
                        }
                        $values[] = $literal;
                    }
                    $where[] = '(' . $order . ')>(' . implode(',', $values) . ')';
                }
                $offset = 0;
            } else {
                // Filesort ties (collation or max_sort_length) can repeat/skip
                // distinct rows across LIMIT pages. A full binary row digest
                // only leaves ties between identical rows, whose counts matter.
                $parts = array_map(static fn (string $name): string => "COALESCE(HEX(CAST(" . self::identifier($name) . " AS BINARY)),'NULL')", array_keys($columns));
                $order = "SHA2(CONCAT_WS('|'," . implode(',', $parts) . '),256)';
            }
        } else {
            // Every translated table is a rowid table. Avoid aliases shadowed
            // by real user columns; fall back to OFFSET only if all are shadowed.
            $names = array_map('strtolower', array_keys($columns));
            foreach (['_rowid_', 'rowid', 'oid'] as $name) {
                if (in_array($name, $names, true)) { continue; }
                $rowidAlias = '_wplite_migration_rowid';
                while (in_array(strtolower($rowidAlias), $names, true)) { $rowidAlias .= '_'; }
                $order = self::identifier($name);
                $select .= ', ' . $order . ' AS ' . self::identifier($rowidAlias);
                if ($cursor) { $where[] = $order . '>' . $pdo->quote(base64_decode($cursor[0], true)); }
                $offset = 0;
                break;
            }
        }
        $sql = 'SELECT ' . $select . ' FROM ' . self::identifier($table)
            . ($where ? ' WHERE (' . implode(') AND (', $where) . ')' : '')
            . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        if ($fromSource) {
            $rows = $source->dbh->query($sql, MYSQLI_USE_RESULT);
            if (!$rows) { throw new \RuntimeException('无法读取表：' . $table); }
        } else { $rows = $pdo->query($sql); }
        $pass = $copy ? 'copy' : $state['phase'] . '-' . $state['step'];
        $hash = $pdo->prepare('INSERT INTO ' . self::HASHES . ' (table_name,pass,hash,n) VALUES (?,?,?,1)'
            . ' ON CONFLICT(table_name,pass,hash) DO UPDATE SET n=n+1');
        $insert = 'INSERT INTO ' . self::identifier($table) . ' (' . implode(',', array_map(self::identifier(...), array_keys($columns))) . ') VALUES ';
        $processed = 0; $ended = false;
        $pending = []; $bytes = 0;
        $target->beginTransaction();
        try {
            while ($processed < $limit) {
                self::tick();
                $row = $fromSource ? $rows->fetch_assoc() : $rows->fetch(\PDO::FETCH_ASSOC);
                if ($row === null || $row === false) { $ended = true; break; }
                if ($copy) {
                    $values = [];
                    foreach ($columns as $name => $type) {
                        $binary = preg_match('/\b(blob|binary|varbinary|bit)\b/i', $type);
                        // Hex literals preserve binary values (including empty
                        // blobs) in the upstream driver's multi-row INSERT path.
                        $values[] = $row[$name] === null ? 'NULL'
                            : ($binary ? "X'" . bin2hex($row[$name]) . "'" : $target->quote($row[$name]));
                    }
                    $tuple = '(' . implode(',', $values) . ')';
                    // Keep SQL parsing bounded for wide rows and large blobs.
                    if ($pending && $bytes + strlen($tuple) > 262144) {
                        $target->exec($insert . implode(',', $pending)); $pending = []; $bytes = 0;
                    }
                    $pending[] = $tuple; $bytes += strlen($tuple);
                    if (count($pending) >= 25 || $bytes >= 262144) {
                        $target->exec($insert . implode(',', $pending)); $pending = []; $bytes = 0;
                    }
                }
                $hash->execute([$table, $pass, self::rowHash($row, $columns)]);
                if ($fromSource && $state['tables'][$table]['primary']) {
                    $state['cursor'] = array_map(static fn (string $name): string => base64_encode((string) $row[$name]), $state['tables'][$table]['order']);
                } elseif ($rowidAlias !== null) {
                    $state['cursor'] = [base64_encode((string) $row[$rowidAlias])];
                }
                ++$processed;
                // Yield instead of throwing when normal batch work is used up.
                if (microtime(true) >= $until) { break; }
            }
            if ($pending) { $target->exec($insert . implode(',', $pending)); }
            $target->commit();
        } catch (\Throwable $error) {
            if ($target->inTransaction()) { $target->rollBack(); }
            throw $error;
        } finally {
            if ($fromSource) { $rows->free(); } else { $rows->closeCursor(); }
        }
        $state['offset'] += $processed;
        if ($ended) {
            if ($copy) { $state['tables'][$table]['rows'] = $state['offset']; }
            elseif ($state['offset'] !== $state['tables'][$table]['rows']) { throw new \RuntimeException('数据行数已改变，未切换：' . $table); }
            if (!$copy) { self::compareHashes($pdo, $table, $pass); }
        }
        return $ended;
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
        $mode = (string) array_values((array) $source->last_result[0])[0];
        $modes = preg_split('/\s*,\s*/', trim($mode), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Removed in MySQL 8: this only controlled whether GRANT created users.
        // WPLite migrates tables and rows, never accounts or GRANT statements.
        $modes = array_values(array_filter($modes, static fn (string $item): bool => strcasecmp($item, 'NO_AUTO_CREATE_USER') !== 0));
        $target->exec('SET SESSION sql_mode = ' . $target->quote(implode(',', $modes)));
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
        Setup::protectedStorage($root, $directory, rtrim(site_url('', is_ssl() ? 'https' : 'http'), '/'));
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

    private static function nextTable(array &$state): void
    {
        $state['offset'] = 0; $state['cursor'] = [];
        ++$state['index'];
        if ($state['index'] < count($state['names'])) {
            if ($state['step'] === 'copy') { $state['step'] = 'schema'; }
            return;
        }
        $state['index'] = 0;
        // Compare the candidate with the hashes captured during copying.
        // The source remains paused until automatic publication.
        $state['step'] = match ($state['step']) {
            'copy' => 'target',
            'target' => 'integrity',
        };
    }

    /** Process one step and return the number of rows consumed from this request's budget. */
    private static function advance(array &$state, \wpdb $source, \WP_MySQL_On_SQLite $target, int $limit, float $until): int
    {
        $names = $state['names'];
        $index = $state['index'];
        $pdo = $target->get_sqlite_pdo();
        if ($state['step'] === 'integrity') {
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { throw new \RuntimeException('SQLite 完整性检查失败。'); }
            self::tick();
            $state['step'] = 'publish';
            return 0;
        }
        $table = $names[$index];
        self::query($source, 'SET SESSION lock_wait_timeout = 2');
        self::query($source, 'LOCK TABLES ' . self::identifier($table) . ' READ');
        try {
            if ($state['step'] === 'schema') {
                $schema = self::schema($source, $table);
                $target->exec($schema['ddl']);
                $state['tables'][$table] = ['schema' => hash('sha256', $schema['ddl']),
                    'columns' => $schema['columns'], 'order' => $schema['order'], 'primary' => $schema['primary'],
                    'skip_content' => self::skipContent($table), 'rows' => 0];
                $state['step'] = 'copy';
                if ($state['tables'][$table]['skip_content']) { self::nextTable($state); }
                return 0;
            }
            if ($state['step'] === 'target' && $state['offset'] === 0) {
                $schema = self::schema($source, $table);
                if (hash('sha256', $schema['ddl']) !== $state['tables'][$table]['schema']) {
                    throw new \RuntimeException('源数据表结构已改变，未切换：' . $table);
                }
            }
            if ($state['tables'][$table]['skip_content']) {
                if ($state['step'] === 'target' && $pdo->query('SELECT 1 FROM ' . self::identifier($table) . ' LIMIT 1')->fetchColumn() !== false) {
                    throw new \RuntimeException('应为空的日志或会话表包含数据，未切换：' . $table);
                }
                self::nextTable($state);
                return 0;
            }
            $before = $state['offset'];
            $ended = self::batchRows($state, $source, $target, $table, $limit, $until);
            $processed = $state['offset'] - $before;
            if (!$ended) { return $processed; }
            self::nextTable($state);
            return $processed;
        } finally { $source->query('UNLOCK TABLES'); }
    }

    private static function publish(array $state, bool &$committed): void
    {
        $config = self::checkConfiguration($state);
        require_once __DIR__ . '/setup.php';
        $helper = dirname($state['database']) . '/wordpress-sqlite.php';
        $code = "<?php\n// WPLite: the original MySQL database is retained.\n";
        $constants = ['DB_ENGINE' => 'sqlite', 'DB_PATH' => $state['database'], 'WPLITE_PUBLIC_ROOT' => $state['public']];
        if (isset($state['storage_deny_hash'])) { $constants['WPLITE_STORAGE_DENY_HASH'] = $state['storage_deny_hash']; }
        foreach ($constants as $name => $value) { $code .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n"; }
        $code .= 'require_once ' . var_export(__DIR__ . '/bootstrap.php', true) . ";\n";
        Setup::fresh($helper, $code);
        $updated = substr_replace($config['code'], 'require_once ' . var_export($helper, true) . ";\n", $config['offset'], 0);
        token_get_all($updated, TOKEN_PARSE);
        $temporary = dirname($config['path']) . '/.wplite-config-' . bin2hex(random_bytes(8)) . '.php';
        Setup::fresh($temporary, $updated, fileperms($config['path']) & 0777);
        self::tick();
        if (!hash_equals($state['config_hash'], hash_file('sha256', $config['path'])) || !rename($temporary, $config['path'])) {
            throw new \RuntimeException('配置发布失败，未切换。');
        }
        $committed = true;
        if (function_exists('opcache_invalidate')) { opcache_invalidate($config['path'], true); }
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
