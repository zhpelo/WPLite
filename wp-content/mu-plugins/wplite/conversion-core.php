<?php
declare(strict_types=1);

namespace WPLite;

/** Shared copy/verification rules for browser migration and isolated CLI copies. */
trait ConversionCore
{
    private const EMPTY_TABLES = ['actionscheduler_logs', 'woocommerce_log', 'woocommerce_sessions'];
    private const HASHES = '_wp_sqlite_wplite_migration_hashes';
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
}
