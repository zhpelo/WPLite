<?php
declare(strict_types=1);
namespace WPLite;

require_once __DIR__ . '/conversion-core.php';

/** Generates a new artifact from an isolated snapshot or a maintained source. */
final class CopyRunner
{
    use ConversionCore;
    public const VERSION = '1';
    public static string $stage = 'input';
    private static float $deadline = 0;

    public static function run(array $config): array
    {
        if (PHP_SAPI !== 'cli') { throw new \RuntimeException('cli_only'); }
        foreach (['database', 'prefix', 'output', 'wordpress_root', 'snapshot_id', 'host', 'user', 'password'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key])) { throw new \RuntimeException('invalid_input'); }
        }
        $maintained = ($config['maintained_source'] ?? false) === true;
        if (!(($maintained && preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $config['database']))
            || (!$maintained && preg_match('/^wplite_migration_[a-z0-9_]{1,40}$/D', $config['database'])))
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $config['prefix'])
            || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $config['snapshot_id'])
            || (!$maintained && ($config['isolated_snapshot'] ?? false) !== true)) {
            throw new \RuntimeException('isolated_snapshot_required');
        }
        // The parent is a private executor-owned directory, never a webroot.
        $output = $config['output'];
        $parent = realpath(dirname($output));
        if ($parent === false || $output !== $parent . '/' . basename($output)
            || in_array(basename($output), ['', '.', '..'], true) || file_exists($output) || is_link($output)
            || (fileperms($parent) & 0077) !== 0) { throw new \RuntimeException('private_new_output_required'); }
        if (!mkdir($output, 0700)) { throw new \RuntimeException('output_create_failed'); }
        self::$deadline = microtime(true) + max(1, min(86400, (int) ($config['timeout_seconds'] ?? 3600)));
        self::$stage = 'connect';
        // Trusted WordPress core supplied with the pinned converter, not the migrating site's plugins.
        require_once rtrim($config['wordpress_root'], '/') . '/wp-includes/class-wpdb.php';
        require_once __DIR__ . '/copy-source.php';
        if (defined('DB_NAME')) { throw new \RuntimeException('already_bootstrapped'); }
        define('DB_NAME', $config['database']);
        global $wpdb;
        $wpdb = new CopySource($config);
        $source = $wpdb;
        try {
            self::$stage = 'structure';
            $names = self::tables($source);
            if ($maintained) {
                // Hold every table read lock for the complete copy/verification.
                // ConversionCore's batch locks must not release this global set.
                $source->freeze($names);
                if (self::tables($source) !== $names) { throw new \RuntimeException('source_changed'); }
            }
            // Reject multisite rather than partially copying shared tables.
            if (in_array($config['prefix'] . 'blogs', $names, true)) { throw new \RuntimeException('multisite_unsupported'); }
            $state = ['phase' => 'copying', 'step' => 'schema', 'index' => 0, 'offset' => 0, 'cursor' => [], 'tables' => [], 'names' => $names];
            foreach ($names as $name) { self::schema($source, $name); }
            $target = self::target($output . '/site.sqlite', $source);
            $pdo = $target->get_sqlite_pdo();
            $pdo->exec('CREATE TABLE ' . self::HASHES . ' (table_name TEXT NOT NULL, pass TEXT NOT NULL, hash TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY(table_name,pass,hash)) WITHOUT ROWID');
            self::$stage = 'copy_verify';
            while ($state['step'] !== 'publish') {
                self::advance($state, $source, $target, 1000, min(microtime(true) + 2, self::$deadline));
            }
            if (self::tables($source) !== $names) { throw new \RuntimeException('source_changed'); }
            $pdo->exec('DROP TABLE ' . self::HASHES);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $pdo->exec('PRAGMA journal_mode=DELETE');
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { throw new \RuntimeException('integrity_failed'); }
            $pdo = null; $target = null;
            self::$stage = 'report';
            if (!chmod($output . '/site.sqlite', 0600)) { throw new \RuntimeException('output_permissions'); }
            $tables = [];
            foreach ($state['tables'] as $name => $table) {
                $tables[] = ['name' => $name, 'rows' => $table['rows'], 'content_omitted' => $table['skip_content'], 'schema_sha256' => $table['schema']];
            }
            $report = ['format' => 1, 'status' => 'verified', 'snapshot_id' => $config['snapshot_id'], 'core_version' => self::VERSION,
                'consistency' => $maintained ? 'maintained_source_read_lock' : 'isolated_snapshot',
                'core_sha256' => hash_file('sha256', __DIR__ . '/conversion-core.php'), 'sqlite_driver_version' => SQLITE_DRIVER_VERSION,
                'database' => ['file' => 'site.sqlite', 'sha256' => hash_file('sha256', $output . '/site.sqlite'), 'bytes' => filesize($output . '/site.sqlite')],
                'restore' => ['table_prefix' => $config['prefix'], 'database_file' => 'site.sqlite'], 'tables' => $tables,
                'intentional_omissions' => ['empty_tables' => array_map(fn ($name) => $config['prefix'] . $name, self::EMPTY_TABLES),
                    'options_name_prefixes' => ['_transient_', '_site_transient_']], 'source_configuration_changed' => false];
            $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($output . '/report.json.tmp', $json . "\n") === false || !rename($output . '/report.json.tmp', $output . '/report.json')) {
                throw new \RuntimeException('report_failed');
            }
            return $report;
        } finally { $source->close(); }
    }
}
