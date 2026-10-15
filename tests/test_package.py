"""Small, offline tests for dependency/package boundaries, not Git synchronization."""

import hashlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock
import warnings
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
import wplite as w


def archive(entries):
    stream = io.BytesIO()
    with warnings.catch_warnings(), zipfile.ZipFile(stream, 'w') as output:
        warnings.simplefilter('ignore', UserWarning) # Deliberately malformed duplicate fixture.
        for name, value in entries:
            output.writestr(name, value)
    return stream.getvalue()


class PackageTests(unittest.TestCase):
    def test_lock_has_no_wordpress_or_integration_pin(self):
        lock = json.loads((w.ROOT / 'dependencies.lock.json').read_text())
        self.assertEqual({'format', 'sqlite'}, set(lock))
        self.assertEqual('3.1.0', w.load_dependency()['version'])

    def test_dependency_lock_rejects_other_origin_or_unstable_version(self):
        original = json.loads((w.ROOT / 'dependencies.lock.json').read_text())
        for field, value in [('archive_url', 'https://example.invalid/driver.zip'),
                             ('version', '3.2.0-rc1'), ('sha256', 'unverified')]:
            lock = json.loads(json.dumps(original))
            lock['sqlite'][field] = value
            with tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / 'lock.json'
                path.write_text(json.dumps(lock))
                with self.assertRaises(w.Error): w.load_dependency(path)

    def test_digest_must_match_before_zip_is_opened(self):
        with self.assertRaisesRegex(w.Error, 'SHA-256'):
            w.archive_files(b'not a zip', 'plugin', '0' * 64)

    def test_paths_must_be_relative_and_canonical(self):
        for name in ['../secret', '/root', 'a/../secret', 'a\\b', 'a//b', 'a/./b', 'a\x00b']:
            with self.subTest(name=name), self.assertRaises(w.Error): w.safe_member(name)

    def test_archive_rejects_unexpected_root(self):
        with self.assertRaises(w.Error):
            w.archive_files(archive([('foreign/file.php', b'x')]), 'plugin')

    def test_archive_rejects_symbolic_links(self):
        entry = zipfile.ZipInfo('plugin/link')
        entry.create_system = 3
        entry.external_attr = 0o120777 << 16
        with self.assertRaisesRegex(w.Error, 'links'):
            w.archive_files(archive([(entry, b'/secret')]), 'plugin')

    def test_archive_rejects_duplicate_and_parent_collisions(self):
        for entries in [[('plugin/a', b'1'), ('plugin/a', b'2')],
                        [('plugin/a', b'1'), ('plugin/a/child', b'2')]]:
            with self.assertRaises(w.Error): w.archive_files(archive(entries), 'plugin')

    def test_archive_accepts_regular_files_and_limits_expansion(self):
        payload = archive([('plugin/a.php', b'hello')])
        digest = hashlib.sha256(payload).hexdigest()
        self.assertEqual({'a.php': b'hello'}, w.archive_files(payload, 'plugin', digest))
        with mock.patch.object(w, 'MAX_ARCHIVE', 4), self.assertRaises(w.Error):
            w.archive_files(payload, 'plugin', digest)

    def test_preparation_is_repeatable_and_does_not_overwrite(self):
        files = {'wp-content/db.php': b'drop-in', 'wp-content/mu-plugins/wplite.php': b'mu'}
        with tempfile.TemporaryDirectory() as directory, mock.patch.object(w, 'package_files', return_value=files):
            target = Path(directory) / 'package'
            first, second = w.prepare(target), w.prepare(target)
            self.assertEqual(first, second)
            (target / 'wp-content/db.php').write_bytes(b'operator change')
            with self.assertRaisesRegex(w.Error, 'overwrite'): w.prepare(target)
            self.assertEqual(b'operator change', (target / 'wp-content/db.php').read_bytes())

    def test_preparation_rejects_linked_target(self):
        with tempfile.TemporaryDirectory() as directory, mock.patch.object(w, 'package_files', return_value={}):
            target = Path(directory) / 'target'
            target.symlink_to(Path(directory), target_is_directory=True)
            with self.assertRaises(w.Error): w.prepare(target)

    def test_runtime_is_only_dropin_and_mu_plugin(self):
        files = [path.relative_to(w.ROOT / 'plugin').as_posix()
                 for path in (w.ROOT / 'plugin').rglob('*') if path.is_file()]
        self.assertEqual({'wp-content/db.php', 'wp-content/mu-plugins/wplite.php',
            'wp-content/mu-plugins/wplite/bootstrap.php', 'wp-content/mu-plugins/wplite/environment.php'}, set(files))
        self.assertTrue(all(name == 'wp-content/db.php' or name.startswith('wp-content/mu-plugins/') for name in files))
        text = '\n'.join((w.ROOT / 'plugin' / name).read_text() for name in files)
        self.assertNotIn('upgrader_pre_install', text)
        self.assertNotIn('maintenance.json', text)
        self.assertNotIn('backup_key', text)

    def test_only_readonly_compatibility_workflow_remains(self):
        workflows = list((w.ROOT / '.github/workflows').glob('*.yml'))
        self.assertEqual(['ci.yml'], [path.name for path in workflows])
        workflow = workflows[0].read_text()
        self.assertIn('contents: read', workflow)
        for forbidden in ['contents: write', 'pull-requests: write', 'rebase', 'upload-artifact', 'publish_sync']:
            self.assertNotIn(forbidden, workflow)


if __name__ == '__main__':
    unittest.main()
