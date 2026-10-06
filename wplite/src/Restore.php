<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/Backup.php';
require_once __DIR__ . '/Maintenance.php';

/** Database recovery that works without loading WordPress or its database. */
final class Restore
{
    private static function site(string $root, string $data): array
    {
        Environment::requireSupported();
        $root = realpath($root);
        $data = realpath($data);
        if ($root === false || $data === false || Environment::isWithin($data, $root)) {
            throw new \RuntimeException('The installed site and private data directory are required.');
        }
        $state = Environment::readState($data);
        if (($state['root'] ?? '') !== $root || ($state['status'] ?? '') !== 'installed'
            || dirname($state['database'] ?? '') !== $data
            || !preg_match('/^[a-f0-9]{48}$/D', $state['site_id'] ?? '')
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $state['table_prefix'] ?? '')) {
            throw new \RuntimeException('Recovery state does not match this installed site.');
        }
        return [$root, $data, $state];
    }

    private static function runtime(string $root): void
    {
        $manifest = json_decode(file_get_contents($root . '/wplite/distribution.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $relative => $digest) {
            if (str_contains($relative, '..') || str_contains($relative, '\\') || str_starts_with($relative, '/')) {
                throw new \RuntimeException('Invalid runtime file manifest.');
            }
            $file = $root . '/' . $relative;
            if (!is_file($file) || is_link($file) || !hash_equals($digest, hash_file('sha256', $file))) {
                throw new \RuntimeException('Matching runtime files must be repaired before reopening the site.');
            }
        }
        foreach (['wp-config.php', 'wp-settings.php', 'wp-includes/version.php'] as $relative) {
            if (!is_file($root . '/' . $relative)) {
                throw new \RuntimeException('Required WordPress configuration/core files are missing.');
            }
        }
        $source = file_get_contents($root . '/wp-includes/version.php');
        if (!preg_match('/\$wp_version\s*=\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $source, $match)
            || $match[1] !== $manifest['wordpress']['tag']) {
            throw new \RuntimeException('WordPress core version does not match the recovery release.');
        }
    }

    private static function inspectLive(array $state): void
    {
        $details = Backup::inspectDatabase($state['database'], $state['table_prefix'], false);
        if (!hash_equals($state['site_id'], $details['site_id'])) {
            throw new \RuntimeException('Restored database does not belong to this site.');
        }
    }

    private static function rollbackDirectory(string $data, string $id): string
    {
        $parent = $data . '/rollback';
        if (is_link($parent)) {
            throw new \RuntimeException('Rollback directory must not be a symbolic link.');
        }
        if (!is_dir($parent) && !mkdir($parent, 0700)) {
            throw new \RuntimeException('Cannot create the private rollback directory.');
        }
        return $parent . '/' . $id;
    }

    public static function restore(string $root, string $data, string $backup): array
    {
        [$root, $data, $state] = self::site($root, $data);
        $verified = Backup::verify($backup, Backup::backupKey($state), $state['site_id']);
        if ($verified['table_prefix'] !== $state['table_prefix']
            || !hash_equals($verified['distribution_sha256'], hash_file('sha256', $root . '/wplite/distribution.json'))) {
            throw new \RuntimeException('Restore requires the matching WPLite release and table prefix.');
        }
        $lease = Maintenance::acquire($data);
        $id = $lease->journal()['operation_id'];
        $rollback = self::rollbackDirectory($data, $id);
        try {
            $stage = $data . '/.restore-' . $id . '.sqlite';
            if (file_exists($stage) || !copy(realpath($backup) . '/database.sqlite', $stage)) {
                throw new \RuntimeException('Cannot stage the verified database snapshot.');
            }
            chmod($stage, 0600);
            $handle = fopen($stage, 'r+b');
            if ($handle === false || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw new \RuntimeException('Cannot durably stage the restore snapshot.');
            }
            fclose($handle);
            if (!hash_equals($verified['database_sha256'], hash_file('sha256', $stage))) {
                throw new \RuntimeException('Staged snapshot SHA-256 mismatch.');
            }
            if (!mkdir($rollback, 0700)) {
                throw new \RuntimeException('Cannot create a fresh rollback directory.');
            }
            $originals = [];
            foreach (['', '-wal', '-shm'] as $suffix) {
                $file = $state['database'] . $suffix;
                clearstatcache(true, $file);
                if (is_link($file) || (file_exists($file) && !is_file($file))) {
                    throw new \RuntimeException('Database components must be regular files.');
                }
                if (is_file($file)) {
                    $originals[basename($file)] = hash_file('sha256', $file);
                }
            }
            $lease->record('swapping', ['original_files' => $originals]);
            foreach ($originals as $name => $digest) {
                if (!rename($data . '/' . $name, $rollback . '/' . $name)) {
                    throw new \RuntimeException('Cannot preserve an original database component.');
                }
            }
            if (!rename($stage, $state['database'])) {
                throw new \RuntimeException('Cannot atomically install the restored database.');
            }
            $lease->record('validating');
            self::inspectLive($state);
            self::runtime($root);
            $lease->record('complete');
            $lease->finish();
            return ['status' => 'restored', 'kind' => 'database', 'rollback_directory' => $rollback,
                'database_sha256' => $verified['database_sha256']];
        } catch (\Throwable $error) {
            $lease->record('failed', ['error' => $error->getMessage()]);
            throw new \RuntimeException('Restore stopped with maintenance still active. Inspect restore-status; use restore-rollback or repair and restore-complete. ' . $error->getMessage(), 0, $error);
        }
    }

    public static function complete(string $root, string $data): array
    {
        [$root, $data, $state] = self::site($root, $data);
        $lease = Maintenance::acquire($data, resume: true);
        self::inspectLive($state);
        self::runtime($root);
        $lease->finish();
        return ['status' => 'reopened'];
    }

    public static function rollback(string $root, string $data): array
    {
        [$root, $data, $state] = self::site($root, $data);
        $lease = Maintenance::acquire($data, resume: true);
        $journal = $lease->journal();
        $rollback = self::rollbackDirectory($data, $journal['operation_id']);
        $name = basename($state['database']);
        $originals = $journal['original_files'] ?? [];
        if (!isset($originals[$name])) {
            throw new \RuntimeException('No original database was preserved. Repair the staged restore and use restore-complete.');
        }
        $displaced = $rollback . '/displaced';
        if (is_link($displaced) || (file_exists($displaced) && !is_dir($displaced))) {
            throw new \RuntimeException('Invalid rollback staging directory.');
        }
        if (!is_dir($displaced) && !mkdir($displaced, 0700)) {
            throw new \RuntimeException('Cannot preserve the interrupted restore files.');
        }
        foreach ($originals as $component => $digest) {
            if (!in_array($component, [$name, $name . '-wal', $name . '-shm'], true)
                || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                throw new \RuntimeException('Invalid rollback component journal.');
            }
        }
        $lease->record('rolling-back');
        foreach ([$name, $name . '-wal', $name . '-shm'] as $component) {
            $live = $data . '/' . $component;
            $saved = $rollback . '/' . $component;
            if (is_link($live) || is_link($saved)
                || (file_exists($live) && !is_file($live)) || (file_exists($saved) && !is_file($saved))) {
                throw new \RuntimeException('Rollback requires regular database components.');
            }
            if (isset($originals[$component]) && !is_file($saved)) {
                if (!is_file($live) || !hash_equals($originals[$component], hash_file('sha256', $live))) {
                    throw new \RuntimeException('Original rollback component is missing or changed.');
                }
                continue; // Already restored before an interruption.
            }
            if (is_file($saved) && !hash_equals($originals[$component], hash_file('sha256', $saved))) {
                throw new \RuntimeException('Preserved rollback component failed SHA-256 verification.');
            }
            if (file_exists($live)) {
                if (file_exists($displaced . '/' . $component) || !rename($live, $displaced . '/' . $component)) {
                    throw new \RuntimeException('Cannot preserve the interrupted database component.');
                }
            }
            if (is_file($saved) && !rename($saved, $live)) {
                throw new \RuntimeException('Cannot restore an original database component.');
            }
        }
        self::inspectLive($state);
        self::runtime($root);
        $lease->finish();
        return ['status' => 'rolled-back', 'preserved_directory' => $displaced];
    }
}
