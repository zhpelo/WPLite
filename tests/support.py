"""Temporary official WordPress inputs for tests, never runtime dependencies."""

import hashlib
import json
from pathlib import Path
import re
import sys
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import wplite as w


def stable(value: str) -> bool:
    return re.fullmatch(r'[0-9]+\.[0-9]+(?:\.[0-9]+)?', value) is not None


def latest() -> str:
    offers = json.loads(w.download('https://api.wordpress.org/core/version-check/1.7/'))['offers']
    versions = [offer['version'] for offer in offers
                if offer.get('response') == 'upgrade' and stable(offer.get('version', ''))]
    if not versions:
        raise w.Error('No official stable WordPress version was advertised')
    return max(versions, key=lambda value: tuple(map(int, (value.split('.') + ['0'])[:3])))


def core(version: str) -> dict[str, bytes]:
    if not stable(version):
        raise w.Error('Compatibility tests accept stable numeric WordPress versions only')
    cache = ROOT / '.cache/compatibility-downloads'
    cache.mkdir(parents=True, exist_ok=True)
    archive = cache / ('wordpress-' + version + '.zip')
    print('Preparing official WordPress ' + version + (' (cached ZIP)' if archive.exists() else ' (download)'), flush=True)
    payload = archive.read_bytes() if archive.exists() else w.download('https://wordpress.org/wordpress-' + version + '.zip')
    files = w.archive_files(payload, 'wordpress', maximum_expanded=256 * 1024 * 1024)
    # Refresh official checksums on every run, including when the archive is cached.
    query = urllib.parse.urlencode({'version': version, 'locale': 'en_US'})
    print('Checking official file checksums for ' + version, flush=True)
    checksums = json.loads(w.download('https://api.wordpress.org/core/checksums/1.0/?' + query)).get('checksums', {})
    if not checksums or 'wp-settings.php' not in checksums or 'wp-includes/version.php' not in checksums:
        raise w.Error('Missing official WordPress checksums')
    for name in files:
        if name not in checksums or hashlib.md5(files[name], usedforsecurity=False).hexdigest() != checksums[name]:
            raise w.Error('Official WordPress checksum mismatch: ' + name)
    if any(name not in files for name in checksums):
        raise w.Error('Incomplete official WordPress archive')
    if not archive.exists():
        archive.write_bytes(payload)
    return files


def write_tree(root: Path, files: dict[str, bytes]) -> None:
    for name, data in files.items():
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)


def check_core(root: Path, files: dict[str, bytes]) -> None:
    for name, data in files.items():
        if (root / name).read_bytes() != data:
            raise w.Error('WordPress original bytes changed: ' + name)
