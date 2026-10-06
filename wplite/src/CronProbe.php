<?php
declare(strict_types=1);

namespace WPLite;

/** A one-shot observed WP-Cron event, not a claim of recurring scheduler health. */
final class CronProbe
{
    public const HOOK = 'wplite_diagnostic_canary';
    public const OPTION = 'wplite_cron_probe';

    private static function distribution(): string
    {
        return hash_file('sha256', ABSPATH . 'wplite/distribution.json');
    }

    private static function record(): array
    {
        // A loopback worker can finish while its parent still has an option in
        // its local/external object cache. Never write an old cached snapshot.
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        $record = get_option(self::OPTION, []);
        if (!is_array($record) || ($record['format'] ?? null) !== 1
            || !is_string($record['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $record['id'])
            || !is_int($record['requested_at'] ?? null) || !is_int($record['deadline'] ?? null)
            || $record['requested_at'] > time() + 60 || $record['requested_at'] < 0
            || $record['deadline'] !== $record['requested_at'] + 120
            || !in_array($record['state'] ?? '', ['pending', 'executed', 'failed'], true)
            || !is_array($record['http'] ?? null)
            || !is_string($record['distribution'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $record['distribution'])) {
            return [];
        }
        return $record;
    }

    private static function save(array $record): void
    {
        if (!update_option(self::OPTION, $record, false)) {
            throw new \RuntimeException('The Cron probe state could not be saved.');
        }
    }

    private static function locked(callable $operation)
    {
        $file = dirname(DB_PATH) . '/.cron-probe.lock';
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            throw new \RuntimeException('Invalid Cron probe lock.');
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the Cron probe lock.');
        }
        try {
            chmod($file, 0600);
            $deadline = microtime(true) + 2;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Another Cron probe operation is busy.');
                }
                usleep(10000);
            }
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function status(): array
    {
        $record = self::record();
        $current = $record && hash_equals(self::distribution(), $record['distribution']);
        $observed = $current && $record['state'] === 'executed'
            && is_int($record['executed_at'] ?? null) && $record['executed_at'] >= $record['requested_at']
            && $record['executed_at'] <= time() + 60 && in_array($record['runner'] ?? '', ['cli', 'http'], true);
        $phase = !$record ? 'not_requested' : (!$current ? 'stale_release' : $record['state']);
        if ($phase === 'executed' && !$observed) {
            $phase = 'invalid_record';
        }
        if ($phase === 'pending' && time() > $record['deadline']) {
            $phase = 'expired';
        }
        if ($observed && $record['executed_at'] > $record['deadline']) {
            $phase = 'executed_late';
        }
        $http = $record['http'] ?? [];
        return ['status' => $phase,
            'automatic_trigger' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'disabled'
                : (defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON ? 'alternate_traffic' : 'traffic'),
            'execution_observed' => (bool) $observed,
            'requested_at' => $record['requested_at'] ?? null,
            'executed_at' => $observed ? $record['executed_at'] : null,
            'observed_runner' => $observed ? $record['runner'] : null,
            'latency_seconds' => $observed ? $record['executed_at'] - $record['requested_at'] : null,
            'http' => ['status' => in_array($http['status'] ?? '', ['attempted', 'response_received', 'failed'], true) ? $http['status'] : 'not_requested',
                'code' => is_int($http['code'] ?? null) ? $http['code'] : null],
            'recurring_scheduler_verified' => false, 'external_scheduler_configured' => 'unknown'];
    }

    public static function start(string $transport = 'schedule'): array
    {
        if (!in_array($transport, ['schedule', 'loopback'], true)) {
            throw new \RuntimeException('Cron transport must be schedule or loopback.');
        }
        $record = self::locked(static function (): array {
            $old = self::record();
            if ($old && $old['state'] === 'pending' && $old['deadline'] >= time()
                && hash_equals(self::distribution(), $old['distribution'])) {
                return $old; // Do not accumulate events on repeated clicks.
            }
            $cleared = wp_unschedule_hook(self::HOOK, true);
            if (is_wp_error($cleared)) {
                throw new \RuntimeException('The old diagnostic event could not be cleared.');
            }
            $now = time();
            $record = ['format' => 1, 'id' => bin2hex(random_bytes(16)), 'state' => 'pending',
                'requested_at' => $now, 'deadline' => $now + 120, 'distribution' => self::distribution(),
                'http' => ['status' => 'not_requested']];
            self::save($record);
            $scheduled = wp_schedule_single_event($record['requested_at'], self::HOOK, [$record['id']], true);
            if (is_wp_error($scheduled) || $scheduled !== true) {
                $record['state'] = 'failed';
                self::save($record);
                throw new \RuntimeException('The diagnostic event could not be scheduled.');
            }
            return $record;
        });
        if ($transport === 'loopback') {
            self::loopback($record['id']);
        }
        return self::status();
    }

    private static function loopback(string $id): void
    {
        $attempt = self::locked(static function () use ($id): ?string {
            $record = self::record();
            if (!$record || $record['id'] !== $id || $record['state'] !== 'pending'
                || ($record['http']['attempted_at'] ?? 0) > time() - 10) {
                return null;
            }
            $attempt = bin2hex(random_bytes(8));
            $record['http'] = ['status' => 'attempted', 'attempted_at' => time(), 'attempt' => $attempt];
            self::save($record);
            return $attempt;
        });
        if ($attempt === null) {
            return;
        }
        // Use the configured canonical URL, not a caller-supplied host or a
        // filtered URL. No credentials/cookies/secret probe IDs are sent.
        $base = defined('WP_SITEURL') ? WP_SITEURL : '';
        $parsed = is_string($base) ? parse_url($base) : false;
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || empty($parsed['host'])
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])
            || preg_match('/[\x00-\x20\\\\]/', $base)) {
            throw new \RuntimeException('The configured canonical site URL is invalid.');
        }
        // Release the probe lock before requesting a different PHP worker.
        // HTTP 200 is only transport evidence; the real event must observe itself.
        $response = wp_remote_get(rtrim($base, '/') . '/wp-cron.php', ['timeout' => 5, 'redirection' => 0,
            'blocking' => true, 'sslverify' => true, 'limit_response_size' => 1024, 'cookies' => []]);
        $code = is_wp_error($response) ? null : (int) wp_remote_retrieve_response_code($response);
        self::locked(static function () use ($id, $attempt, $code): void {
            $record = self::record();
            if (!$record || $record['id'] !== $id || ($record['http']['attempt'] ?? '') !== $attempt) {
                return;
            }
            $record['http']['status'] = $code === 200 ? 'response_received' : 'failed';
            $record['http']['code'] = $code;
            self::save($record); // Re-read under lock so an executed event survives.
        });
    }

    public static function observe($id): void
    {
        if (!(defined('DOING_CRON') && DOING_CRON) || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            return;
        }
        try {
            self::locked(static function () use ($id): void {
                $record = self::record();
                if (!$record || $record['id'] !== $id || $record['state'] !== 'pending'
                    || !hash_equals(self::distribution(), $record['distribution'])) {
                    return;
                }
                $record['state'] = 'executed';
                $record['executed_at'] = time();
                $record['runner'] = PHP_SAPI === 'cli' ? 'cli' : 'http';
                self::save($record);
            });
        } catch (\Throwable $error) {
            error_log('WPLite: Cron probe execution could not be recorded.');
        }
    }
}
