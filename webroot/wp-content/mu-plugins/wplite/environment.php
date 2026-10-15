<?php
declare(strict_types=1);

namespace WPLite;

/** Small installation/runtime checks; no WordPress dependencies or site state. */
final class Environment
{
    public static function sqliteSupported(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1
            && version_compare($version, '3.37.0', '>=');
    }

    public static function journalMode(string $version): string
    {
        // Older engines can run the driver, but must avoid the WAL-reset bug.
        return in_array($version, ['3.44.6', '3.50.7'], true)
            || (version_compare($version, '3.51.3', '>=') && $version !== '3.52.0')
            ? 'WAL' : 'DELETE';
    }

    public static function requireSupported(): string
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            throw new \RuntimeException('This WPLite version supports POSIX hosts (Linux/macOS) only.');
        }
        if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600) {
            throw new \RuntimeException('WPLite requires PHP 8.3–8.5; current version: ' . PHP_VERSION . '.');
        }
        if (!class_exists(\PDO::class) || !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('WPLite requires an available PDO SQLite driver.');
        }
        $pdo = new \PDO('sqlite::memory:');
        $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        if (!self::sqliteSupported($version)) {
            throw new \RuntimeException('WPLite requires SQLite 3.37.0 or newer; current version: ' . $version . '.');
        }
        return $version;
    }

    public static function isWithin(string $path, string $root): bool
    {
        return $path === rtrim($root, '/') || str_starts_with($path, rtrim($root, '/') . '/');
    }
}
