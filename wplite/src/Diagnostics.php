<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Maintenance.php';

/** Explicit, allowlisted diagnostics. Never serialize private installation state. */
final class Diagnostics
{
    public static function offline(string $root, string $data, bool $integrity = false): array
    {
        $environment = Environment::inspect();
        $report = ['format' => 1, 'scope' => 'offline-site', 'ok' => false,
            'environment' => $environment, 'errors' => [], 'warnings' => [],
            'runtime' => ['status' => 'not_checked', 'signature' => 'unavailable'],
            'storage' => ['mode' => 'unknown', 'live_http_check' => 'not_run'],
            'database' => ['status' => 'not_checked', 'integrity' => 'not_checked', 'connection_policy' => 'not_loaded'],
            'maintenance' => 'unknown', 'cron' => ['status' => 'not_loaded'],
            'capabilities' => ['content' => 'blocked', 'scheduled_features' => 'unverified',
                'commerce' => 'unverified', 'production_certified' => false,
                'commerce_blockers' => ['compatibility_not_certified', 'scheduler_reliability_not_certified',
                    'signed_updates_unavailable', 'full_site_recovery_unavailable']]];
        if (!$environment['ok']) {
            $report['errors'][] = 'environment_unsupported';
        }
        $root = realpath($root);
        try {
            if ($root === false || !str_starts_with($data, '/') || is_link($data) || realpath($data) === false) {
                throw new \RuntimeException('Invalid site boundary.');
            }
            $data = realpath($data);
            $stateFile = $data . '/site.json';
            if (!is_file($stateFile) || is_link($stateFile) || filesize($stateFile) > 65536) {
                throw new \RuntimeException('Invalid state.');
            }
            $state = @Environment::readState($data);
            if (($state['root'] ?? '') !== $root || ($state['status'] ?? '') !== 'installed'
                || ($state['database'] ?? '') !== $data . '/site.sqlite'
                || !preg_match('/^[a-f0-9]{48}$/D', $state['site_id'] ?? '')
                || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $state['table_prefix'] ?? '')
                || !is_string($state['public_root'] ?? null)
                || realpath($state['public_root']) !== $state['public_root']
                || !Environment::isWithin($root, $state['public_root'])) {
                throw new \RuntimeException('Mismatched state.');
            }
        } catch (\Throwable $error) {
            $report['errors'][] = 'site_state_invalid';
            return $report;
        }
        try {
            $manifestFile = $root . '/wplite/distribution.json';
            if (!is_file($manifestFile) || is_link($manifestFile) || filesize($manifestFile) > 2 * 1024 * 1024) {
                throw new \RuntimeException('Invalid manifest.');
            }
            $manifest = json_decode(@file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['file_manifest_version'] ?? null) !== 1 || empty($manifest['files'])
                || !is_string($manifest['wplite_version'] ?? null)
                || !preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/D', $manifest['wplite_version'])
                || !is_string($manifest['wordpress']['tag'] ?? null)
                || !preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $manifest['wordpress']['tag'])
                || !is_string($manifest['sqlite']['tag'] ?? null)
                || !preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $manifest['sqlite']['tag'])) {
                throw new \RuntimeException('Incomplete manifest.');
            }
            foreach ($manifest['files'] as $relative => $digest) {
                if (!is_string($relative) || str_starts_with($relative, '/') || str_contains($relative, '\\')
                    || in_array('..', explode('/', $relative), true) || !is_string($digest)
                    || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                    throw new \RuntimeException('Invalid managed file.');
                }
                $file = $root . '/' . $relative;
                $resolved = realpath($file);
                if ($resolved === false || !Environment::isWithin($resolved, $root) || !is_file($file)
                    || is_link($file) || !hash_equals($digest, @hash_file('sha256', $file))) {
                    throw new \RuntimeException('Managed file mismatch.');
                }
            }
            $version = @file_get_contents($root . '/wp-includes/version.php');
            if (!is_string($version) || !preg_match('/\$wp_version\s*=\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/', $version, $match)
                || $match[1] !== ($manifest['wordpress']['tag'] ?? null)
                || !is_file($root . '/wplite/site-config.php') || is_link($root . '/wplite/site-config.php')) {
                throw new \RuntimeException('Core/configuration mismatch.');
            }
            $report['runtime'] = ['status' => 'matched_manifest', 'signature' => 'unavailable',
                'wplite' => $manifest['wplite_version'], 'wordpress' => $manifest['wordpress']['tag'],
                'driver' => $manifest['sqlite']['tag'], 'managed_files' => count($manifest['files'])];
        } catch (\Throwable $error) {
            $report['runtime']['status'] = 'failed';
            $report['errors'][] = 'runtime_mismatch';
        }
        try {
            @Storage::assertInstalled($data, $state);
            $report['storage']['mode'] = Environment::isWithin($data, $state['public_root']) ? 'http-denied' : 'private';
            $report['storage']['configuration'] = 'matched';
            $report['storage']['http_verified_at'] = $state['storage']['verified_at'] ?? null;
        } catch (\Throwable $error) {
            $report['storage']['configuration'] = 'failed';
            $report['errors'][] = 'storage_protection_invalid';
        }
        $report['storage']['writable'] = is_writable($data) && (!is_file($state['database']) || is_writable($state['database']));
        if (!$report['storage']['writable']) {
            $report['errors'][] = 'storage_not_writable';
        }
        $free = @disk_free_space($data);
        $report['storage']['free_bytes'] = $free === false ? null : (int) $free;
        if ($free === false) {
            $report['warnings'][] = 'free_space_unknown';
        } elseif ($free < 16 * 1024 * 1024 + (is_file($state['database']) ? filesize($state['database']) * 2 : 0)) {
            $report['warnings'][] = 'low_database_backup_headroom';
        }
        try {
            $report['maintenance'] = @Maintenance::status($data)['phase'] === 'inactive' ? 'inactive' : 'active';
            if ($report['maintenance'] === 'active') {
                $report['errors'][] = 'maintenance_active';
            }
        } catch (\Throwable $error) {
            $report['maintenance'] = 'invalid';
            $report['errors'][] = 'maintenance_invalid';
        }
        if ($report['maintenance'] !== 'inactive' || !$environment['ok']) {
            return $report;
        }
        $file = $state['database'];
        if (!is_file($file) || is_link($file) || filesize($file) === 0) {
            $report['database']['status'] = 'missing_or_invalid';
            $report['errors'][] = 'database_missing_or_invalid';
            return $report;
        }
        try {
            $uri = 'file:' . str_replace('%2F', '/', rawurlencode($file)) . '?mode=ro';
            $pdo = new \PDO('sqlite:' . $uri, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 250');
            $identity = $pdo->query('SELECT option_value FROM "' . $state['table_prefix'] . 'options" WHERE option_name = \'wplite_site_id\'')->fetchColumn();
            if (!is_string($identity) || !hash_equals($state['site_id'], $identity)) {
                throw new \RuntimeException('Database identity mismatch.');
            }
            $report['database']['status'] = 'available';
            $report['database']['bytes'] = filesize($file);
            $report['database']['journal_mode'] = strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn());
            if ($report['database']['journal_mode'] !== 'wal') {
                $report['errors'][] = 'database_not_wal';
            }
            if ($integrity) {
                $report['database']['integrity'] = $pdo->query('PRAGMA quick_check(1)')->fetchColumn() === 'ok' ? 'ok' : 'failed';
                if ($report['database']['integrity'] !== 'ok') {
                    $report['errors'][] = 'database_integrity_failed';
                }
            }
        } catch (\Throwable $error) {
            $report['database']['status'] = 'unreadable_or_foreign';
            $report['errors'][] = 'database_unreadable_or_foreign';
        }
        $report['ok'] = !$report['errors'];
        $report['capabilities']['content'] = $report['ok'] ? 'configuration_ready' : 'blocked';
        return $report;
    }

    public static function site(bool $integrity = false): array
    {
        if (!defined('WPLITE_BOOTSTRAPPED') || !WPLITE_BOOTSTRAPPED) {
            throw new \RuntimeException('Load the installed WordPress site first.');
        }
        $report = self::offline(rtrim(ABSPATH, '/'), dirname(DB_PATH), $integrity);
        $report['scope'] = 'loaded-site';
        try {
            $pdo = $GLOBALS['wpdb']->get_driver()->get_sqlite_pdo();
            $policy = ['synchronous' => (int) $pdo->query('PRAGMA synchronous')->fetchColumn(),
                'busy_timeout' => (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn(),
                'wal_autocheckpoint' => (int) $pdo->query('PRAGMA wal_autocheckpoint')->fetchColumn()];
            $report['database']['connection_policy'] = $policy;
            if ($policy !== ['synchronous' => 2, 'busy_timeout' => 5000, 'wal_autocheckpoint' => 1000]
                || !is_resource($GLOBALS['wplite_request_lock'] ?? null)) {
                $report['errors'][] = 'active_connection_policy_invalid';
            }
        } catch (\Throwable $error) {
            $report['errors'][] = 'active_connection_unavailable';
        }
        require_once __DIR__ . '/CronProbe.php';
        $report['cron'] = CronProbe::status();
        $report['ok'] = !$report['errors'];
        $report['capabilities']['content'] = $report['ok'] ? 'basic_runtime_ready' : 'blocked';
        $report['capabilities']['scheduled_features'] = $report['cron']['recent_observation'] ? 'observed_once' : 'unverified';
        return $report;
    }
}
