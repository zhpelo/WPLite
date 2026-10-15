#!/usr/bin/env python3
"""Real HTTP owner-authorized installation; optional unprivileged Apache gate."""

import argparse
import contextlib
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
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


def port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def request(url, fields=None, headers=None, opener=None):
    opener = opener or urllib.request.build_opener(urllib.request.ProxyHandler({}))
    body = urllib.parse.urlencode(fields).encode() if fields is not None else None
    try:
        with opener.open(urllib.request.Request(url, data=body, headers=headers or {}), timeout=45) as response:
            return response.status, response.read().decode(errors='replace'), dict(response.headers)
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(errors='replace'), dict(error.headers)


@contextlib.contextmanager
def server(arguments, directory, env=None):
    log = tempfile.TemporaryFile(mode='w+')
    process = subprocess.Popen(list(map(str, arguments)), cwd=directory, env=env,
                               stdout=log, stderr=log, start_new_session=True)
    try:
        yield process
    except Exception:
        log.seek(0)
        print(log.read()[-7000:], file=sys.stderr)
        raise
    finally:
        # The isolated PHP development server forks workers in this group only.
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGTERM)
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait(timeout=5)
        log.close()


def ready(process, url):
    for _ in range(100):
        if process.poll() is not None:
            raise RuntimeError('Test server exited before readiness')
        try:
            request(url)
            return
        except urllib.error.URLError:
            time.sleep(0.05)
    raise RuntimeError('Test server did not become ready')


def owner_key(site):
    key = secrets.token_hex(32)
    file = site / 'wplite/setup-key.php'
    file.write_text("<?php\nif (!defined('WPLITE_SETUP_READING')) { http_response_code(404); exit; }\n"
                    + "return ['sha256' => '" + hashlib.sha256(key.encode()).hexdigest() + "'];\n")
    file.chmod(0o600)
    return key


def fields(url, key, data=''):
    return {'setup-key': key, 'url': url, 'title': 'WPLite Web 中文', 'admin-user': 'webadmin',
            'admin-email': 'web@example.invalid', 'admin-password': secrets.token_urlsafe(24),
            'data-dir': str(data), 'table-prefix': 'wp_'}


def no_database(site, data):
    if (site / 'wplite/site-config.php').exists() or (data / 'site.sqlite').exists():
        raise RuntimeError('An unsafe installation request created configuration or a database')


def install_checks(site, url, data):
    endpoint = url + '/wplite/install.php'
    status, body, headers = request(url + '/')
    assert status == 200 and 'SQLite 安装' in body and 'MySQL database' not in body
    assert 'no-store' in headers['Cache-Control'] and 'frame-ancestors' in headers['Content-Security-Policy']
    values = fields(url, secrets.token_hex(32), data)
    assert request(endpoint, values)[0] == 403
    assert not data.exists()
    key = owner_key(site)
    values = fields(url, key, data)
    assert request(url + '/wplite/setup-key.php')[0] == 404
    # GET/query secrets do not authenticate, create locks or echo credentials.
    status, body, _ = request(endpoint + '?setup-key=' + key)
    assert status == 200 and key not in body and not (site / 'wplite/.install.lock').exists()
    wrong = values | {'setup-key': 'x' * 64}
    status, body, _ = request(endpoint, wrong)
    assert status == 403 and key not in body and values['admin-password'] not in body
    assert not (site / 'wplite/.install.lock').exists()
    os.utime(site / 'wplite/setup-key.php', (time.time() - 3700,) * 2)
    assert request(endpoint, values)[0] == 403
    key = owner_key(site)
    values['setup-key'] = key
    assert request(endpoint, values, {'Origin': 'https://attacker.invalid'})[0] == 400
    assert request(endpoint, values | {'url': 'http://attacker.invalid'})[0] == 400
    assert request(endpoint, values | {'admin-password': 'short'})[0] == 400
    assert request(endpoint, values | {'data-dir': str(data.parent / '..' / data.name)})[0] == 400
    assert not data.exists()
    no_database(site, data)
    import fcntl
    with (site / 'wplite/.install.lock').open('a') as lease:
        fcntl.flock(lease, fcntl.LOCK_EX | fcntl.LOCK_NB)
        status, body, _ = request(endpoint, values)
        assert status == 400 and 'Another installation' in body
    no_database(site, data)
    return values


