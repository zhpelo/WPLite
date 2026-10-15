#!/usr/bin/env python3
"""Install on official core, run its native updater, and verify SQLite/HTTP behavior."""

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

from support import ROOT, core, latest, write_tree, check_core, w


def php_run(php, arguments, env, expected=True):
    process = subprocess.run([str(php), '-n', '-d', 'memory_limit=256M', *map(str, arguments)],
        env=env, capture_output=True, text=True, timeout=240)
    if expected and process.returncode:
        raise RuntimeError('PHP integration failed:\n' + process.stdout + process.stderr)
    return process.stdout if expected else process


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
    parser.add_argument('--from-version', default='7.1.2', help='Stable upgrade baseline, test input only')
    parser.add_argument('--to-version', default='latest', help='Official stable test input, not a runtime pin')
    args = parser.parse_args()
    php = args.php.resolve()
    env = os.environ.copy()
    modules = php_run(php, ['-m'], env).splitlines()
    if 'pdo_sqlite' not in modules or 'mysqli' in modules or 'pdo_mysql' in modules:
        raise RuntimeError('Tests require real PDO SQLite without MySQL extensions')
    target = latest() if args.to_version == 'latest' else args.to_version
    if tuple(map(int, args.from_version.split('.'))) >= tuple(map(int, target.split('.'))):
        raise RuntimeError('The upgrade baseline must be older than the stable target')
    print('Verifying official WordPress test inputs: ' + args.from_version + ' -> ' + target, flush=True)
    before, after = core(args.from_version), core(target)
    cache = ROOT / '.cache'
    cache.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='mu-compat-', dir=cache) as temporary:
        temporary = Path(temporary)
        package = Path(w.prepare(temporary / 'package')['package'])
        site = temporary / 'public/site'
        write_tree(site, before)
        with socket.socket() as available:
            available.bind(('127.0.0.1', 0))
            port = available.getsockname()[1]
        url = f'http://127.0.0.1:{port}'
        env.update(WPLITE_TEST_SITE=str(site), WPLITE_TEST_URL=url,
            WPLITE_ADMIN_PASSWORD='test-only-' + os.urandom(20).hex())
        data = temporary / 'private'
        install = [ROOT / 'tools/install.php', '--package', package, '--wordpress-root', site,
                   '--public-root', temporary / 'public', '--data-dir', data]
        # Setup must reject public data and existing databases/configurations without writes.
        unsafe = install[:-1] + [site / 'exposed-database']
        refused = php_run(php, unsafe, env, expected=False)
        if refused.returncode == 0 or (site / 'wp-config.php').exists() or (site / 'exposed-database').exists():
            raise RuntimeError('Public-root data was not rejected before setup')
        php_run(php, install, env)
        check_core(site, before)
        config = (site / 'wp-config.php').read_bytes()
        original_dropin = (site / 'wp-content/db.php').read_bytes()
        refused = php_run(php, install, env, expected=False)
        if refused.returncode == 0 or (site / 'wp-config.php').read_bytes() != config:
            raise RuntimeError('Setup overwrote an existing configuration')
        shutil.copyfile(ROOT / 'tests/fixtures/offline.php', site / 'wp-content/mu-plugins/offline-test.php')
        print(php_run(php, [ROOT / 'tests/fixtures/install.php'], env).strip(), flush=True)
        result = json.loads(php_run(php, [ROOT / 'tests/fixtures/exercise.php'], env))
        print('PASS: Unicode CRUD, dbDelta, rollback, WAL/FULL, Site Health and unblocked core/plugin/theme updates', flush=True)
        check_core(site, before)
        shutil.copyfile(ROOT / '.cache/compatibility-downloads' / ('wordpress-' + target + '.zip'), temporary / 'upgrade.zip')
        env.update(WPLITE_TEST_UPGRADE_ARCHIVE=str(temporary / 'upgrade.zip'),
                   WPLITE_TEST_TARGET=target, WPLITE_TEST_POST=str(result['post_id']))
        print(php_run(php, [ROOT / 'tests/fixtures/upgrade.php'], env).strip(), flush=True)
        print(php_run(php, [ROOT / 'tests/fixtures/after_upgrade.php'], env).strip(), flush=True)
        # Core's updater may retain old content files, but target core bytes must be exact.
        check_core(site, {name: value for name, value in after.items() if not name.startswith('wp-content/')})
        if (site / 'wp-config.php').read_bytes() != config or (site / 'wp-content/db.php').read_bytes() != original_dropin:
            raise RuntimeError('Native core upgrade changed integration/configuration')
        for name, value in w.package_files().items():
            if (site / name).read_bytes() != value:
                raise RuntimeError('Native core update changed an integration file: ' + name)
        # Repeat behavior checks on the upgraded core, not just the initial install.
        upgraded = json.loads(php_run(php, [ROOT / 'tests/fixtures/exercise.php'], env))
        print('PASS: unmodified target core and unchanged integration; SQLite operations work after upgrade', flush=True)
        server_log = temporary / 'server.log'
        with server_log.open('w+') as log:
            server = subprocess.Popen([str(php), '-n', '-S', f'127.0.0.1:{port}', '-t', str(site)],
                env=env, stdout=log, stderr=log)
            try:
                guest = urllib.request.build_opener(urllib.request.ProxyHandler({}))
                for attempt in range(100):
                    if server.poll() is not None: raise RuntimeError('The isolated HTTP server exited')
                    try:
                        status, body = request(guest, url + '/')
                        break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else: raise RuntimeError('The isolated HTTP server did not become ready')
                if status != 200 or 'WPLite Smoke' not in body:
                    raise RuntimeError(f'Homepage failed: {status}\n' + body[:2000])
                status, body = request(guest, url + '/?rest_route=/wp/v2/posts/' + str(upgraded['post_id']))
                if status != 200 or json.loads(body)['title']['rendered'] != 'WPLite SQLite 中文 😀':
                    raise RuntimeError('REST failed after core upgrade')
                browser = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                request(browser, url + '/wp-login.php')
                status, body = request(browser, url + '/wp-login.php', {
                    'log': 'smokeadmin', 'pwd': env['WPLITE_ADMIN_PASSWORD'], 'testcookie': '1',
                    'redirect_to': url + '/wp-admin/', 'wp-submit': 'Log In'})
                if status != 200 or 'Dashboard' not in body or 'login_error' in body:
                    raise RuntimeError('Administrator login/dashboard failed:\n' + body[:2000])
                if 'wp-admin-bar-my-account' in request(guest, url + '/')[1]:
                    raise RuntimeError('Guest request leaked authenticated state')
                database = data / 'site.sqlite'
                saved = database.with_suffix('.held')
                database.rename(saved)
                try:
                    if request(guest, url + '/')[0] != 503 or database.exists():
                        raise RuntimeError('Missing DB was recreated or did not fail closed')
                finally: saved.rename(database)
                dropin = site / 'wp-content/db.php'
                held = site / 'wp-content/db.php.held'
                dropin.rename(held)
                try:
                    if request(guest, url + '/')[0] != 503:
                        raise RuntimeError('Missing drop-in attempted to boot normally')
                finally: held.rename(dropin)
                driver = site / 'wp-content/mu-plugins/wplite/vendor/sqlite/wp-includes/sqlite/db.php'
                driver.rename(driver.with_suffix('.held'))
                try:
                    if request(guest, url + '/')[0] != 503:
                        raise RuntimeError('Missing driver was not rejected')
                finally: driver.with_suffix('.held').rename(driver)
                if request(guest, url + '/')[0] != 200:
                    raise RuntimeError('Site did not resume after restoring fixture files')
                print('PASS: HTTP/REST/login/guest isolation; missing database/drop-in/driver fail safely', flush=True)
            except Exception:
                log.flush()
                print(server_log.read_text()[-7000:], file=sys.stderr)
                raise
            finally:
                server.terminate()
                try: server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
        report = {'status': 'passed', 'wordpress_from': args.from_version, 'wordpress_to': target,
            'sqlite_driver': w.load_dependency()['version'], 'sqlite': result['sqlite'],
            'php': php_run(php, ['-r', 'echo PHP_VERSION;'], env), 'mysql_extensions': False,
            'native_core_upgrader': True, 'core_bytes_unchanged': True,
            'package_sha256': w.fingerprint(w.package_files())}
        print(json.dumps(report, indent=2), flush=True)


if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('WPLite compatibility failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
