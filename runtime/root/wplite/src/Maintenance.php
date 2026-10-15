<?php
declare(strict_types=1);

namespace WPLite;

/** A persistent maintenance marker plus process-owned file locks. */
final class Maintenance
{
    private $operation = null;
    private $requests = null;
    private array $journal = [];

    private function __construct(private string $data)
    {
    }

    /** Keep this resource alive until PHP finishes its shutdown hooks. */
    public static function guard(string $data)
    {
        if (file_exists($data . '/maintenance.json') || is_link($data . '/maintenance.json')) {
            throw new \RuntimeException('WPLite maintenance is active.');
        }
        $handle = fopen($data . '/.requests.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_SH | LOCK_NB)) {
            throw new \RuntimeException('WPLite is draining requests for maintenance.');
        }
        if (file_exists($data . '/maintenance.json') || is_link($data . '/maintenance.json')) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new \RuntimeException('WPLite maintenance is active.');
        }
        return $handle;
    }

    public static function acquire(string $data, bool $resume = false, float $timeout = 30,
                                   string $type = 'database-restore'): self
    {
        if (!in_array($type, ['database-restore', 'site-backup'], true)) {
            throw new \RuntimeException('Unsupported maintenance operation type.');
        }
        $lease = new self($data);
        $lease->operation = fopen($data . '/.operations.lock', 'c');
        if ($lease->operation === false || !flock($lease->operation, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another WPLite maintenance operation is running.');
        }
        $marker = $data . '/maintenance.json';
        if ($resume) {
            $lease->journal = self::status($data);
            if (($lease->journal['type'] ?? '') !== $type) {
                throw new \RuntimeException('There is no interrupted matching maintenance operation to resume.');
            }
        } else {
            if (file_exists($marker) || is_link($marker)) {
                throw new \RuntimeException('Maintenance is already active. Inspect restore-status before continuing.');
            }
            $lease->journal = ['format' => 1, 'type' => $type,
                'operation_id' => bin2hex(random_bytes(12)), 'phase' => 'draining', 'started_at' => gmdate('c')];
            $lease->record('draining');
        }
        $lease->requests = fopen($data . '/.requests.lock', 'c');
        $deadline = microtime(true) + $timeout;
        while ($lease->requests !== false && !flock($lease->requests, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                if (!$resume) {
                    $lease->finish(); // No database mutation has started.
                }
                throw new \RuntimeException('Timed out waiting for active requests to finish; database unchanged.');
            }
            usleep(25000);
        }
        if ($lease->requests === false) {
            if (!$resume) {
                $lease->finish();
            }
            throw new \RuntimeException('Cannot open the request maintenance lock.');
        }
        return $lease;
    }

    public static function status(string $data): array
    {
        $marker = $data . '/maintenance.json';
        if (!file_exists($marker) && !is_link($marker)) {
            return ['phase' => 'inactive'];
        }
        if (!is_file($marker) || is_link($marker) || filesize($marker) > 64 * 1024) {
            throw new \RuntimeException('Invalid maintenance journal.');
        }
        $journal = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
        if (($journal['format'] ?? null) !== 1 || !preg_match('/^[a-f0-9]{24}$/D', $journal['operation_id'] ?? '')) {
            throw new \RuntimeException('Invalid maintenance operation identity.');
        }
        return $journal;
    }

    public function journal(): array
    {
        return $this->journal;
    }

    public function record(string $phase, array $details = []): void
    {
        $this->journal = array_replace($this->journal, $details, ['phase' => $phase, 'updated_at' => gmdate('c')]);
        $temporary = tempnam($this->data, '.maintenance-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create the maintenance journal.');
        }
        chmod($temporary, 0600);
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the maintenance journal.');
        }
        try {
            $contents = json_encode($this->journal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('Cannot durably record maintenance progress.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $this->data . '/maintenance.json')) {
            throw new \RuntimeException('Cannot publish the maintenance journal.');
        }
    }

    public function finish(): void
    {
        $current = self::status($this->data);
        if (($current['operation_id'] ?? '') !== ($this->journal['operation_id'] ?? '')) {
            throw new \RuntimeException('Maintenance ownership changed; refusing to reopen the site.');
        }
        if (!unlink($this->data . '/maintenance.json')) {
            throw new \RuntimeException('Cannot clear the completed maintenance marker.');
        }
        $this->release();
    }

    private function release(): void
    {
        foreach (['requests', 'operation'] as $property) {
            if (is_resource($this->$property)) {
                flock($this->$property, LOCK_UN);
                fclose($this->$property);
                $this->$property = null;
            }
        }
    }

    public function __destruct()
    {
        // A crash/error releases OS locks but deliberately keeps the durable
        // marker: normal traffic must not see a partially restored database.
        $this->release();
    }
}
