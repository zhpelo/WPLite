"""Local setup refusal tests using temporary, fixture-owned paths only."""

from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / '.cache/test-runtime/install/bin/php'


@unittest.skipUnless(PHP.is_file(), 'Prepare the isolated test PHP first')
class InstallSafetyTests(unittest.TestCase):
    def setup_tree(self, temporary):
        base = Path(temporary)
        site, package = base / 'public/site', base / 'package'
        (site / 'wp-admin').mkdir(parents=True)
        (site / 'wp-content').mkdir()
        (site / 'wp-settings.php').write_text('<?php // fixture only')
        (package / 'wp-content/mu-plugins/wplite/vendor/sqlite/wp-includes/sqlite').mkdir(parents=True)
        (package / 'wp-content/mu-plugins/wplite/vendor/sqlite/wp-includes/sqlite/db.php').write_text('<?php // fixture')
        (package / 'wp-content/db.php').write_text('<?php // fixture')
        return site, package, base / 'private'

    def configure(self, site, package, data):
        return subprocess.run([str(PHP), '-n', str(ROOT / 'tools/install.php'),
            '--wordpress-root', str(site), '--public-root', str(site.parent),
            '--package', str(package), '--data-dir', str(data)], capture_output=True, text=True)

    def test_existing_configuration_integration_or_data_is_preserved(self):
        for name in ['wp-config.php', '../wp-config.php', 'wp-content/db.php',
                     'wp-content/mu-plugins/wplite.php', 'wplite', '../../private']:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as temporary:
                site, package, data = self.setup_tree(temporary)
                existing = site / name
                existing.parent.mkdir(parents=True, exist_ok=True)
                existing.write_text('owner data')
                result = self.configure(site, package, data)
                self.assertNotEqual(0, result.returncode)
                self.assertIn('refusing to overwrite', result.stderr)
                self.assertEqual('owner data', existing.read_text())

    def test_public_database_path_is_rejected_before_any_write(self):
        with tempfile.TemporaryDirectory() as temporary:
            site, package, _ = self.setup_tree(temporary)
            data = site / 'database'
            self.assertNotEqual(0, self.configure(site, package, data).returncode)
            self.assertFalse(data.exists())
            self.assertFalse((site / 'wp-config.php').exists())
            self.assertFalse((site / 'wp-content/db.php').exists())

    def test_package_cannot_deploy_core(self):
        with tempfile.TemporaryDirectory() as temporary:
            site, package, data = self.setup_tree(temporary)
            original = (site / 'wp-settings.php').read_bytes()
            (package / 'wp-settings.php').write_text('malicious replacement')
            self.assertNotEqual(0, self.configure(site, package, data).returncode)
            self.assertEqual(original, (site / 'wp-settings.php').read_bytes())
            self.assertFalse(data.exists())

    def test_linked_content_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            site, package, data = self.setup_tree(temporary)
            (site / 'wp-content').rmdir() # Only this test's empty fixture directory.
            (site / 'wp-content').symlink_to(package / 'wp-content', target_is_directory=True)
            self.assertNotEqual(0, self.configure(site, package, data).returncode)
            self.assertFalse(data.exists())

    def test_new_configuration_and_database_are_private(self):
        with tempfile.TemporaryDirectory(prefix="wplite-'中文-") as temporary:
            site, package, data = self.setup_tree(temporary)
            result = self.configure(site, package, data)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(0o600, (site / 'wp-config.php').stat().st_mode & 0o777)
            self.assertEqual(0o700, data.stat().st_mode & 0o777)
            self.assertEqual(0o600, (data / 'site.sqlite').stat().st_mode & 0o777)
            self.assertTrue((data / 'site.sqlite').read_bytes().startswith(b'SQLite format 3\x00'))
            syntax = subprocess.run([str(PHP), '-n', '-l', str(site / 'wp-config.php')], capture_output=True)
            self.assertEqual(0, syntax.returncode)
