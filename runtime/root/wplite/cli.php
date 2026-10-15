<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/src/Environment.php';

/** Return a named CLI argument without accepting duplicate/unknown flags. */
function wpliteArguments(array $arguments, ?array $accepted = null): array
{
    $accepted ??= ['url', 'title', 'admin-user', 'admin-email', 'password-env', 'data-dir', 'public-root', 'table-prefix'];
    $result = [];
    for ($i = 0; $i < count($arguments); ++$i) {
        if (!preg_match('/^--([a-z-]+)$/D', $arguments[$i], $match)
            || !in_array($match[1], $accepted, true) || isset($result[$match[1]])
            || !isset($arguments[$i + 1]) || str_starts_with($arguments[$i + 1], '--')) {
            throw new RuntimeException('Unknown, repeated or incomplete CLI argument.');
        }
        $result[$match[1]] = $arguments[++$i];
    }
    return $result;
}

try {
    $command = $argv[1] ?? 'doctor';
    if ($command === 'doctor') {
        $options = wpliteArguments(array_slice($argv, 2), ['data-dir', 'integrity']);
        if (!in_array($options['integrity'] ?? 'none', ['none', 'quick'], true)
            || (isset($options['integrity']) && !isset($options['data-dir']))) {
            throw new RuntimeException('Use --integrity none|quick with an installed --data-dir.');
        }
        if (isset($options['data-dir'])) {
            require_once __DIR__ . '/src/Diagnostics.php';
            $report = \WPLite\Diagnostics::offline(dirname(__DIR__), $options['data-dir'], ($options['integrity'] ?? '') === 'quick');
        } else {
            $report = \WPLite\Environment::inspect();
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($report['ok'] ? 0 : 1);
    }
    if (in_array($command, ['doctor-site', 'cron-probe', 'cron-status'], true)) {
        $options = wpliteArguments(array_slice($argv, 2), match ($command) {
            'doctor-site' => ['integrity'], 'cron-probe' => ['transport'], 'cron-status' => ['require-execution'],
        });
        if (($command === 'doctor-site' && !in_array($options['integrity'] ?? 'none', ['none', 'quick'], true))
            || ($command === 'cron-status' && !in_array($options['require-execution'] ?? 'no', ['yes', 'no'], true))) {
            throw new RuntimeException('Invalid diagnostic option value.');
        }
        require dirname(__DIR__) . '/wp-load.php';
        require_once __DIR__ . '/src/Diagnostics.php';
        require_once __DIR__ . '/src/CronProbe.php';
        $report = match ($command) {
            'doctor-site' => \WPLite\Diagnostics::site(($options['integrity'] ?? '') === 'quick'),
            'cron-probe' => \WPLite\CronProbe::start($options['transport'] ?? 'schedule'),
            'cron-status' => \WPLite\CronProbe::status(),
        };
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(($command === 'doctor-site' && !$report['ok'])
            || ($command === 'cron-status' && ($options['require-execution'] ?? '') === 'yes' && !$report['execution_observed']) ? 1 : 0);
    }
    if (in_array($command, ['site-backup-create', 'site-backup-verify', 'site-backup-complete'], true)) {
        require_once __DIR__ . '/src/SiteBackup.php';
        $accepted = match ($command) {
            'site-backup-create' => ['data-dir', 'output-dir'],
            'site-backup-verify' => ['data-dir', 'backup-dir'],
            'site-backup-complete' => ['data-dir'],
        };
        $options = wpliteArguments(array_slice($argv, 2), $accepted);
        if (empty($options['data-dir']) || ($command === 'site-backup-verify' && empty($options['backup-dir']))) {
            throw new RuntimeException('Full backups require the original --data-dir; verification also requires --backup-dir.');
        }
        if ($command === 'site-backup-verify') {
            $state = \WPLite\Environment::readState($options['data-dir']);
            $report = \WPLite\SiteBackup::verify($options['backup-dir'], \WPLite\Backup::backupKey($state), $state['site_id']);
        } else {
            $report = $command === 'site-backup-create'
                ? \WPLite\SiteBackup::create(dirname(__DIR__), $options['data-dir'], $options['output-dir'] ?? null)
                : \WPLite\SiteBackup::complete(dirname(__DIR__), $options['data-dir']);
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    if ($command === 'backup-create' || $command === 'backup-verify') {
        require_once __DIR__ . '/src/Backup.php';
        if ($command === 'backup-verify') {
            $options = wpliteArguments(array_slice($argv, 2), ['backup-dir', 'data-dir']);
            if (empty($options['backup-dir']) || empty($options['data-dir'])) {
                throw new RuntimeException('backup-verify requires --backup-dir and the original private --data-dir.');
            }
            $state = \WPLite\Environment::readState($options['data-dir']);
            $report = \WPLite\Backup::verify($options['backup-dir'], \WPLite\Backup::backupKey($state), $state['site_id']);
        } else {
            $options = wpliteArguments(array_slice($argv, 2), ['output-dir']);
            require dirname(__DIR__) . '/wp-load.php';
            $report = \WPLite\Backup::create($GLOBALS['wpdb']->get_driver()->get_sqlite_pdo(),
                ABSPATH, dirname(DB_PATH), WPLITE_SITE_ID, $GLOBALS['table_prefix'],
                $options['output-dir'] ?? null, defined('WPLITE_PUBLIC_ROOT') ? WPLITE_PUBLIC_ROOT : ABSPATH);
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    if (in_array($command, ['restore', 'restore-status', 'restore-rollback', 'restore-complete'], true)) {
        require_once __DIR__ . '/src/Restore.php';
        $options = wpliteArguments(array_slice($argv, 2), $command === 'restore' ? ['data-dir', 'backup-dir'] : ['data-dir']);
        if (empty($options['data-dir']) || ($command === 'restore' && empty($options['backup-dir']))) {
            throw new RuntimeException('Recovery requires the private --data-dir, and restore also requires --backup-dir.');
        }
        $report = match ($command) {
            'restore' => \WPLite\Restore::restore(dirname(__DIR__), $options['data-dir'], $options['backup-dir']),
            'restore-status' => \WPLite\Maintenance::status($options['data-dir']),
            'restore-rollback' => \WPLite\Restore::rollback(dirname(__DIR__), $options['data-dir']),
            'restore-complete' => \WPLite\Restore::complete(dirname(__DIR__), $options['data-dir']),
        };
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    if ($command !== 'install') {
        throw new RuntimeException('Commands: doctor, doctor-site, cron-probe, cron-status, install, backup-create, backup-verify, site-backup-create, site-backup-verify, site-backup-complete, restore, restore-status, restore-rollback, restore-complete.');
    }
    require_once __DIR__ . '/src/Installer.php';
    $options = wpliteArguments(array_slice($argv, 2));
    $passwordKey = $options['password-env'] ?? 'WPLITE_ADMIN_PASSWORD';
    $password = getenv($passwordKey);
    echo json_encode(\WPLite\Installer::install(dirname(__DIR__), $options, $password === false ? '' : $password), JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'WPLite: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