def verify_site(site, url, values, data):
    state = json.loads((data / 'site.json').read_text())
    assert state['status'] == 'installed' and state['database'] == str(data / 'site.sqlite')
    assert (data / 'site.sqlite').read_bytes().startswith(b'SQLite format 3\0')
    assert not (site / 'wplite/setup-key.php').exists()
    assert request(url + '/wplite/site-config.php')[0] == 404
    assert request(url + '/wplite/install.php', values)[0] == 410
    assert request(url + '/wp-admin/install.php')[0] == 200  # native core says already installed
    browser = urllib.request.build_opener(urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    request(url + '/wp-login.php', opener=browser)
    status, body, _ = request(url + '/wp-login.php', {'log': values['admin-user'],
        'pwd': values['admin-password'], 'testcookie': '1', 'redirect_to': url + '/wp-admin/'}, opener=browser)
    assert status == 200 and 'Dashboard' in body and 'login_error' not in body
    status, body, _ = request(url + '/')
    assert status == 200 and 'WPLite Web' in body and 'wp-admin-bar-my-account' not in body
    return state


def site_from(archive, directory):
    with zipfile.ZipFile(archive) as bundle:
        bundle.extractall(directory)
    site = directory / 'wplite'
    shutil.copy2(ROOT / 'tests/fixtures/offline.php', site / 'wp-content/mu-plugins/wplite-test-offline.php')
    return site


def apache_config(directory, site, httpd, modules, backend, front):
    config = directory / 'httpd.conf'
    names = ['mpm_prefork', 'unixd', 'authz_core', 'dir', 'proxy', 'proxy_http', 'rewrite']
    builtins = subprocess.run([str(httpd), '-l'], check=True, capture_output=True, text=True).stdout
    load = ''
    for name in names:
        library = modules / ('mod_' + name + '.so')
        if library.is_file():
            load += f'LoadModule {name}_module "{library}"\n'
        elif 'mod_' + name + '.c' not in builtins:
            raise RuntimeError('Missing Apache test module: ' + name)
    config.write_text(load + f'''ServerRoot "{directory}"
DefaultRuntimeDir "{directory}"
PidFile "{directory}/httpd.pid"
ErrorLog "{directory}/error.log"
Listen 127.0.0.1:{front}
ServerName 127.0.0.1
User {__import__('pwd').getpwuid(os.getuid()).pw_name}
Group {__import__('grp').getgrgid(os.getgid()).gr_name}
DocumentRoot "{site}"
DirectoryIndex index.php
StartServers 1
MinSpareServers 1
MaxSpareServers 2
ServerLimit 4
MaxRequestWorkers 4
ProxyPreserveHost On
<Directory "{site}">
  Require all granted
  AllowOverride AuthConfig
  Options -Indexes +FollowSymLinks
  RewriteEngine On
  RewriteCond %{{THE_REQUEST}} "\\s(/[^\\s?]*)"
  RewriteRule ^(.*\\.php)$ http://127.0.0.1:{backend}%1 [P,L]
</Directory>
''')
    return config


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', type=Path, required=True)
    parser.add_argument('--httpd', type=Path)
    parser.add_argument('--apache-modules', type=Path)
    args = parser.parse_args()
    php = args.php.resolve()
    env = os.environ.copy() | {'PHP_CLI_SERVER_WORKERS': '4'}
    with tempfile.TemporaryDirectory(prefix='web-install-', dir=ROOT / '.cache') as temporary:
        temporary = Path(temporary)
        artifact = w.build(ROOT, ROOT / 'upstream.lock.json', temporary / 'build')['archive']
        site = site_from(artifact, temporary / 'private-public')
        private = temporary / 'private-data'
        number = port()
        url = f'http://127.0.0.1:{number}'
        with server([php, '-n', '-S', f'127.0.0.1:{number}', '-t', site], site, env) as running:
            ready(running, url)
            # A public non-loopback host must not even display a plaintext credential form.
            status, body, _ = request(url + '/wplite/install.php', headers={'Host': 'example.invalid'})
            assert status == 400 and 'HTTPS is required' in body and '<form' not in body
            values = install_checks(site, url, private)
            status, body, _ = request(url + '/wplite/install.php', values)
            assert status == 201, body
            assert verify_site(site, url, values, private)['storage']['mode'] == 'private'
        print('PASS: authenticated HTTPS-gated Web install, expiry, CSRF/origin checks, no MySQL wizard, login and duplicate refusal', flush=True)

        site = site_from(artifact, temporary / 'nested-public')
        private = temporary / 'nested-private'
        number = port()
        url = f'http://127.0.0.1:{number}/wplite'
        with server([php, '-n', '-S', f'127.0.0.1:{number}', '-t', site.parent], site, env) as running:
            ready(running, url + '/')
            values = fields(url, owner_key(site), private)
            values['table-prefix'] = 'nested_'
            assert request(url + '/wplite/install.php', values)[0] == 201
            state = verify_site(site, url, values, private)
            assert state['public_root'] == str(site.parent)
            assert state['table_prefix'] == 'nested_'
        print('PASS: subdirectory Web installation uses the actual parent document root', flush=True)

        site = site_from(artifact, temporary / 'exposed-public')
        number = port()
        url = f'http://127.0.0.1:{number}'
        with server([php, '-n', '-S', f'127.0.0.1:{number}', '-t', site], site, env) as running:
            ready(running, url)
            values = fields(url, owner_key(site))
            status, body, _ = request(url + '/wplite/install.php', values)
            assert status == 400 and 'publicly accessible' in body, body
            data = site / '.wplite-data'
            no_database(site, data)
            assert sorted(p.name for p in data.iterdir()) == ['.htaccess', '.wplite-prepared.json', 'index.php']
            assert not list(site.glob('wplite-probe-*'))
        print('PASS: server ignoring .htaccess cannot install in public storage; no database, secrets or leftover probe files', flush=True)

        for mode in ['bad-control', 'redirect', 'server-error', 'sqlite-only', 'backups-exposed', 'denied-but-leaking']:
            number = port()
            url = f'http://127.0.0.1:{number}'
            scenario = env | {'WPLITE_TEST_SITE': str(site), 'WPLITE_TEST_PROBE_MODE': mode}
            with server([php, '-n', '-S', f'127.0.0.1:{number}', '-t', site,
                         ROOT / 'tests/fixtures/probe_router.php'], site, scenario) as running:
                ready(running, url)
                values = fields(url, owner_key(site))
                status, body, _ = request(url + '/wplite/install.php', values)
                assert status == 400, (mode, status, body)
                no_database(site, data)
                assert sorted(p.name for p in data.iterdir()) == ['.htaccess', '.wplite-prepared.json', 'index.php']
                assert not list(site.glob('wplite-probe-*'))
        print('PASS: false control, redirects, 500, extension-only denial, exposed backups and leaking 403 responses all fail closed', flush=True)

        if args.httpd:
            if not args.apache_modules:
                raise RuntimeError('--httpd requires --apache-modules')
            # Retry the same prepared directory after real server protection is
            # available; no manual deletion or destructive reset is needed.
            backend, front = port(), port()
            url = f'http://127.0.0.1:{front}'
            config = apache_config(temporary, site, args.httpd.resolve(), args.apache_modules.resolve(), backend, front)
            with server([php, '-n', '-S', f'127.0.0.1:{backend}', '-t', site], site, env) as php_server:
                ready(php_server, f'http://127.0.0.1:{backend}')
                with server([args.httpd.resolve(), '-f', config, '-DFOREGROUND'], site) as apache:
                    ready(apache, url)
                    values = fields(url, owner_key(site))
                    status, body, _ = request(url + '/wplite/install.php', values)
                    assert status == 201, body
                    data = site / '.wplite-data'
                    assert verify_site(site, url, values, data)['storage']['mode'] == 'http-denied'
                    assert request(url + '/.wplite-data/site.sqlite')[0] == 403
                    assert request(url + '/.wplite-data/site.json')[0] == 403
                    # Backup and independent recovery work under the same denied tree.
                    created = subprocess.run([str(php), '-n', str(site / 'wplite/cli.php'), 'backup-create'],
                        check=True, capture_output=True, text=True)
                    backup = json.loads(created.stdout)
                    backup_url = url + '/.wplite-data/' + str(Path(backup['directory']).relative_to(data)) + '/database.sqlite'
                    assert request(backup_url)[0] == 403
                    subprocess.run([str(php), '-n', str(site / 'wplite/cli.php'), 'restore', '--data-dir', str(data),
                        '--backup-dir', backup['directory']], check=True, capture_output=True, text=True)
                    for origin in [None, 'http://example.invalid', url + '/wrong-path',
                                   'https://user:fixture@example.invalid', url + '#fragment']:
                        command = [str(php), '-n', str(site / 'wplite/cli.php'), 'site-backup-create', '--data-dir', str(data)]
                        if origin is not None:
                            command += ['--public-origin', origin]
                        rejected = subprocess.run(command, capture_output=True, text=True)
                        assert rejected.returncode == 1 and not (data / 'maintenance.json').exists()
                    full = subprocess.run([str(php), '-n', str(site / 'wplite/cli.php'), 'site-backup-create',
                        '--data-dir', str(data), '--public-origin', url], check=True, capture_output=True, text=True)
                    full = json.loads(full.stdout)
                    snapshot = Path(full['directory'])
                    manifest = json.loads((snapshot / 'manifest.json').read_text())
                    assert manifest['excluded_paths'] == ['.wplite-data']
                    assert not (snapshot / 'site/.wplite-data').exists()
                    full_url = url + '/.wplite-data/' + str(snapshot.relative_to(data))
                    for relative in ['/manifest.json', '/database.sqlite', '/site/wplite/site-config.php']:
                        assert request(full_url + relative)[0] == 403
                    subprocess.run([str(php), '-n', str(site / 'wplite/cli.php'), 'site-backup-verify',
                        '--data-dir', str(data), '--backup-dir', str(snapshot)], check=True, capture_output=True, text=True)
                    assert request(url + '/')[0] == 200
                    original = (data / '.htaccess').read_bytes()
                    (data / '.htaccess').write_text('# accidentally removed deny rule\n')
                    assert request(url + '/')[0] == 503
                    (data / '.htaccess').write_bytes(original)
                    assert request(url + '/')[0] == 200
            print('PASS: real unprivileged Apache HTTP-denied storage, database backup/restore, full site snapshot exclusion/privacy and deny-file tamper guard', flush=True)
            # The stored .htaccess hash alone cannot detect changed routing that
            # exempts PHP or images. Probe the new layout before copying secrets.
            database_digest = hashlib.sha256((data / 'site.sqlite').read_bytes()).hexdigest()
            for mode in ['backup-php-exposed', 'backup-images-exposed', 'redirect', 'server-error', 'denied-but-leaking', 'bad-control']:
                number = port()
                origin = f'http://127.0.0.1:{number}'
                scenario = env | {'WPLITE_TEST_SITE': str(site), 'WPLITE_TEST_PROBE_MODE': mode}
                target = data / 'backups' / ('full-probe-' + mode)
                with server([php, '-n', '-S', f'127.0.0.1:{number}', '-t', site,
                             ROOT / 'tests/fixtures/probe_router.php'], site, scenario) as running:
                    ready(running, origin + '/readme.html')
                    state_file = data / 'site.json'
                    original_state = state_file.read_bytes()
                    unkeyed = json.loads(original_state)
                    unkeyed.pop('backup_key', None)
                    state_file.write_text(json.dumps(unkeyed))
                    try:
                        rejected = subprocess.run([str(php), '-n', str(site / 'wplite/cli.php'), 'site-backup-create',
                            '--data-dir', str(data), '--output-dir', str(target), '--public-origin', origin], capture_output=True, text=True)
                        assert rejected.returncode == 1, (mode, rejected.stdout, rejected.stderr)
                        assert 'backup_key' not in json.loads(state_file.read_bytes())
                        assert target.is_dir() and not list(target.iterdir())
                        assert not list(site.glob('wplite-probe-*')) and not (data / 'maintenance.json').exists()
                    finally:
                        state_file.write_bytes(original_state)
            assert hashlib.sha256((data / 'site.sqlite').read_bytes()).hexdigest() == database_digest
            print('PASS: fresh full snapshot probes reject PHP/image bypass, redirects, 500, leaking denial and false control before real database/config copying', flush=True)
        else:
            print('NOT RUN: real Apache protected-storage success path (supply --httpd and --apache-modules)', flush=True)


if __name__ == '__main__':
    main()
