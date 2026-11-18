<?php
declare(strict_types=1);
namespace WPLite;

/** Minimal wpdb adapter: no WordPress boot, hooks, plugins, or logging of SQL. */
final class CopySource extends \wpdb
{
    private bool $frozen = false;

    public function freeze(array $tables): void
    {
        if (!$tables || $this->frozen) { throw new \RuntimeException("invalid_freeze"); }
        $names = array_map(static fn ($table) => "`" . str_replace("`", "``", $table) . "` READ", $tables);
        $this->dbh->query("SET SESSION lock_wait_timeout=60");
        $this->dbh->query("LOCK TABLES " . implode(", ", $names));
        $this->frozen = true;
    }

    public function __construct(array $config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->dbh = mysqli_init();
        $this->dbh->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        $this->dbh->real_connect($config['host'], $config['user'], $config['password'], $config['database'], (int) ($config['port'] ?? 3306), $config['socket'] ?? null);
        $this->dbh->set_charset('utf8mb4');
        $this->dbh->query('SET SESSION TRANSACTION READ ONLY');
        $this->prefix = $config['prefix'];
        $this->options = $this->prefix . 'options';
        $this->users = $this->prefix . 'users';
        $this->ready = true;
        $this->suppress_errors = true;
    }

    public function query($query)
    {
        if ($this->frozen && preg_match('/^(?:LOCK TABLES|UNLOCK TABLES)\b/i', $query)) { return 0; }
        if (!preg_match('/^(?:SELECT|SHOW|SET SESSION|LOCK TABLES|UNLOCK TABLES)\b/i', $query)) { throw new \RuntimeException('read_only_source'); }
        $result = $this->dbh->query($query);
        $this->last_result = [];
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_object()) { $this->last_result[] = $row; }
            $result->free();
        }
        $this->num_rows = count($this->last_result);
        return $this->num_rows;
    }
}
