<?php
declare(strict_types=1);

namespace WPLite;

require_once __DIR__ . '/Environment.php';

/** Storage preparation never writes a database before the HTTP privacy gate. */
final class Storage
{
    public const DENY = "# WPLite: deny every file and descendant, not just .sqlite.\nRequire all denied\n";

    public static function location(string $root, string $public, string $data): array
    {
        $public = realpath($public);
        if ($public === false || !Environment::isWithin($root, $public)) {
            throw new \RuntimeException('The actual public document root must contain this installation.');
        }
        if (!str_starts_with($data, '/') || str_contains($data, "\0")
            || in_array('..', explode('/', $data), true) || in_array('.', explode('/', $data), true)) {
            throw new \RuntimeException('Use an absolute data directory without parent traversal.');
        }
        $parent = realpath(dirname($data));
        if ($parent === false || is_link($data) || in_array(basename($data), ['', '.', '..'], true)) {
            throw new \RuntimeException('The data directory needs an existing parent and must not be a symbolic link.');
        }
        $data = $parent . '/' . basename($data);
        if ($data === $root || $data === $public || Environment::isWithin($root, $data)) {
            throw new \RuntimeException('The data directory must not contain the installation or document root.');
        }
        return [$public, $data];
    }

    public static function prepare(string $root, string $public, string $data, ?string $origin = null): array
    {
        [$public, $data] = self::location($root, $public, $data);
        $inside = Environment::isWithin($data, $public);
        if ($inside && $origin === null) {
            throw new \RuntimeException('CLI installation requires a data directory outside the public document root.');
        }
        $marker = $data . '/.wplite-prepared.json';
        $identity = ['format' => 1, 'root_sha256' => hash('sha256', $root)];
        if (file_exists($data)) {
            if (!$inside || !is_dir($data) || !is_file($marker) || is_link($marker)
                || json_decode(file_get_contents($marker), true) !== $identity) {
                throw new \RuntimeException('Refusing to overwrite an existing data directory.');
            }
            foreach (array_diff(scandir($data), ['.', '..', '.wplite-prepared.json', '.htaccess', 'index.php']) as $unexpected) {
                throw new \RuntimeException('Prepared storage contains unexpected files; inspect it before retrying.');
            }
        } else {
            if (!mkdir($data, 0700)) {
                throw new \RuntimeException('Cannot create the data directory.');
            }
            if ($inside) {
                self::freshFile($marker, json_encode($identity, JSON_THROW_ON_ERROR));
                self::freshFile($data . '/.htaccess', self::DENY);
                self::freshFile($data . '/index.php', "<?php http_response_code(404); exit;\n");
            }
        }
        if (!$inside) {
            return ['mode' => 'private'];
        }
        if (!is_file($data . '/.htaccess') || is_link($data . '/.htaccess') || file_get_contents($data . '/.htaccess') !== self::DENY) {
            throw new \RuntimeException('The prepared storage deny rule was changed. Restore it before retrying.');
        }
        self::probe($root, $public, $data, $origin);
        return ['mode' => 'http-denied', 'verified_at' => time(), 'deny_sha256' => hash('sha256', self::DENY)];
    }

    public static function assertInstalled(string $data, array $state): void
    {
        $public = $state['public_root'] ?? $state['root'] ?? '';
        if (!is_string($public) || $public === '' || realpath($data) !== $data || is_link($data)) {
            throw new \RuntimeException('Invalid installed storage boundary.');
        }
        if (!Environment::isWithin($data, $public)) {
            return;
        }
        $proof = $state['storage'] ?? [];
        $deny = $data . '/.htaccess';
        if (($proof['mode'] ?? '') !== 'http-denied' || !is_int($proof['verified_at'] ?? null)
            || ($proof['deny_sha256'] ?? '') !== hash('sha256', self::DENY)
            || !is_file($deny) || is_link($deny) || !hash_equals($proof['deny_sha256'], hash_file('sha256', $deny))) {
            throw new \RuntimeException('Public-root storage has no matching verified HTTP deny rule.');
        }
    }

    private static function freshFile(string $file, string $content): void
    {
        $handle = fopen($file, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create a fresh storage probe file.');
        }
        try {
            chmod($file, 0600);
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write a complete storage probe file.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function get(string $url): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0,
            'max_redirects' => 0, 'timeout' => 4, 'ignore_errors' => true,
            'header' => "Connection: close\r\nCache-Control: no-cache\r\nUser-Agent: WPLite-storage-check\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw new \RuntimeException('The HTTP storage check could not reach this site. Loopback HTTP(S) with valid TLS is required.');
        }
        try {
            $meta = stream_get_meta_data($handle);
            $body = stream_get_contents($handle, 4097);
            $headers = $meta['wrapper_data'] ?? [];
            if (!isset($headers[0]) || !preg_match('~^HTTP/\S+ ([0-9]{3})\b~', $headers[0], $match)) {
                throw new \RuntimeException('Invalid HTTP storage check response.');
            }
            return [(int) $match[1], $body];
        } finally {
            fclose($handle);
        }
    }

    private static function probe(string $root, string $public, string $data, string $origin): void
    {
        $id = bin2hex(random_bytes(16));
        $challenge = bin2hex(random_bytes(24));
        $name = 'wplite-probe-' . $id;
        $positive = $root . '/' . $name . '.txt';
        $created = [];
        $directories = [];
        $encode = static fn (string $path): string => implode('/', array_map('rawurlencode', explode('/', $path)));
        try {
            self::freshFile($positive, $challenge);
            [$status, $body] = self::get($origin . $encode(substr($positive, strlen($public))) . '?wplite=' . $id);
            if ($status !== 200 || $body !== $challenge) {
                throw new \RuntimeException('The public control probe did not return its exact challenge. Check the site URL, mapping and HTTP cache.');
            }
            foreach ([$name . '.sqlite', $name . '.sqlite-wal', $name . '.sqlite-shm', $name . '.json', '.' . $name,
                'backups-' . $id . '/database.sqlite', 'rollback-' . $id . '/site.sqlite'] as $relative) {
                $file = $data . '/' . $relative;
                if (dirname($file) !== $data) {
                    if (!mkdir(dirname($file), 0700)) {
                        throw new \RuntimeException('Cannot create a nested storage probe.');
                    }
                    $directories[] = dirname($file);
                }
                self::freshFile($file, $challenge);
                $created[] = $file;
                [$status, $body] = self::get($origin . $encode(substr($file, strlen($public))) . '?wplite=' . $id);
                if (!in_array($status, [403, 404], true) || str_contains((string) $body, $challenge)) {
                    throw new \RuntimeException('Storage is publicly accessible or denial is unproven. Deny the entire data directory in the web server and retry; no database was created.');
                }
            }
        } finally {
            foreach ($created as $file) {
                unlink($file);
            }
            foreach (array_reverse($directories) as $directory) {
                rmdir($directory);
            }
            if (is_file($positive)) {
                unlink($positive);
            }
        }
    }
}
