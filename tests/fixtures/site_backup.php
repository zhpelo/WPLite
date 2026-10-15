<?php
declare(strict_types=1);

namespace WPLite {
    // Test-only deterministic interruption after a real payload fsync. This
    // fixture stays outside every distribution and never fakes a successful I/O.
    function fsync($stream): bool
    {
        $result = \fsync($stream);
        $uri = stream_get_meta_data($stream)['uri'] ?? '';
        if (str_ends_with($uri, '/site/wp-content/uploads/backup-large.bin')) {
            if (getenv('WPLITE_COPY_FAULT') === 'hold') {
                file_put_contents(getenv('WPLITE_TEST_DATA_DIR') . '/copy-paused', 'payload copied, manifest not committed');
                while (true) { usleep(100000); }
            }
            if (getenv('WPLITE_COPY_FAULT') === 'external-write') {
                file_put_contents(getenv('WPLITE_TEST_SITE') . '/unexpected-update.txt', 'out-of-protocol write');
            }
        }
        return $result;
    }
}

namespace {
    $root = getenv('WPLITE_TEST_SITE');
    $data = getenv('WPLITE_TEST_DATA_DIR');
    if (($argv[1] ?? '') === 'seed') {
        require $root . '/wp-load.php';
        update_option('wplite_full_backup_probe', 'consistent 中文 😀');
        echo json_encode(['status' => 'seeded']) . PHP_EOL;
    } else {
        require $root . '/wplite/src/SiteBackup.php';
        try {
            if (($argv[1] ?? '') === 'database-maintenance') {
                $lease = \WPLite\Maintenance::acquire($data);
                $report = ['status' => 'maintenance-created'];
            } else {
                $report = \WPLite\SiteBackup::create($root, $data, $argv[2]);
            }
            echo json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            exit(1);
        }
    }
}
