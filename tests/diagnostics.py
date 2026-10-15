#!/usr/bin/env python3
"""Real WordPress/PDO diagnostics, administrator authorization and observed Cron."""

import argparse
import hashlib
import html
from http.cookiejar import CookieJar
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request
import zipfile

import web_install as http

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import wplite as w


def execute(php, arguments, env, code=0):
    process = subprocess.run([str(php), '-n', *map(str, arguments)], env=env, capture_output=True, text=True, timeout=40)
    if process.returncode != code:
        fatal = '\n'.join(line.split(' in ')[0] for line in process.stdout.splitlines() if 'Fatal error:' in line or 'Uncaught ' in line)
        raise RuntimeError('Unexpected diagnostic command exit: ' + str(process.returncode) + '\n' + process.stderr[:2000] + fatal)
    return json.loads(process.stdout) if process.stdout.strip() else {}


def panel(browser, url):
    status, body, _ = http.request(url + '/wp-admin/tools.php?page=wplite-diagnostics', opener=browser)
    assert status == 200 and 'WPLite 运行诊断' in body
    payload = re.search(r'<pre id="wplite-diagnostics-json"[^>]*>(.*?)</pre>', body, re.S)
    nonce = re.search(r'name="_wpnonce"[^>]*value="([^"]+)"', body)
    assert payload and nonce
    return json.loads(html.unescape(payload[1])), nonce[1], body


