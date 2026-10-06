<?php
declare(strict_types=1);

namespace WPLite;

final class Environment
{
    public static function sqliteSupported(string $version): bool
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            return false;
        }
        return in_array($version, ['3.44.6', '3.50.7'], true)
            || (version_compare($version, '3.51.3', '>=') && $version !== '3.52.0');
    }

    public static function inspect(): array
    {
        $sqlite = null;
        $errors = [];
        if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600) {
            $errors[] = 'Supported PHP versions are 8.3, 8.4 and 8.5.';
        }
        if (!extension_loaded('pdo_sqlite')) {
            $errors[] = 'The pdo_sqlite PHP extension is required.';
        } else {
            $pdo = new \PDO('sqlite::memory:');
            $sqlite = $pdo->query('SELECT sqlite_version()')->fetchColumn();
            if (!self::sqliteSupported($sqlite)) {
                $errors[] = 'SQLite requires 3.51.3+ (excluding 3.52.0), or certified 3.44.6/3.50.7.';
            }
        }
        return ['ok' => !$errors, 'php' => PHP_VERSION, 'sqlite' => $sqlite,
            'pdo_sqlite' => extension_loaded('pdo_sqlite'), 'errors' => $errors];
    }

    public static function requireSupported(): void
    {
        $report = self::inspect();
        if (!$report['ok']) {
            throw new \RuntimeException(implode(' ', $report['errors']));
        }
    }

    public static function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    public static function readState(string $directory): array
    {
        if (!is_file($directory . '/site.json')) {
            throw new \RuntimeException('WPLite site state is missing. Restore the matching configuration and database.');
        }
        $raw = file_get_contents($directory . '/site.json');
        if ($raw === false) {
            throw new \RuntimeException('WPLite site state is missing. Restore the matching configuration and database.');
        }
        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function writeState(string $directory, array $state): void
    {
        $temporary = tempnam($directory, '.state-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create the site state file.');
        }
        chmod($temporary, 0600);
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false
            || !rename($temporary, $directory . '/site.json')) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot save the site state file.');
        }
    }
}
