<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/Restore.php';

/** Stopped-writer, data-only full site snapshots. Never execute copied PHP. */
final class SiteBackup
{
    private const MAX_ENTRIES = 50000;
    private const MAX_MANIFEST = 16 * 1024 * 1024;

    private static function site(string $root, string $data): array
    {
        Environment::requireSupported();
        if (is_link($root) || is_link($data)) {
            throw new \RuntimeException('Site backup requires real installation and data directories.');
        }
        $root = realpath($root);
        $data = realpath($data);
        if ($root === false || $data === false || !is_file($data . '/site.json')
            || is_link($data . '/site.json') || filesize($data . '/site.json') > 1024 * 1024) {
            throw new \RuntimeException('The installed site and original private state are required.');
        }
        $state = Environment::readState($data);
        Storage::assertInstalled($data, $state);
        if (($state['root'] ?? '') !== $root || ($state['status'] ?? '') !== 'installed'
            || ($state['database'] ?? '') !== $data . '/site.sqlite'
            || !preg_match('/^[a-f0-9]{48}$/D', $state['site_id'] ?? '')
            || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $state['table_prefix'] ?? '')
            || realpath($state['public_root'] ?? '') !== ($state['public_root'] ?? '')
            || !Environment::isWithin($root, $state['public_root'])
            || Environment::isWithin($root, $data)) {
            throw new \RuntimeException('Full backup state does not match this installed site.');
        }
        return [$root, $data, $state];
    }

    private static function relative(string $path): void
    {
        if ($path === '' || strlen($path) > 4096 || str_starts_with($path, '/')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)
            || count(explode('/', $path)) > 64
            || array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new \RuntimeException('Invalid relative full backup path.');
        }
    }

    private static function stat(string $file, bool $private = false): array
    {
        clearstatcache(true, $file);
        $stat = lstat($file);
        $type = $stat === false ? 0 : $stat['mode'] & 0170000;
        if (!in_array($type, [0100000, 0040000], true)
            || ($type === 0100000 && $stat['nlink'] !== 1)
            || ($private && ($stat['mode'] & 0077) !== 0)) {
            throw new \RuntimeException('Full backups reject links, special files and non-private snapshot entries.');
        }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
    }

    private static function inventory(string $root, ?string $exclude = null, bool $private = false): array
    {
        self::stat($root, $private);
        $files = $directories = [];
        $walk = static function (string $relative) use (&$walk, &$files, &$directories, $root, $exclude, $private): void {
            $parent = $root . ($relative === '' ? '' : '/' . $relative);
            $names = scandir($parent);
            if ($names === false) {
                throw new \RuntimeException('Cannot enumerate all site files.');
            }
            foreach (array_diff($names, ['.', '..']) as $name) {
                $path = $relative === '' ? $name : $relative . '/' . $name;
                self::relative($path);
                $file = $root . '/' . $path;
                if ($exclude !== null && $file === $exclude) {
                    continue; // The entire bound data tree, never arbitrary user files.
                }
                if (count($files) + count($directories) >= self::MAX_ENTRIES) {
                    throw new \RuntimeException('Full backup exceeds the supported file count.');
                }
                $stat = self::stat($file, $private);
                if (($stat['mode'] & 0170000) === 0040000) {
                    $directories[$path] = $stat;
                    $walk($path);
                } else {
                    $digest = hash_file('sha256', $file);
                    if ($digest === false || self::stat($file, $private) !== $stat) {
                        throw new \RuntimeException('A site file changed while being inventoried.');
                    }
                    $files[$path] = $stat + ['sha256' => $digest];
                }
            }
        };
        $walk('');
        ksort($files, SORT_STRING);
        ksort($directories, SORT_STRING);
        return ['files' => $files, 'directories' => $directories];
    }

    private static function copyFile(string $source, string $destination, array $expected): void
    {
        if (self::stat($source) !== array_diff_key($expected, ['sha256' => true])) {
            throw new \RuntimeException('Source file changed before backup copying.');
        }
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'x+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
            throw new \RuntimeException('Cannot exclusively copy a site file.');
        }
        try {
            chmod($destination, 0600);
            $opened = fstat($input);
            if ($opened['ino'] !== $expected['ino'] || $opened['dev'] !== $expected['dev']
                || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1
                || stream_copy_to_stream($input, $output) !== $expected['size']
                || !fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new \RuntimeException('Cannot durably copy the complete original site file.');
            }
            if (self::stat($source) !== array_diff_key($expected, ['sha256' => true])
                || !hash_equals($expected['sha256'], hash_file('sha256', $destination))) {
                throw new \RuntimeException('Source or destination changed during full backup.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public static function create(string $root, string $data, ?string $output = null): array
    {
        [$root, $data, $state] = self::site($root, $data);
        $lease = Maintenance::acquire($data, type: 'site-backup');
        try {
            Restore::assertRuntime($root);
            $state = Environment::readState($data);
            if (!isset($state['backup_key'])) {
                $state['backup_key'] = bin2hex(random_bytes(32));
                Environment::writeState($data, $state);
            }
            $key = Backup::backupKey($state);
            if ($output === null) {
                $parent = $data . '/backups';
                if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) {
                    throw new \RuntimeException('Invalid private backup parent.');
                }
                if (!is_dir($parent) && !mkdir($parent, 0700)) {
                    throw new \RuntimeException('Cannot create the private backup parent.');
                }
                $output = $parent . '/site-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
            }
            $target = Backup::target($output, $state['public_root'], $data);
            $lease->record('inventory');
            $original = self::inventory($root, $data);
            foreach (['', '-wal', '-shm'] as $suffix) {
                $file = $state['database'] . $suffix;
                if ($suffix === '' || file_exists($file) || is_link($file)) {
                    $stat = self::stat($file);
                    if (($stat['mode'] & 0170000) !== 0100000) {
                        throw new \RuntimeException('The live database components must be regular files.');
                    }
                }
            }
            $uri = 'sqlite:file:' . str_replace('%2F', '/', rawurlencode($state['database'])) . '?mode=rw';
            $pdo = new \PDO($uri, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000');
            $databaseBytes = (int) $pdo->query('PRAGMA page_count')->fetchColumn()
                * (int) $pdo->query('PRAGMA page_size')->fetchColumn();
            $total = array_sum(array_column($original['files'], 'size'));
            $free = disk_free_space(dirname($target));
            if (!is_int($total) || !is_int($databaseBytes) || $free === false
                || $free < $total + $databaseBytes * 2 + 16 * 1024 * 1024) {
                throw new \RuntimeException('Insufficient or unknown free space for full site backup.');
            }
            if (!mkdir($target, 0700) || !mkdir($target . '/site', 0700)) {
                throw new \RuntimeException('Cannot exclusively create the full site snapshot.');
            }
            $lease->record('snapshotting');
            $details = Backup::snapshot($pdo, $target . '/database.sqlite', $state['table_prefix'], $state['site_id']);
            unset($pdo);
            $lease->record('copying');
            $directories = $files = [];
            foreach ($original['directories'] as $relative => $stat) {
                if (!mkdir($target . '/site/' . $relative, 0700)) {
                    throw new \RuntimeException('Cannot create a complete snapshot directory tree.');
                }
                $directories[$relative] = $stat['mode'] & 0777;
            }
            foreach ($original['files'] as $relative => $stat) {
                self::copyFile($root . '/' . $relative, $target . '/site/' . $relative, $stat);
                $files[$relative] = ['bytes' => $stat['size'], 'sha256' => $stat['sha256'], 'mode' => $stat['mode'] & 0777];
            }
            if (self::inventory($root, $data) !== $original) {
                throw new \RuntimeException('Site files changed outside the drained request protocol; no full backup was committed.');
            }
            $savedState = $state;
            unset($savedState['backup_key']);
            $manifest = ['format' => 1, 'kind' => 'site', 'created_at' => gmdate('c'),
                'site_id' => $state['site_id'], 'table_prefix' => $state['table_prefix'],
                'site_state' => $savedState, 'files' => $files, 'directories' => $directories,
                'excluded_paths' => Environment::isWithin($data, $root) ? [substr($data, strlen($root) + 1)] : [],
                'database_bytes' => filesize($target . '/database.sqlite'),
                'database_sha256' => hash_file('sha256', $target . '/database.sqlite'),
                'wordpress_db_version' => $details['db_version'],
                'distribution_sha256' => $files['wplite/distribution.json']['sha256']];
            $manifest['hmac_sha256'] = hash_hmac('sha256', Backup::canonical($manifest), $key);
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (strlen($json) > self::MAX_MANIFEST) {
                throw new \RuntimeException('Full site manifest exceeds the supported size.');
            }
            Backup::durableWrite($target . '/manifest.json', $json);
            $verified = self::verify($target, $key, $state['site_id']);
            $lease->finish();
            return array_replace($verified, ['directory' => $target, 'status' => 'created']);
        } catch (\Throwable $error) {
            // No live file/database replacement occurs during backup. A caught
            // failure may safely reopen; a killed owner leaves the durable marker.
            $lease->finish();
            throw $error;
        }
    }

    public static function verify(string $directory, string $key, string $siteId): array
    {
        Environment::requireSupported();
        Backup::backupKey(['backup_key' => $key]);
        if (is_link($directory) || ($directory = realpath($directory)) === false) {
            throw new \RuntimeException('Full backup directory is missing or linked.');
        }
        self::stat($directory, true);
        $names = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($names, SORT_STRING);
        if ($names !== ['database.sqlite', 'manifest.json', 'site']) {
            throw new \RuntimeException('Full backup is incomplete or has unsigned extra files.');
        }
        foreach (['manifest.json', 'database.sqlite'] as $name) {
            $stat = self::stat($directory . '/' . $name, true);
            if (($stat['mode'] & 0170000) !== 0100000) {
                throw new \RuntimeException('Full backup payload must contain regular private files.');
            }
        }
        if (filesize($directory . '/manifest.json') > self::MAX_MANIFEST) {
            throw new \RuntimeException('Full backup manifest exceeds its size limit.');
        }
        $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ($manifest['kind'] ?? '') !== 'site'
            || !is_array($manifest['files'] ?? null) || !is_array($manifest['directories'] ?? null)
            || count($manifest['files']) + count($manifest['directories']) > self::MAX_ENTRIES
            || !is_array($manifest['site_state'] ?? null) || isset($manifest['site_state']['backup_key'])
            || !is_string($manifest['site_id'] ?? null) || !hash_equals($siteId, $manifest['site_id'])
            || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $manifest['table_prefix'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['hmac_sha256'] ?? '')) {
            throw new \RuntimeException('Invalid full backup manifest or site identity.');
        }
        if (!hash_equals(hash_hmac('sha256', Backup::canonical($manifest), $key), $manifest['hmac_sha256'])) {
            throw new \RuntimeException('Full backup manifest authentication failed.');
        }
        $actual = self::inventory($directory . '/site', private: true);
        if (array_keys($actual['files']) !== array_keys($manifest['files'])
            || array_keys($actual['directories']) !== array_keys($manifest['directories'])) {
            throw new \RuntimeException('Full backup file tree differs from its authenticated manifest.');
        }
        $bytes = 0;
        foreach ($manifest['directories'] as $path => $mode) {
            self::relative((string) $path);
            if (!is_int($mode) || $mode < 0 || $mode > 0777) {
                throw new \RuntimeException('Invalid original directory permissions.');
            }
        }
        foreach ($manifest['files'] as $path => $record) {
            self::relative((string) $path);
            $stat = $actual['files'][$path];
            if (!is_array($record) || !is_int($record['bytes'] ?? null) || $record['bytes'] < 0
                || !is_int($record['mode'] ?? null) || $record['mode'] < 0 || $record['mode'] > 0777
                || !preg_match('/^[a-f0-9]{64}$/D', $record['sha256'] ?? '')
                || $stat['size'] !== $record['bytes'] || !hash_equals($record['sha256'], $stat['sha256'])) {
                throw new \RuntimeException('Full backup file size, SHA-256 or mode record failed verification.');
            }
            $bytes += $record['bytes'];
        }
        if (!is_int($manifest['database_bytes'] ?? null) || $manifest['database_bytes'] < 16
            || filesize($directory . '/database.sqlite') !== $manifest['database_bytes']
            || !preg_match('/^[a-f0-9]{64}$/D', $manifest['database_sha256'] ?? '')
            || !hash_equals($manifest['database_sha256'], hash_file('sha256', $directory . '/database.sqlite'))
            || ($manifest['site_state']['site_id'] ?? '') !== $siteId
            || ($manifest['site_state']['table_prefix'] ?? '') !== $manifest['table_prefix']
            || ($manifest['site_state']['status'] ?? '') !== 'installed'
            || ($manifest['files']['wplite/distribution.json']['sha256'] ?? '') !== ($manifest['distribution_sha256'] ?? '')) {
            throw new \RuntimeException('Full backup database, configuration or distribution binding is invalid.');
        }
        $details = Backup::inspectDatabase($directory . '/database.sqlite', $manifest['table_prefix']);
        if (!hash_equals($siteId, $details['site_id']) || $details['db_version'] !== ($manifest['wordpress_db_version'] ?? '')) {
            throw new \RuntimeException('Full backup database identity or schema version differs.');
        }
        Restore::assertRuntime($directory . '/site');
        return ['status' => 'verified', 'kind' => 'site', 'authenticated' => true,
            'files' => count($manifest['files']), 'directories' => count($manifest['directories']),
            'file_bytes' => $bytes, 'database_bytes' => $manifest['database_bytes'],
            'database_sha256' => $manifest['database_sha256']];
    }

    public static function complete(string $root, string $data): array
    {
        [$root, $data, $state] = self::site($root, $data);
        $lease = Maintenance::acquire($data, resume: true, type: 'site-backup');
        $details = Backup::inspectDatabase($state['database'], $state['table_prefix'], false);
        if (!hash_equals($state['site_id'], $details['site_id'])) {
            throw new \RuntimeException('Cannot reopen an unrelated database after interrupted backup.');
        }
        Restore::assertRuntime($root);
        $lease->finish();
        return ['status' => 'reopened', 'kind' => 'site-backup'];
    }
}