def login(url, user, password):
    browser = urllib.request.build_opener(urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(CookieJar()))
    http.request(url + '/wp-login.php', opener=browser)
    status, body, _ = http.request(url + '/wp-login.php', {'log': user, 'pwd': password,
        'testcookie': '1', 'redirect_to': url + '/wp-admin/'}, opener=browser)
    assert status == 200 and 'login_error' not in body
    return browser


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', type=Path, required=True)
    php = parser.parse_args().php.resolve()
    with tempfile.TemporaryDirectory(prefix='diagnostics-', dir=ROOT / '.cache') as temporary:
        temporary = Path(temporary)
        artifact = w.build(ROOT, ROOT / 'upstream.lock.json', temporary / 'build')['archive']
        with zipfile.ZipFile(artifact) as bundle:
            bundle.extractall(temporary / 'public')
        site = temporary / 'public/wplite'
        data = temporary / 'private'
        mode = temporary / 'mode.txt'
        mode.write_text('blocked')
        number = http.port()
        url = f'http://127.0.0.1:{number}'
        env = os.environ.copy() | {'WPLITE_TEST_SITE': str(site), 'WPLITE_TEST_DATA_DIR': str(data),
            'WPLITE_TEST_MODE_FILE': str(mode), 'WPLITE_TEST_CRON_URL': url + '/wp-cron.php',
            'WPLITE_ADMIN_PASSWORD': secrets.token_urlsafe(24), 'WPLITE_TEST_READER_PASSWORD': secrets.token_urlsafe(24),
            'PHP_CLI_SERVER_WORKERS': '4'}
        shutil.copy2(ROOT / 'tests/fixtures/diagnostic_offline.php', site / 'wp-content/mu-plugins/diagnostic-test.php')
        cli = site / 'wplite/cli.php'
        fixture = [ROOT / 'tests/fixtures/diagnostics.php']
        run = lambda arguments, code=0: execute(php, arguments, env, code)
        test = lambda action: run([*fixture, action])
        run([cli, 'install', '--url', url, '--title', 'WPLite Diagnostics', '--admin-user', 'diagnostic-admin',
            '--admin-email', 'owner@example.invalid', '--data-dir', data, '--public-root', temporary / 'public'])
        test('seed')
        run([cli, 'backup-create'])
        state = json.loads((data / 'site.json').read_text())
        salt = re.search(r"define\('AUTH_KEY', '([a-f0-9]+)'\)", (site / 'wplite/site-config.php').read_text())[1]
        def safe(report):
            output = json.dumps(report)
            assert all(value not in output for value in (str(site), str(data), state['site_id'], state['backup_key'], salt))
            assert report['capabilities']['commerce'] == 'unverified'
            assert report['capabilities']['production_certified'] is False
        report = run([cli, 'doctor', '--data-dir', data, '--integrity', 'quick'])
        assert report['ok'] and report['scope'] == 'offline-site' and report['database']['integrity'] == 'ok'
        safe(report)
        wal = test('wal-size')
        assert wal['ok'] and wal['database']['logical_bytes'] > wal['database']['bytes']
        safe(wal)
        report = run([cli, 'doctor-site'])
        assert report['ok'] and report['capabilities']['content'] == 'basic_runtime_ready'
        assert report['database']['integrity'] == 'not_checked'
        safe(report)
        assert test('inspect')['events'] == 0
        assert test('inspect')['record'] == []
        print('PASS: independent/loaded CLI diagnostics, optional real integrity check, safe report and no automatic canary scheduling', flush=True)

        with http.server([php, '-n', '-S', f'127.0.0.1:{number}', '-t', site], site, env) as server:
            http.ready(server, url)
            path = url + '/wp-admin/tools.php?page=wplite-diagnostics'
            status, body, _ = http.request(path)
            assert 'wplite-diagnostics-json' not in body
            endpoint = url + '/wp-admin/admin-post.php'
            assert http.request(endpoint, {'action': 'wplite_cron_probe', 'transport': 'schedule'})[0] in (400, 403)
            reader = login(url, 'diagnostic-reader', env['WPLITE_TEST_READER_PASSWORD'])
            assert http.request(path, opener=reader)[0] == 403
            assert http.request(endpoint, {'action': 'wplite_cron_probe', 'transport': 'schedule'}, opener=reader)[0] == 403
            admin = login(url, 'diagnostic-admin', env['WPLITE_ADMIN_PASSWORD'])
            report, nonce, _ = panel(admin, url)
            safe(report)
            assert report['cron']['status'] == 'not_requested'
            assert http.request(endpoint + '?action=wplite_cron_probe', opener=admin)[0] == 405
            assert http.request(endpoint, {'action': 'wplite_cron_probe', 'transport': 'schedule'}, opener=admin)[0] == 403
            assert http.request(endpoint, {'action': 'wplite_cron_probe', 'transport': 'schedule', '_wpnonce': 'bad'}, opener=admin)[0] == 403
            assert http.request(endpoint, {'action': 'wplite_cron_probe', 'transport': 'other', '_wpnonce': nonce}, opener=admin)[0] == 400
            assert test('inspect')['events'] == 0
            values = {'action': 'wplite_cron_probe', '_wpnonce': nonce, 'transport': 'schedule'}
            assert http.request(endpoint, values, opener=admin)[0] == 200
            before = test('inspect')
            assert before['events'] == 1 and before['autoloaded'] is False
            assert http.request(endpoint, values, opener=admin)[0] == 200
            after = test('inspect')
            assert after['events'] == 1 and after['record']['id'] == before['record']['id']
            test('outside-cron')
            report = run([cli, 'cron-status', '--require-execution', 'yes'], code=1)
            assert report['status'] == 'pending' and report['execution_observed'] is False
            subprocess.run([str(php), '-n', str(site / 'wp-cron.php')], env=env, capture_output=True, check=True, timeout=30)
            report = run([cli, 'cron-status', '--require-execution', 'yes'])
            assert report['execution_observed'] and report['observed_runner'] == 'cli'
            assert report['automatic_trigger'] == 'disabled' and report['recurring_scheduler_verified'] is False
            print('PASS: admin capability/POST/nonce protection, single event reuse, non-autoloaded metadata and real external CLI Cron despite DISABLE_WP_CRON', flush=True)

            test('reset')
            mode.write_text('fake')
            report = run([cli, 'cron-probe', '--transport', 'loopback'])
            assert report['http']['code'] == 200 and report['execution_observed'] is False
            assert run([cli, 'cron-status'])['status'] == 'pending'
            test('reset')
            mode.write_text('traffic-blocked')
            run([cli, 'cron-probe'])
            http.request(url + '/')
            report = run([cli, 'cron-status'])
            assert report['automatic_trigger'] == 'traffic' and report['execution_observed'] is False
            mode.write_text('blocked')
            test('reset')
            print('PASS: neither HTTP 200 without execution nor enabled-but-blocked traffic Cron is counted as execution evidence', flush=True)

            mode.write_text('real')
            _, nonce, _ = panel(admin, url)
            assert http.request(endpoint, {'action': 'wplite_cron_probe', 'transport': 'loopback', '_wpnonce': nonce}, opener=admin)[0] == 200
            for _ in range(60):
                report = run([cli, 'cron-status'])
                if report['execution_observed']:
                    break
                time.sleep(0.05)
            assert report['execution_observed'] and report['observed_runner'] == 'http', report
            assert report['http']['code'] == 200 and report['http']['status'] == 'response_received'
            report, _, _ = panel(admin, url)
            assert report['capabilities']['scheduled_features'] == 'observed_once'
            safe(report)
            print('PASS: real self-HTTP wp-cron execution, preserved parent/child observation and commerce remains unverified', flush=True)
            test('old-observation')
            report = run([cli, 'cron-status'])
            assert report['execution_observed'] and report['recent_observation'] is False
            assert run([cli, 'doctor-site'])['capabilities']['scheduled_features'] == 'unverified'

            mode.write_text('blocked')
            test('reset')
            run([cli, 'cron-probe'])
            test('age')
            assert run([cli, 'cron-status'])['status'] == 'expired'
            subprocess.run([str(php), '-n', str(site / 'wp-cron.php')], env=env, capture_output=True, check=True, timeout=30)
            report = run([cli, 'cron-status'])
            assert report['status'] == 'executed_late' and report['latency_seconds'] >= 180
            test('stale-release')
            report = run([cli, 'cron-status'])
            assert report['status'] == 'stale_release' and report['execution_observed'] is False
            test('invalid-record')
            report = run([cli, 'cron-status'])
            assert report['status'] == 'invalid_record' and report['execution_observed'] is False
            unsafe = test('unsafe-connection')
            assert unsafe['ok'] is False and 'active_connection_policy_invalid' in unsafe['errors']
            safe(unsafe)
            print('PASS: late tasks are labelled late, release changes invalidate old proof and unsafe live connection policy is detected', flush=True)

            database = data / 'site.sqlite'
            held = data / 'site.held-for-diagnostics'
            original = hashlib.sha256(database.read_bytes()).hexdigest()
            database.rename(held)
            try:
                report = run([cli, 'doctor', '--data-dir', data], code=1)
                assert 'database_missing_or_invalid' in report['errors'] and not database.exists()
                safe(report)
                assert hashlib.sha256(held.read_bytes()).hexdigest() == original
            finally:
                held.rename(database)
            test('maintenance')
            try:
                report = run([cli, 'doctor', '--data-dir', data], code=1)
                assert report['maintenance'] == 'active' and report['database']['status'] == 'not_checked'
                safe(report)
            finally:
                test('finish-maintenance')
            state_file = data / 'site.json'
            old = state_file.read_bytes()
            state_file.write_text('invalid-private-state-' + state['backup_key'])
            try:
                report = run([cli, 'doctor', '--data-dir', data], code=1)
                assert report['errors'] == ['site_state_invalid']
                safe(report)
            finally:
                state_file.write_bytes(old)
            foreign = json.loads(old)
            foreign['site_id'] = 'f' * 48
            state_file.write_text(json.dumps(foreign))
            try:
                report = run([cli, 'doctor', '--data-dir', data], code=1)
                assert 'database_unreadable_or_foreign' in report['errors']
                safe(report)
            finally:
                state_file.write_bytes(old)
            if os.geteuid() != 0:
                managed = site / 'WPLITE.md'
                permissions = managed.stat().st_mode & 0o777
                managed.chmod(0)
                try:
                    report = run([cli, 'doctor', '--data-dir', data], code=1)
                    assert 'runtime_mismatch' in report['errors']
                    safe(report)
                finally:
                    managed.chmod(permissions)
            assert run([cli, 'doctor-site'])['ok']
            print('PASS: missing database is not recreated, maintenance avoids database reads and malformed private state is safely reported', flush=True)


if __name__ == '__main__':
    main()
