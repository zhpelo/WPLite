#!/usr/bin/env python3
"""Prepare only WPLite's MU plugin and pinned SQLite drop-in; never WordPress."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import sys
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
MAX_ARCHIVE = 96 * 1024 * 1024
DRIVER_ROOT = 'plugin-sqlite-database-integration'
VENDOR = 'wp-content/mu-plugins/wplite/vendor/sqlite/'


class Error(RuntimeError):
    pass


class SafeDownloadRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        if not new_url.startswith('https://'):
            raise Error('Insecure download redirect')
        result = super().redirect_request(request, fp, code, message, headers, new_url)
        if result is not None:
            result.remove_header('Authorization')
        return result


def download(url: str, maximum: int = MAX_ARCHIVE) -> bytes:
    if not url.startswith('https://'):
        raise Error('Only HTTPS downloads are allowed')
    request = urllib.request.Request(url, headers={'User-Agent': 'WPLite-compatibility/0.2'})
    with urllib.request.build_opener(SafeDownloadRedirect()).open(request, timeout=60) as response:
        if not response.url.startswith('https://'):
            raise Error('Insecure download redirect')
        payload = response.read(maximum + 1)
    if len(payload) > maximum:
        raise Error('Download exceeds the size limit')
    return payload


def safe_member(name: str) -> PurePosixPath:
    if (not name or '\\' in name or '\x00' in name or name.startswith('/')
            or any(part in ('', '.', '..') for part in name.rstrip('/').split('/'))):
        raise Error('Unsafe archive member: ' + repr(name))
    return PurePosixPath(name)


def load_dependency(path: Path = ROOT / 'dependencies.lock.json') -> dict:
    lock = json.loads(path.read_text())
    dependency = lock['sqlite']
    version = dependency['version']
    expected = ('https://github.com/WordPress/sqlite-database-integration/releases/download/v'
                + version + '/plugin-sqlite-database-integration.zip')
    if (lock.get('format') != 1 or set(lock) != {'format', 'sqlite'}
            or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version)
            or dependency['archive_url'] != expected
            or not re.fullmatch(r'[a-f0-9]{64}', dependency['sha256'])):
        raise Error('Expected a pinned official SQLite release and SHA-256')
    return dependency


def archive_files(payload: bytes, root: str, expected_sha: str | None = None,
                  maximum_expanded: int | None = None) -> dict[str, bytes]:
    if expected_sha is not None and hashlib.sha256(payload).hexdigest() != expected_sha:
        raise Error('Archive SHA-256 mismatch')
    files, seen, total = {}, set(), 0
    limit = MAX_ARCHIVE if maximum_expanded is None else maximum_expanded
    with zipfile.ZipFile(io.BytesIO(payload)) as archive:
        for entry in archive.infolist():
            path = safe_member(entry.filename)
            if path.parts[0] != root:
                raise Error('Unexpected archive root')
            if str(path) in seen:
                raise Error('Duplicate archive member')
            seen.add(str(path))
            mode = (entry.external_attr >> 16) & 0o170000
            if mode not in (0, 0o100000, 0o040000):
                raise Error('Archive links and special files are forbidden')
            if entry.is_dir():
                continue
            if len(path.parts) < 2:
                raise Error('Archive root must be a directory')
            total += entry.file_size
            if total > limit:
                raise Error('Expanded archive exceeds the size limit')
            files[str(PurePosixPath(*path.parts[1:]))] = archive.read(entry)
    # Do not allow a file to act as a parent directory of another entry.
    for name in files:
        if any(str(parent) in files for parent in PurePosixPath(name).parents if str(parent) != '.'):
            raise Error('Archive file/directory collision')
    return files


def package_files() -> dict[str, bytes]:
    dependency = load_dependency()
    cache = ROOT / '.cache/downloads' / (dependency['sha256'] + '.zip')
    payload = cache.read_bytes() if cache.exists() else download(dependency['archive_url'])
    driver = archive_files(payload, DRIVER_ROOT, dependency['sha256'])
    if 'wp-includes/sqlite/db.php' not in driver or 'LICENSE' not in driver:
        raise Error('The driver archive has no database entrypoint or license')
    if not cache.exists():
        cache.parent.mkdir(parents=True, exist_ok=True)
        cache.write_bytes(payload)
    files = {}
    for path in sorted((ROOT / 'plugin').rglob('*')):
        if path.is_symlink():
            raise Error('Plugin source cannot contain symbolic links')
        if path.is_file():
            name = path.relative_to(ROOT / 'plugin').as_posix()
            if not (name == 'wp-content/db.php' or name.startswith('wp-content/mu-plugins/')):
                raise Error('Only the drop-in and MU plugin may enter the package')
            if name.startswith(VENDOR):
                raise Error('Driver files must come from the pinned upstream archive')
            files[name] = path.read_bytes()
    files.update({VENDOR + name: data for name, data in driver.items()})
    return files


def fingerprint(files: dict[str, bytes]) -> str:
    return hashlib.sha256(json.dumps({name: hashlib.sha256(data).hexdigest()
        for name, data in sorted(files.items())}, separators=(',', ':')).encode()).hexdigest()


def prepare(output: Path | None = None) -> dict:
    files = package_files()
    digest = fingerprint(files)
    output = output or ROOT / '.cache' / ('plugin-' + digest[:16])
    if output.is_symlink():
        raise Error('The package target cannot be a symbolic link')
    if output.exists():
        existing = {p.relative_to(output).as_posix(): p.read_bytes()
                    for p in output.rglob('*') if p.is_file() and not p.is_symlink()}
        if any(p.is_symlink() for p in output.rglob('*')) or existing != files:
            raise Error('Refusing to overwrite an existing, different package directory')
    else:
        output.mkdir(parents=True)
        for name, data in sorted(files.items()):
            target = output / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            target.chmod(0o644)
    return {'package': str(output.resolve()), 'sha256': digest, 'files': len(files),
            'sqlite_driver': load_dependency()['version'], 'contains_wordpress_core': False}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['prepare'])
    parser.add_argument('--output', type=Path, help='New directory; existing different files are never overwritten')
    args = parser.parse_args()
    print(json.dumps(prepare(args.output), indent=2))


if __name__ == '__main__':
    try:
        main()
    except (Error, OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
        print('WPLite: ' + str(error), file=sys.stderr)
        sys.exit(1)
