#!/usr/bin/env python3
"""Exercise the actual locked release on a PHP build with no MySQL extensions."""

import argparse
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import wplite as w


def php_run(php, arguments, env):
    process = subprocess.run([str(php), '-n', *map(str, arguments)], env=env,
                             capture_output=True, text=True, timeout=90)
    if process.returncode:
        raise RuntimeError('PHP integration command failed:\n' + process.stdout + process.stderr)
    return process.stdout


def request(opener, url, fields=None):
    data = urllib.parse.urlencode(fields).encode() if fields is not None else None
    try:
        with opener.open(urllib.request.Request(url, data=data), timeout=20) as response:
            return response.status, response.read().decode(errors='replace')
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(errors='replace')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', type=Path, required=True)
    args = parser.parse_args()
    php = args.php.resolve()
    modules = php_run(php, ['-m'], os.environ.copy()).splitlines()
    if 'pdo_sqlite' not in modules or 'mysqli' in modules or 'pdo_mysql' in modules:
        raise RuntimeError('The smoke test requires PDO SQLite and no mysqli/pdo_mysql extensions')
    cache = ROOT / '.cache'
    cache.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='smoke-', dir=cache) as temporary:
        temporary = Path(temporary)
        first = w.build(ROOT, ROOT / 'upstream.lock.json', temporary / 'one')
        second = w.build(ROOT, ROOT / 'upstream.lock.json', temporary / 'two')
        if first['sha256'] != second['sha256']:
            raise RuntimeError('Repeated builds produced different ZIP bytes')
        with zipfile.ZipFile(first['archive']) as archive:
            archive.extractall(temporary / 'public')
        site = temporary / 'public/wplite'
        shutil.copyfile(ROOT / 'tests/fixtures/offline.php', site / 'wp-content/mu-plugins/offline-test.php')
        with socket.socket() as available:
            available.bind(('127.0.0.1', 0))
            port = available.getsockname()[1]
        url = f'http://127.0.0.1:{port}'
        env = os.environ.copy()
        env.update(WPLITE_ADMIN_PASSWORD='test-only-' + os.urandom(16).hex(), WPLITE_TEST_SITE=str(site))
        data = temporary / 'private'
        env['WPLITE_TEST_DATA_DIR'] = str(data)
        install = [site / 'wplite/cli.php', 'install', '--url', url, '--title', 'WPLite Smoke',
                   '--admin-user', 'smokeadmin', '--admin-email', 'nobody@example.invalid',
                   '--data-dir', data, '--public-root', temporary / 'public']
        php_run(php, install, env)
        result = json.loads(php_run(php, [ROOT / 'tests/fixtures/exercise.php'], env))
        print('PASS: real installation, Unicode CRUD, dbDelta, transactions, WAL/FULL and update guard', flush=True)
        backup = json.loads(php_run(php, [ROOT / 'tests/fixtures/backup.php'], env))
        created = json.loads(php_run(php, [site / 'wplite/cli.php', 'backup-create'], env))
        if created['status'] != 'created' or created['authenticated'] is not True:
            raise RuntimeError('CLI database backup creation failed')
        print('PASS: consistent WAL backup, authenticated read-only verification and 12 backup safeguards', flush=True)
        holder = subprocess.Popen([str(php), '-n', str(ROOT / 'tests/fixtures/hold_request.php')],
                                  env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            for attempt in range(100):
                if (data / 'guard-ready').exists():
                    break
                if holder.poll() is not None:
                    raise RuntimeError('Request guard fixture exited before becoming ready')
                time.sleep(0.02)
            else:
                raise RuntimeError('Request guard fixture was not ready')
            php_run(php, [ROOT / 'tests/fixtures/restore.php', 'restore', backup['directory']], env)
            _, errors = holder.communicate(timeout=10)
            if holder.returncode or errors or not (data / 'guard-finished').exists():
                raise RuntimeError('Restore did not drain request shutdown hooks: ' + errors)
        finally:
            if holder.poll() is None:
                holder.terminate()
                holder.wait(timeout=5)
        print('PASS: authenticated database restore, original preservation and request shutdown draining', flush=True)
        repeat = subprocess.run([str(php), '-n', *map(str, install)], env=env, capture_output=True, text=True)
        if repeat.returncode == 0 or 'Refusing to overwrite' not in repeat.stderr:
            raise RuntimeError('Repeated installation did not fail closed')
        server_log = temporary / 'server.log'
        with server_log.open('w+') as log:
            server = subprocess.Popen([str(php), '-n', '-S', f'127.0.0.1:{port}', '-t', str(site)],
                                      env=env, stdout=log, stderr=log)
            try:
                guest = urllib.request.build_opener(urllib.request.ProxyHandler({}))
                for attempt in range(100):
                    if server.poll() is not None:
                        raise RuntimeError('Test HTTP server exited unexpectedly')
                    try:
                        status, body = request(guest, url + '/')
                        break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    raise RuntimeError('HTTP server did not become ready')
                if status != 200 or 'WPLite Smoke' not in body:
                    raise RuntimeError(f'Homepage failed: {status}\n' + body[:2000])
                status, body = request(guest, url + '/?rest_route=/wp/v2/posts/' + str(result['post_id']))
                if status != 200 or json.loads(body)['title']['rendered'] != 'WPLite SQLite 中文 😀':
                    raise RuntimeError('Real WordPress REST request failed')
                browser = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                request(browser, url + '/wp-login.php')
                status, body = request(browser, url + '/wp-login.php', {
                    'log': 'smokeadmin', 'pwd': env['WPLITE_ADMIN_PASSWORD'], 'testcookie': '1',
                    'redirect_to': url + '/wp-admin/', 'wp-submit': 'Log In'})
                if status != 200 or 'Dashboard' not in body or 'login_error' in body:
                    raise RuntimeError('Administrator login/dashboard failed:\n' + body[:2000])
                status, body = request(guest, url + '/')
                if status != 200 or 'wp-admin-bar-my-account' in body:
                    raise RuntimeError('Guest request leaked authenticated state')
                print('PASS: homepage, REST, administrator login, dashboard and guest isolation', flush=True)
                php_run(php, [ROOT / 'tests/fixtures/maintenance.php'], env)
                keeper = subprocess.Popen([str(php), '-n', str(ROOT / 'tests/fixtures/maintenance.php'), 'hold'],
                                          env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                try:
                    for attempt in range(100):
                        if (data / 'maintenance-ready').exists():
                            break
                        if keeper.poll() is not None:
                            raise RuntimeError('Maintenance owner exited before the crash probe')
                        time.sleep(0.02)
                    else:
                        raise RuntimeError('Maintenance owner was not ready for the crash probe')
                    keeper.kill()
                    keeper.communicate(timeout=5)
                    if request(guest, url + '/')[0] != 503:
                        raise RuntimeError('Killing the maintenance owner reopened the site')
                finally:
                    if keeper.poll() is None:
                        keeper.terminate()
                        keeper.wait(timeout=5)
                php_run(php, [site / 'wplite/cli.php', 'restore-complete', '--data-dir', data], env)
                if request(guest, url + '/')[0] != 200:
                    raise RuntimeError('Healthy crash recovery did not reopen the site')
                print('PASS: drain timeout, core-version rejection and killed-owner maintenance recovery', flush=True)
                php_run(php, [ROOT / 'tests/fixtures/restore.php', 'prepare-failure', backup['directory']], env)
                if request(guest, url + '/')[0] != 503:
                    raise RuntimeError('Failed restore served ordinary traffic')
                state = json.loads(php_run(php, [site / 'wplite/cli.php', 'restore-status', '--data-dir', data], env))
                if state['phase'] != 'failed':
                    raise RuntimeError('Independent recovery status could not inspect a failed restore')
                shutil.copyfile(data / 'dropin-before-test.php', site / 'wp-content/db.php')
                if request(guest, url + '/')[0] != 503:
                    raise RuntimeError('Repairing code reopened the failed restore before explicit recovery')
                rollback = json.loads(php_run(php, [site / 'wplite/cli.php', 'restore-rollback', '--data-dir', data], env))
                if rollback['status'] != 'rolled-back':
                    raise RuntimeError('Independent rollback failed')
                php_run(php, [ROOT / 'tests/fixtures/restore.php', 'verify-rollback'], env)
                if request(guest, url + '/')[0] != 200:
                    raise RuntimeError('Site did not reopen after validated rollback')
                php_run(php, [ROOT / 'tests/fixtures/restore.php', 'prepare-failure', backup['directory']], env)
                shutil.copyfile(data / 'dropin-before-test.php', site / 'wp-content/db.php')
                completion = json.loads(php_run(php, [site / 'wplite/cli.php', 'restore-complete', '--data-dir', data], env))
                if completion['status'] != 'reopened':
                    raise RuntimeError('Independent recovery completion failed')
                php_run(php, [ROOT / 'tests/fixtures/restore.php', 'verify-complete'], env)
                print('PASS: failed restore stays offline; independent rollback and completion both recover correctly', flush=True)
                database = data / 'site.sqlite'
                original = hashlib.sha256(database.read_bytes()).hexdigest()
                hidden = database.with_suffix('.held-for-test')
                database.rename(hidden)
                try:
                    status, _ = request(guest, url + '/')
                    if status != 503 or database.exists():
                        raise RuntimeError('Missing database did not fail closed')
                    verification = json.loads(php_run(php, [site / 'wplite/cli.php', 'backup-verify',
                        '--backup-dir', backup['directory'], '--data-dir', data], env))
                    if verification['status'] != 'verified' or database.exists():
                        raise RuntimeError('Independent backup verification requires or recreates the live database')
                finally:
                    hidden.rename(database)
                dropin = site / 'wp-content/db.php'
                hidden = dropin.with_suffix('.held-for-test')
                dropin.rename(hidden)
                try:
                    status, _ = request(guest, url + '/')
                    if status != 503:
                        raise RuntimeError('Missing drop-in did not fail closed')
                finally:
                    hidden.rename(dropin)
                if hashlib.sha256(database.read_bytes()).hexdigest() != original:
                    raise RuntimeError('Failure probes changed the database')
                database.rename(database.with_suffix('.original-held-for-recovery'))
                recovered = json.loads(php_run(php, [site / 'wplite/cli.php', 'restore', '--data-dir', data,
                    '--backup-dir', backup['directory']], env))
                if recovered['status'] != 'restored' or request(guest, url + '/')[0] != 200:
                    raise RuntimeError('Independent restore could not recover a missing live database')
                print('PASS: missing live database recovered without loading WordPress during restore', flush=True)
                print('PASS: missing database/drop-in return 503 without creating or changing data', flush=True)
            except Exception:
                log.flush()
                print(server_log.read_text()[-7000:], file=sys.stderr)
                raise
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
        print(json.dumps({'status': 'passed', 'wordpress': w.load_lock(ROOT / 'upstream.lock.json')['wordpress']['tag'],
                          'sqlite': result['sqlite'], 'mysql_extensions': False,
                          'reproducible_sha256': first['sha256']}, indent=2))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('WPLite smoke test failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
