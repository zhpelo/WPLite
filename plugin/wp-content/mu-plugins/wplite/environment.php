<?php
declare(strict_types=1);

namespace WPLite;

/** Small installation/runtime checks; no WordPress dependencies or site state. */
final class Environment
{
    public static function sqliteSupported(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1
            && (in_array($version, ['3.44.6', '3.50.7'], true)
                || (version_compare($version, '3.51.3', '>=') && $version !== '3.52.0'));
    }

    public static function requireSupported(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            throw new \RuntimeException('This WPLite version supports POSIX hosts (Linux/macOS) only.');
        }
        if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80600 || !extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('WPLite requires PHP 8.3–8.5 with PDO SQLite.');
        }
        $pdo = new \PDO('sqlite::memory:');
        if (!self::sqliteSupported((string) $pdo->query('SELECT sqlite_version()')->fetchColumn())) {
            throw new \RuntimeException('Use patched SQLite 3.51.3+ (not 3.52.0), or 3.44.6/3.50.7.');
        }
    }

    public static function isWithin(string $path, string $root): bool
    {
        return $path === rtrim($root, '/') || str_starts_with($path, rtrim($root, '/') . '/');
    }
}
