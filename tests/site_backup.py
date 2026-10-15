#!/usr/bin/env python3
"""Full file/database snapshot checks against the actual locked development ZIP."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import zipfile

import web_install as http

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import wplite as w


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', type=Path, required=True)
    php = parser.parse_args().php.resolve()
    with tempfile.TemporaryDirectory(prefix='site-backup-', dir=ROOT / '.cache') as temporary:
        temporary = Path(temporary)
        artifact = w.build(ROOT, ROOT / 'upstream.lock.json', temporary / 'build')
        with zipfile.ZipFile(artifact['archive']) as archive:
            archive.extractall(temporary / 'public')
        site = temporary / 'public/wplite'
        data = temporary / 'private'
        number = http.port()
        url = f'http://127.0.0.1:{number}'
        env = os.environ.copy() | {'WPLITE_TEST_SITE': str(site), 'WPLITE_TEST_DATA_DIR': str(data),
            'WPLITE_ADMIN_PASSWORD': secrets.token_urlsafe(24)}
        cli = site / 'wplite/cli.php'
        fixture = ROOT / 'tests/fixtures/site_backup.php'
        def run(arguments, code=0, extra=None):
            process = subprocess.run([str(php), '-n', *map(str, arguments)], env=env | (extra or {}),
                                     text=True, capture_output=True, timeout=120)
            assert process.returncode == code, process.stdout[-2000:] + process.stderr[-2000:]
            return json.loads(process.stdout) if process.stdout.strip() and code == 0 else process.stderr
        shutil.copyfile(ROOT / 'tests/fixtures/offline.php', site / 'wp-content/mu-plugins/offline-test.php')
        run([cli, 'install', '--url', url, '--title', 'WPLite Full Backup', '--admin-user', 'backup-owner',
             '--admin-email', 'owner@example.invalid', '--data-dir', data, '--public-root', temporary / 'public'])
        run([fixture, 'seed'])
        custom = {'wp-content/uploads/backup-large.bin': b'payload\0' * (5 * 1024 * 1024),
                  'wp-content/uploads/中文 😀.txt': '备份中文 😀'.encode(),
                  'wp-content/plugins/backup-probe/main.php': b'<?php // saved plugin version\n',
                  'wp-content/themes/backup-probe/style.css': b'/* Theme Name: Backup Probe */\n',
                  '.htaccess': b'# custom site rule\n', '.owner-config': b'private site file\n',
                  '0': b'numeric root filename\n', 'empty-file': b''}
        for name, contents in custom.items():
            file = site / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(contents)
        (site / 'wp-content/uploads/empty-directory').mkdir()
        forbidden = site / 'wp-content/mu-plugins/must-not-load.php'
        forbidden.write_text("<?php if (getenv('WPLITE_NO_WP') === '1') { file_put_contents(getenv('WPLITE_TEST_DATA_DIR') . '/wp-was-loaded', 'bad'); throw new RuntimeException('WordPress must not load during independent backup'); }\n")
        no_wp = {'WPLITE_NO_WP': '1'}
        holder = subprocess.Popen([str(php), '-n', str(ROOT / 'tests/fixtures/hold_request.php')],
                                  env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            for attempt in range(100):
                if (data / 'guard-ready').exists(): break
                if holder.poll() is not None: raise RuntimeError('Request holder exited before backup')
                time.sleep(0.02)
            else: raise RuntimeError('Request holder did not become ready')
            created = run(['-d', 'memory_limit=32M', cli, 'site-backup-create', '--data-dir', data], extra=no_wp)
            _, errors = holder.communicate(timeout=5)
            assert holder.returncode == 0 and not errors and (data / 'guard-finished').exists()
        finally:
            if holder.poll() is None:
                holder.terminate()
                holder.communicate(timeout=5)
        assert created['status'] == 'created' and created['kind'] == 'site' and created['authenticated']
        backup = Path(created['directory'])
        assert not (data / 'wp-was-loaded').exists()
        state = json.loads((data / 'site.json').read_bytes())
        manifest = json.loads((backup / 'manifest.json').read_bytes())
        assert state['backup_key'] not in (backup / 'manifest.json').read_text()
        assert 'backup_key' not in manifest['site_state'] and manifest['excluded_paths'] == []
        for name, contents in custom.items():
            assert (backup / 'site' / name).read_bytes() == contents
        assert (backup / 'site/wp-content/uploads/empty-directory').is_dir()
        assert (backup / 'site/wplite/site-config.php').read_bytes() == (site / 'wplite/site-config.php').read_bytes()
        for file in backup.rglob('*'):
            assert not file.is_symlink() and file.stat().st_mode & 0o077 == 0
        with sqlite3.connect('file:' + str(backup / 'database.sqlite') + '?mode=ro&immutable=1', uri=True) as db:
            value = db.execute("SELECT option_value FROM wp_options WHERE option_name='wplite_full_backup_probe'").fetchone()[0]
            assert value == 'consistent 中文 😀'
        verify = lambda path=backup, code=0: run([cli, 'site-backup-verify', '--data-dir', data, '--backup-dir', path], code, no_wp)
        assert verify()['status'] == 'verified'
        assert not (data / 'wp-was-loaded').exists()
        print('PASS: drained full site backup streams 40 MiB media under PHP 32 MiB, includes config/plugins/themes/dotfiles/empty directories and never bootstraps WordPress', flush=True)

        original_manifest = (backup / 'manifest.json').read_bytes()
        file = backup / 'site/wp-content/uploads/中文 😀.txt'
        original = file.read_bytes()
        file.write_bytes(b'altered')
        assert 'verification' in verify(code=1)
        file.write_bytes(original)
        (backup / 'unsigned-extra').write_text('must reject')
        assert 'unsigned' in verify(code=1)
        (backup / 'unsigned-extra').unlink()
        (backup / 'site/wp-content/uploads/unsigned.txt').write_text('must reject')
        (backup / 'site/wp-content/uploads/unsigned.txt').chmod(0o600)
        assert 'tree differs' in verify(code=1)
        (backup / 'site/wp-content/uploads/unsigned.txt').unlink()
        altered = json.loads(original_manifest)
        altered['created_at'] = 'forged'
        (backup / 'manifest.json').write_text(json.dumps(altered))
        assert 'authentication' in verify(code=1)
        (backup / 'manifest.json').write_bytes(original_manifest)
        file.chmod(0o644)
        assert 'non-private' in verify(code=1)
        file.chmod(0o600)
        moved = backup / 'site/wp-content/uploads/held.txt'
        file.rename(moved)
        file.symlink_to(moved)
        assert 'links' in verify(code=1)
        file.unlink()
        moved.rename(file)
        assert verify()['status'] == 'verified'
        state_file = data / 'site.json'
        state_bytes = state_file.read_bytes()
        try:
            for key, value in [('backup_key', 'e' * 64), ('site_id', 'f' * 48)]:
                changed = dict(state)
                changed[key] = value
                state_file.write_text(json.dumps(changed))
                verify(code=1)
        finally:
            state_file.write_bytes(state_bytes)
        run([cli, 'backup-verify', '--data-dir', data, '--backup-dir', backup], 1)
        print('PASS: file/manifest tampering, unsigned extras, unsafe permissions, symlinks and database-only restore confusion fail closed', flush=True)

        database = data / 'site.sqlite'
        held = data / 'database-held-for-test'
        database.rename(held)
        config = site / 'wplite/site-config.php'
        saved_config = data / 'config-held-for-test.php'
        config.rename(saved_config)
        try:
            assert verify()['status'] == 'verified' and not database.exists() and not config.exists()
            saved_config.rename(config)
            run([cli, 'site-backup-create', '--data-dir', data], 1, no_wp)
            assert not database.exists()
        finally:
            held.rename(database)
            if saved_config.exists(): saved_config.rename(config)
        print('PASS: full backup verification neither loads WordPress nor depends on or recreates live database/configuration', flush=True)

        # Genuine filesystem rejection paths; no environmental safeguards disabled.
        escape = site / 'wp-content/uploads/escape'
        escape.symlink_to(data / 'site.json')
        assert 'links' in run([cli, 'site-backup-create', '--data-dir', data], 1, no_wp)
        escape.unlink()
        os.link(site / 'empty-file', escape)
        assert 'links' in run([cli, 'site-backup-create', '--data-dir', data], 1, no_wp)
        escape.unlink()
        os.mkfifo(escape)
        assert 'special files' in run([cli, 'site-backup-create', '--data-dir', data], 1, no_wp)
        escape.unlink()
        if os.geteuid() != 0:
            (site / 'empty-file').chmod(0)
            run([cli, 'site-backup-create', '--data-dir', data], 1, no_wp)
            (site / 'empty-file').chmod(0o644)
        run([cli, 'site-backup-create', '--data-dir', data, '--output-dir', site / 'exposed-backup'], 1, no_wp)
        run([cli, 'site-backup-create', '--data-dir', data, '--output-dir', backup], 1, no_wp)
        assert 'enabled fsync' in run(['-d', 'disable_functions=fsync', cli, 'site-backup-create', '--data-dir', data], 1, no_wp)
        assert not (data / 'maintenance.json').exists() and verify()['status'] == 'verified'
        print('PASS: source links/hardlinks/FIFO, unreadable files, public output and overwrite attempts leave original site and prior backup intact', flush=True)

        unexpected = data / 'backups/external-write'
        error = run([fixture, 'create', unexpected], 1, {'WPLITE_COPY_FAULT': 'external-write', **no_wp})
        assert 'outside the drained request protocol' in error and not (unexpected / 'manifest.json').exists()
        (site / 'unexpected-update.txt').unlink()
        assert not (data / 'maintenance.json').exists()
        print('PASS: out-of-protocol filesystem changes reject the snapshot before manifest commit', flush=True)
        run([fixture, 'database-maintenance'])
        run([cli, 'site-backup-complete', '--data-dir', data], 1)
        assert run([cli, 'restore-complete', '--data-dir', data])['status'] == 'reopened'

        log = temporary / 'server.log'
        with log.open('w+') as output:
            server = subprocess.Popen([str(php), '-n', '-S', f'127.0.0.1:{number}', '-t', str(site)],
                                      env=env, stdout=output, stderr=output)
            try:
                for attempt in range(100):
                    try:
                        if http.request(url)[0] == 200: break
                    except OSError: pass
                    time.sleep(0.05)
                else: raise RuntimeError('Full backup HTTP fixture did not start')
                interrupted = data / 'backups/interrupted'
                owner = subprocess.Popen([str(php), '-n', str(fixture), 'create', str(interrupted)],
                    env=env | no_wp | {'WPLITE_COPY_FAULT': 'hold'}, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                try:
                    for attempt in range(600):
                        if (data / 'copy-paused').exists(): break
                        if owner.poll() is not None: raise RuntimeError('Backup owner exited before crash probe: ' + owner.communicate()[1])
                        time.sleep(0.05)
                    else: raise RuntimeError('Backup did not reach payload-copy pause')
                    assert http.request(url)[0] == 503
                    run([cli, 'site-backup-create', '--data-dir', data], 1)
                    owner.kill()
                    owner.communicate(timeout=5)
                    assert http.request(url)[0] == 503 and not (interrupted / 'manifest.json').exists()
                    run([cli, 'restore-complete', '--data-dir', data], 1)
                    assert run([cli, 'site-backup-complete', '--data-dir', data])['status'] == 'reopened'
                    assert http.request(url)[0] == 200
                    verify(interrupted, 1)
                    assert (site / 'wp-content/uploads/backup-large.bin').read_bytes() == custom['wp-content/uploads/backup-large.bin']
                finally:
                    if owner.poll() is None:
                        owner.terminate()
                        owner.communicate(timeout=5)
            finally:
                server.terminate()
                server.wait(timeout=5)
        print('PASS: killed backup owner leaves HTTP 503, no committed manifest and unchanged originals; typed independent completion safely reopens', flush=True)
        print(json.dumps({'status': 'passed', 'files': created['files'], 'artifact_sha256': artifact['sha256']}), flush=True)


if __name__ == '__main__':
    main()
