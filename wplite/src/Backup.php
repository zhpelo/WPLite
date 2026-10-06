<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/Environment.php';

/** Consistent, authenticated database snapshots. No live-file copying. */
final class Backup
{
    public static function canonical(array $manifest): string
    {
        unset($manifest['hmac_sha256']);
        ksort($manifest, SORT_STRING);
        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function backupKey(array $state): string
    {
        $key = $state['backup_key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \RuntimeException('This installation has no valid private backup key. Create a backup first.');
        }
        return $key;
    }

    private static function target(string $path, string $publicRoot): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('Backup output must be an absolute path without parent traversal.');
        }
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent)) {
            throw new \RuntimeException('Backup output parent must already exist.');
        }
        $target = $parent . '/' . basename($path);
        if (Environment::isWithin($target, $publicRoot) || file_exists($target) || is_link($target)) {
            throw new \RuntimeException('Refusing to overwrite an existing backup or store it in the public document root.');
        }
        return $target;
    }

    private static function durableWrite(string $file, string $contents): void
    {
        $handle = fopen($file, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot exclusively create the backup manifest.');
        }
        chmod($file, 0600);
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('Cannot durably write the backup manifest.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function create(\PDO $source, string $root, string $data, string $siteId,
                                  string $prefix, ?string $output = null, ?string $publicRoot = null): array
    {
        Environment::requireSupported();
        $root = realpath($root);
        $data = realpath($data);
        $publicRoot = realpath($publicRoot ?? $root);
        if ($root === false || $data === false || $publicRoot === false
            || !Environment::isWithin($root, $publicRoot) || Environment::isWithin($data, $publicRoot)) {
            throw new \RuntimeException('A private data directory and the real public document root are required.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix) || !preg_match('/^[a-f0-9]{48}$/D', $siteId)) {
            throw new \RuntimeException('Invalid site identity or table prefix.');
        }
        if ($source->inTransaction()) {
            throw new \RuntimeException('Commit or roll back the active transaction before creating a backup.');
        }
        $operation = fopen($data . '/.operations.lock', 'c');
        if ($operation === false || !flock($operation, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another WPLite maintenance operation is running.');
        }
        try {
            $state = Environment::readState($data);
            if (($state['site_id'] ?? '') !== $siteId || ($state['status'] ?? '') !== 'installed') {
                throw new \RuntimeException('Backup source does not match an installed site.');
            }
            if (!isset($state['backup_key'])) {
                // Add only to the private state. Never include this secret in a
                // snapshot, manifest, diagnostic response or distribution ZIP.
                $state['backup_key'] = bin2hex(random_bytes(32));
                Environment::writeState($data, $state);
            }
            $key = self::backupKey($state);
            if ($output === null) {
                $parent = $data . '/backups';
                if (!file_exists($parent) && !mkdir($parent, 0700)) {
                    throw new \RuntimeException('Cannot create the private backup directory.');
                }
                $output = $parent . '/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
            }
            $target = self::target($output, $publicRoot);
            $bytes = (int) $source->query('PRAGMA page_count')->fetchColumn()
                * (int) $source->query('PRAGMA page_size')->fetchColumn();
            $free = disk_free_space(dirname($target));
            if ($free !== false && $free < $bytes * 2 + 16 * 1024 * 1024) {
                throw new \RuntimeException('Insufficient free space for a consistent database snapshot.');
            }
            if (!mkdir($target, 0700)) {
                throw new \RuntimeException('Cannot exclusively create the private backup directory.');
            }
            $file = $target . '/database.sqlite';
            $query = $source->prepare('VACUUM INTO :destination');
            $query->execute([':destination' => $file]);
            $query->closeCursor();
            chmod($file, 0600);
            $handle = fopen($file, 'r+b');
            if ($handle === false) {
                throw new \RuntimeException('Cannot flush the database snapshot.');
            }
            try {
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                    throw new \RuntimeException('Cannot durably flush the database snapshot.');
                }
            } finally {
                fclose($handle);
            }
            $snapshot = self::openSnapshot($file);
            $details = self::databaseDetails($snapshot, $prefix);
            if (!hash_equals($siteId, $details['site_id'])) {
                throw new \RuntimeException('Snapshot belongs to a different site.');
            }
            unset($snapshot);
            $distribution = json_decode(file_get_contents($root . '/wplite/distribution.json'), true, flags: JSON_THROW_ON_ERROR);
            $manifest = ['format' => 1, 'kind' => 'database', 'site_id' => $siteId,
                'table_prefix' => $prefix, 'created_at' => gmdate('c'),
                'database_bytes' => filesize($file), 'database_sha256' => hash_file('sha256', $file),
                'wordpress' => $distribution['wordpress']['tag'], 'sqlite_driver' => $distribution['sqlite']['tag'],
                'distribution_sha256' => hash_file('sha256', $root . '/wplite/distribution.json'),
                'wordpress_db_version' => $details['db_version']];
            $manifest['hmac_sha256'] = hash_hmac('sha256', self::canonical($manifest), $key);
            self::durableWrite($target . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            self::verify($target, $key, $siteId);
            return ['status' => 'created', 'kind' => 'database', 'directory' => $target,
                'database_sha256' => $manifest['database_sha256'], 'bytes' => $manifest['database_bytes'],
                'authenticated' => true];
        } finally {
            flock($operation, LOCK_UN);
            fclose($operation);
        }
    }

    private static function openSnapshot(string $file, bool $immutable = true): \PDO
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read the database snapshot.');
        }
        try {
            if (fread($handle, 16) !== "SQLite format 3\0") {
                throw new \RuntimeException('Backup is not an SQLite database.');
            }
        } finally {
            fclose($handle);
        }
        $uri = 'file:' . str_replace('%2F', '/', rawurlencode($file)) . ($immutable ? '?mode=ro&immutable=1' : '?mode=rw');
        return new \PDO('sqlite:' . $uri, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public static function inspectDatabase(string $file, string $prefix, bool $immutable = true): array
    {
        if (!is_file($file) || is_link($file) || !preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
            throw new \RuntimeException('An existing regular database and valid table prefix are required.');
        }
        $pdo = self::openSnapshot($file, $immutable);
        return self::databaseDetails($pdo, $prefix);
    }

    private static function databaseDetails(\PDO $pdo, string $prefix): array
    {
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new \RuntimeException('Backup SQLite integrity check failed.');
        }
        $query = $pdo->prepare('SELECT option_value FROM "' . $prefix . 'options" WHERE option_name = :name');
        $query->execute([':name' => 'wplite_site_id']);
        $id = $query->fetchColumn();
        $query->execute([':name' => 'db_version']);
        return ['site_id' => (string) $id, 'db_version' => (string) $query->fetchColumn()];
    }

    public static function verify(string $directory, string $key, string $siteId): array
    {
        Environment::requireSupported();
        if (is_link($directory)) {
            throw new \RuntimeException('Backup directory must not be a symbolic link.');
        }
        $directory = realpath($directory);
        if ($directory === false || !is_dir($directory) || is_link($directory)) {
            throw new \RuntimeException('Backup directory does not exist or is a symbolic link.');
        }
        foreach (['manifest.json', 'database.sqlite'] as $name) {
            if (!is_file($directory . '/' . $name) || is_link($directory . '/' . $name)) {
                throw new \RuntimeException('Backup is incomplete or contains a symbolic link.');
            }
        }
        if (filesize($directory . '/manifest.json') > 64 * 1024) {
            throw new \RuntimeException('Backup manifest exceeds the size limit.');
        }
        $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ($manifest['kind'] ?? '') !== 'database'
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $manifest['table_prefix'] ?? '')
            || !is_int($manifest['database_bytes'] ?? null) || $manifest['database_bytes'] < 16
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['database_sha256'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['hmac_sha256'] ?? '')
            || !hash_equals($siteId, $manifest['site_id'] ?? '')) {
            throw new \RuntimeException('Invalid backup manifest or mismatched site identity.');
        }
        if (!hash_equals(hash_hmac('sha256', self::canonical($manifest), $key), $manifest['hmac_sha256'])) {
            throw new \RuntimeException('Backup manifest authentication failed.');
        }
        $file = $directory . '/database.sqlite';
        if (filesize($file) !== $manifest['database_bytes']
            || !hash_equals($manifest['database_sha256'], hash_file('sha256', $file))) {
            throw new \RuntimeException('Backup database size or SHA-256 check failed.');
        }
        $snapshot = self::openSnapshot($file);
        $details = self::databaseDetails($snapshot, $manifest['table_prefix']);
        if (!hash_equals($siteId, $details['site_id']) || $details['db_version'] !== $manifest['wordpress_db_version']) {
            throw new \RuntimeException('Backup database identity or schema version does not match its manifest.');
        }
        unset($snapshot);
        return ['status' => 'verified', 'kind' => 'database', 'authenticated' => true,
            'site_id' => $siteId, 'wordpress' => $manifest['wordpress'], 'sqlite_driver' => $manifest['sqlite_driver'],
            'table_prefix' => $manifest['table_prefix'], 'wordpress_db_version' => $manifest['wordpress_db_version'],
            'distribution_sha256' => $manifest['distribution_sha256'], 'database_sha256' => $manifest['database_sha256'],
            'bytes' => $manifest['database_bytes']];
    }
}
