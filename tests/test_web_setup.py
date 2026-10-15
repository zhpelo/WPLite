import hashlib
import importlib.util
import json
from pathlib import Path
import stat
import tempfile
import unittest


spec = importlib.util.spec_from_file_location('web_setup', Path(__file__).resolve().parents[1] / 'tools/prepare_web_install.py')
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class OwnerKeyTest(unittest.TestCase):
    def test_secret_is_private_and_not_in_php_or_console_metadata(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary) / 'owner'
            report = setup.prepare(directory)
            secret = (directory / 'owner-secret.txt').read_text().splitlines()[0]
            php = (directory / 'setup-key.php').read_text()
            self.assertEqual(len(secret), 64)
            self.assertIn(hashlib.sha256(secret.encode()).hexdigest(), php)
            self.assertNotIn(secret, php)
            self.assertNotIn(secret, json.dumps(report))
            self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o700)
            for file in directory.iterdir():
                self.assertEqual(stat.S_IMODE(file.stat().st_mode), 0o600)

    def test_existing_directory_or_link_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as temporary:
            existing = Path(temporary) / 'existing'
            existing.mkdir()
            marker = existing / 'keep.txt'
            marker.write_text('keep')
            with self.assertRaises(FileExistsError):
                setup.prepare(existing)
            link = Path(temporary) / 'linked'
            link.symlink_to(existing, target_is_directory=True)
            with self.assertRaises(FileExistsError):
                setup.prepare(link)
            self.assertEqual(marker.read_text(), 'keep')


if __name__ == '__main__':
    unittest.main()
