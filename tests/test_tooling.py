import copy
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import shutil
import sys
import tempfile
import unittest
from unittest import mock
import zipfile


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import wplite as w


def fixture_driver(extra=None):
    output = io.BytesIO()
    with zipfile.ZipFile(output, 'w') as archive:
        archive.writestr('plugin-sqlite-database-integration/wp-includes/sqlite/db.php', '<?php // fixture driver\n')
        archive.writestr('plugin-sqlite-database-integration/LICENSE', 'Fixture license\n')
        for name, data in (extra or {}).items():
            archive.writestr('plugin-sqlite-database-integration/' + name, data)
    return output.getvalue()


class StableTagsTest(unittest.TestCase):
    def test_numeric_order_and_two_component_tags(self):
        self.assertGreater(w.version('6.9.10'), w.version('6.9.9'))
        self.assertEqual(w.version('6.8'), w.version('6.8.0'))

    def test_reject_development_and_unsafe_refs(self):
        for tag in ('trunk', 'master', '7.2-beta1', '7.2-RC1', 'v7.1', '7.1.3\n', '--all', '../7.1'):
            with self.subTest(tag=tag), self.assertRaises(w.Error):
                w.version(tag)

    def test_annotated_tags_are_peeled_and_prereleases_ignored(self):
        commit, tag_object = 'a' * 40, 'b' * 40
        output = f'{commit}\trefs/tags/6.8^{{}}\n{tag_object}\trefs/tags/6.8\n'
        output += f'{commit}\trefs/tags/6.9-RC1\n{commit}\trefs/heads/trunk\n'
        self.assertEqual(w.parse_tags(output), {'6.8': commit})

    def test_patch_selection_does_not_advance_series_or_downgrade(self):
        tags = {v: 'a' * 40 for v in ['6.8.9', '6.9.8', '6.9.9', '6.9.10', '7.0', '7.1.2']}
        update = w.select_update('6.9.9', tags)
        self.assertEqual(update['target'], '6.9.10')
        self.assertEqual(update['next_series'], '7.1.2')
        self.assertIsNone(w.select_update('7.1.2', tags)['target'])

    def test_recorded_tag_change_fails_before_further_network_requests(self):
        lock = json.loads((ROOT / 'upstream.lock.json').read_text())
        with mock.patch.object(w, 'git') as git, self.assertRaises(w.Error):
            w.verify_recorded_tags(lock, {lock['wordpress']['tag']: 'a' * 40})
        git.assert_not_called()

    def test_lock_disallows_unapproved_sources_and_unpinned_dependencies(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / 'lock.json'
            original = json.loads((ROOT / 'upstream.lock.json').read_text())
            for key, value in [('repository', 'https://example.com/wordpress.git'), ('tag', 'trunk'), ('commit', 'main')]:
                lock = copy.deepcopy(original)
                lock['wordpress'][key] = value
                w.write_json(file, lock)
                with self.subTest(key=key), self.assertRaises(w.Error):
                    w.load_lock(file)


class DownloadTest(unittest.TestCase):
    def request(self, url, token='fixture-not-a-secret', maximum=64):
        response = io.BytesIO(b'fixture payload')
        response.url = url
        with mock.patch.dict(os.environ, {'WPLITE_GITHUB_API_TOKEN': token}), \
                mock.patch.object(w.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value = response
            result = w.download(url, maximum=maximum)
        self.assertEqual(result, b'fixture payload')
        self.assertIsInstance(opener.call_args.args[0], w.SafeDownloadRedirect)
        self.assertEqual(opener.return_value.open.call_args.kwargs, {'timeout': 60})
        return opener.return_value.open.call_args.args[0]

    def test_authentication_is_optional_and_limited_to_driver_release_metadata(self):
        for endpoint in ('latest', 'tags/v3.1.0'):
            with self.subTest(endpoint=endpoint):
                self.assertEqual(self.request(w.SQLITE_RELEASE_API + endpoint).get_header('Authorization'),
                                 'Bearer fixture-not-a-secret')
        self.assertIsNone(self.request(w.SQLITE_RELEASE_API + 'latest', token='').get_header('Authorization'))
        for url in ('https://api.github.com.evil.invalid/repos/WordPress/sqlite-database-integration/releases/latest',
                    'https://api.github.com/repos/other/repo/releases/latest',
                    w.SQLITE_RELEASE_API + '../issues', w.SQLITE_RELEASE_API + 'latest?redirect=1',
                    'https://github.com/WordPress/sqlite-database-integration/releases/download/v3.1.0/plugin-sqlite-database-integration.zip',
                    'https://api.wordpress.org/core/checksums/1.0/', 'https://www.sqlite.org/source.zip'):
            with self.subTest(url=url):
                self.assertIsNone(self.request(url).get_header('Authorization'))

    def test_redirects_strip_authorization_even_on_same_host_and_reject_tls_downgrade(self):
        request = w.urllib.request.Request(w.SQLITE_RELEASE_API + 'latest', headers={'Authorization': 'Bearer fixture'})
        handler = w.SafeDownloadRedirect()
        for url in (w.SQLITE_RELEASE_API + 'tags/v3.1.0', 'https://release-assets.githubusercontent.com/file.zip'):
            with self.subTest(url=url):
                redirected = handler.redirect_request(request, None, 302, 'Found', {}, url)
                self.assertIsNone(redirected.get_header('Authorization'))
        with self.assertRaisesRegex(w.Error, 'Insecure download redirect'):
            handler.redirect_request(request, None, 302, 'Found', {}, 'http://example.invalid/file')

    def test_download_size_and_https_requirements_remain_enforced(self):
        with self.assertRaisesRegex(w.Error, 'size limit'):
            self.request('https://example.invalid/file', maximum=3)
        with mock.patch.object(w.urllib.request, 'build_opener') as opener, self.assertRaises(w.Error):
            w.download('http://example.invalid/file')
        opener.assert_not_called()


class ArchiveTest(unittest.TestCase):
    def test_git_source_ignores_export_attributes_and_rejects_links(self):
        with tempfile.TemporaryDirectory() as temporary:
            repo = Path(temporary)
            w.git(repo, 'init', '-b', 'main')
            (repo / '.gitattributes').write_text('keep.txt export-ignore\nsubst.txt export-subst\n')
            (repo / 'keep.txt').write_text('Exact retained bytes\n')
            (repo / 'subst.txt').write_text('$Format:%H$\n')
            w.git(repo, 'add', '.')
            w.git(repo, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Snapshot')
            commit = w.git(repo, 'rev-parse', 'HEAD')
            files = w.source_files(repo, commit)
            self.assertEqual(files['keep.txt'], b'Exact retained bytes\n')
            self.assertEqual(files['subst.txt'], b'$Format:%H$\n')
            (repo / 'link.txt').symlink_to('keep.txt')
            w.git(repo, 'add', 'link.txt')
            w.git(repo, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Unsupported link')
            with self.assertRaisesRegex(w.Error, 'Unsupported source member'):
                w.source_files(repo, w.git(repo, 'rev-parse', 'HEAD'))

    def test_wrong_digest_is_rejected(self):
        with self.assertRaises(w.Error):
            w.driver_files(fixture_driver(), '0' * 64)

    def test_traversal_and_symlink_are_rejected(self):
        for name, mode in [('sqlite-database-integration/../../outside', 0),
                           ('sqlite-database-integration/link', 0o120777 << 16)]:
            output = io.BytesIO()
            with zipfile.ZipFile(output, 'w') as archive:
                entry = zipfile.ZipInfo(name)
                entry.external_attr = mode
                archive.writestr(entry, 'outside')
            payload = output.getvalue()
            with self.subTest(name=name), self.assertRaises(w.Error):
                w.driver_files(payload, hashlib.sha256(payload).hexdigest())


class DriverReleaseTest(unittest.TestCase):
    def release(self):
        return {'tag_name': 'v3.1.1', 'draft': False, 'prerelease': False,
                'published_at': '2026-10-15T00:00:00Z', 'assets': [{
                    'name': 'plugin-sqlite-database-integration.zip', 'state': 'uploaded',
                    'digest': 'sha256:' + 'b' * 64,
                    'browser_download_url': 'https://github.com/WordPress/sqlite-database-integration/releases/download/v3.1.1/plugin-sqlite-database-integration.zip'}]}

    def test_requires_published_release_and_matches_tag_commit_and_asset(self):
        release = self.release()
        refs = 'c' * 40 + '\trefs/tags/v3.1.1\n' + 'd' * 40 + '\trefs/tags/v3.1.1^{}\n'
        with mock.patch.object(w, 'download', return_value=json.dumps(release).encode()), mock.patch.object(w, 'git', return_value=refs):
            record = w.sqlite_release('v3.1.1')
        self.assertEqual(record['commit'], 'd' * 40)
        self.assertEqual(record['sha256'], 'b' * 64)

    def test_rejects_drafts_prereleases_missing_digest_and_wrong_asset(self):
        changes = [{'draft': True}, {'prerelease': True}, {'published_at': None}, {'tag_name': 'v3.2.0-rc.1'},
                   {'assets': []}]
        bad_asset = self.release()
        bad_asset['assets'][0]['digest'] = None
        changes.append({'assets': bad_asset['assets']})
        for change in changes:
            release = self.release()
            release.update(change)
            with self.subTest(change=change), mock.patch.object(w, 'download', return_value=json.dumps(release).encode()), self.assertRaises(w.Error):
                w.sqlite_release()

    def test_downgrade_and_same_tag_replacement_are_rejected(self):
        current = {'tag': 'v3.1.0', 'commit': 'a' * 40}
        with self.assertRaisesRegex(w.Error, 'metadata changed'):
            w.choose_driver(current, {'tag': 'v3.1.0', 'commit': 'b' * 40})
        older = {'tag': 'v3.0.9'}
        self.assertEqual(w.choose_driver(current, older), current)
        with self.assertRaisesRegex(w.Error, 'downgrades'):
            w.choose_driver(current, older, explicit=True)


class IntegrationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.directory = Path(self.temporary.name)
        self.upstream = self.directory / 'upstream'
        self.root = self.directory / 'tooling'
        for repo in (self.upstream, self.root):
            repo.mkdir()
            w.git(repo, 'init', '-b', 'main')
            w.git(repo, 'config', 'user.name', 'Test')
            w.git(repo, 'config', 'user.email', 'test@example.invalid')
        self.tag('7.1.3')
        self.first_sha = w.git(self.upstream, 'rev-parse', '7.1.3')
        self.tag('7.1.4')
        self.second_sha = w.git(self.upstream, 'rev-parse', '7.1.4')
        w.git(self.root, 'remote', 'add', 'upstream', str(self.upstream))
        runtime = self.root / 'runtime/root'
        for name in ['wp-config.php', 'wplite/bootstrap.php', 'wp-content/db.php', 'wp-content/mu-plugins/wplite.php', 'WPLITE.md']:
            target = runtime / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('<?php // local overlay\n' if name.endswith('.php') else 'WPLite\n')
        self.driver = fixture_driver()
        self.lock_path = self.root / 'upstream.lock.json'
        lock = json.loads((ROOT / 'upstream.lock.json').read_text())
        lock['wordpress'].update(tag='7.1.3', commit=self.first_sha)
        lock['sqlite']['sha256'] = hashlib.sha256(self.driver).hexdigest()
        lock['integration'] = {'commit': None, 'branch': None}
        self.driver_record = copy.deepcopy(lock['sqlite'])
        w.write_json(self.lock_path, lock)
        w.git(self.root, 'add', '.')
        w.git(self.root, 'commit', '-m', 'Tooling baseline')
        self.main = w.git(self.root, 'rev-parse', 'HEAD')
        self.tags = {'7.1.3': self.first_sha, '7.1.4': self.second_sha}
        self.patches = [
            mock.patch.object(w, 'remote_tags', return_value=self.tags),
            mock.patch.object(w, 'verify_recorded_tags'),
            mock.patch.object(w, 'ensure_upstream'),
            mock.patch.object(w, 'verify_official_source'),
            mock.patch.object(w, 'download', return_value=self.driver),
            mock.patch.object(w, 'sqlite_release', return_value=self.driver_record),
        ]
        for patch in self.patches:
            patch.start()

    def tearDown(self):
        for patch in reversed(self.patches):
            patch.stop()
        self.temporary.cleanup()

    def tag(self, version, conflict=False):
        # Mirror SVN-like release snapshots: tags need not be ancestors of each other.
        if (self.upstream / 'wp-includes/version.php').exists():
            w.git(self.upstream, 'switch', '--orphan', 'snapshot-' + version)
        file = self.upstream / 'wp-includes/version.php'
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_text("<?php\n$wp_version = '" + version + "';\n")
        (self.upstream / 'license.txt').write_text('Official fixture\n')
        if conflict:
            (self.upstream / 'WPLITE.md').write_text('Upstream collision\n')
        w.git(self.upstream, 'add', '.')
        w.git(self.upstream, 'commit', '-m', 'Release ' + version)
        w.git(self.upstream, 'tag', version)

    def test_bootstrap_rebase_and_reproducible_build_keep_main_and_dirty_files(self):
        (self.root / 'user-note.txt').write_text('preserve me')
        first = w.sync(self.root, self.lock_path, '7.1.3', False)
        second = w.sync(self.root, self.lock_path, '7.1.4', False)
        self.assertEqual(second['old_base'], self.first_sha)
        self.assertEqual(second['new_base'], self.second_sha)
        self.assertEqual(w.git(self.root, 'rev-parse', 'HEAD'), self.main)
        self.assertEqual((self.root / 'user-note.txt').read_text(), 'preserve me')
        lock = w.load_lock(self.lock_path)
        files = w.verify_integration(self.root, lock)
        self.assertIn(b"7.1.4", files['wp-includes/version.php'])
        self.assertIn('wp-content/db.php', files)
        self.assertNotEqual(first['commit'], second['commit'])
        one = w.build(self.root, self.lock_path, self.directory / 'one')
        two = w.build(self.root, self.lock_path, self.directory / 'two')
        self.assertEqual(one['sha256'], two['sha256'])
        unchanged = self.lock_path.read_bytes()
        self.assertEqual(w.sync(self.root, self.lock_path, None, False)['status'], 'up-to-date')
        self.assertEqual(self.lock_path.read_bytes(), unchanged)

    def test_unborn_tooling_main_can_bootstrap_without_claiming_source_provenance(self):
        root = self.directory / 'unborn'
        root.mkdir()
        w.git(root, 'init', '-b', 'main')
        w.git(root, 'remote', 'add', 'upstream', str(self.upstream))
        shutil.copytree(self.root / 'runtime', root / 'runtime')
        lock = root / 'upstream.lock.json'
        lock.write_bytes(self.lock_path.read_bytes())
        result = w.sync(root, lock, '7.1.3', False)
        self.assertIsNone(result['source_commit'])
        self.assertEqual(w.git(root, 'symbolic-ref', '--short', 'HEAD'), 'main')
        with self.assertRaises(w.Error):
            w.git(root, 'rev-parse', '--verify', 'HEAD')
        self.assertTrue(w.verify_integration(root, w.load_lock(lock)))

    def test_site_credentials_never_enter_an_overlay(self):
        unchanged = self.lock_path.read_bytes()
        for relative in sorted(w.SITE_ONLY_FILES):
            path = self.root / 'runtime/root' / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('private site material')
            with self.assertRaisesRegex(w.Error, 'must never enter an overlay'):
                w.sync(self.root, self.lock_path, '7.1.3', False)
            path.unlink()
            self.assertEqual(self.lock_path.read_bytes(), unchanged)

    def test_committed_site_credentials_cannot_be_built(self):
        result = w.sync(self.root, self.lock_path, '7.1.3', False)
        candidate = Path(result['candidate'])
        (candidate / 'wplite/setup-key.php').write_text('private setup material')
        w.git(candidate, 'add', '--', 'wplite/setup-key.php')
        w.git(candidate, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid',
              'commit', '-m', 'Accidental site credentials')
        lock = w.load_lock(self.lock_path)
        lock['integration']['commit'] = w.git(candidate, 'rev-parse', 'HEAD')
        with self.assertRaisesRegex(w.Error, 'must never enter a distribution'):
            w.verify_integration(candidate, lock)

    def test_conflict_preserves_lock_and_stops_with_candidate_path(self):
        w.sync(self.root, self.lock_path, '7.1.3', False)
        self.tag('7.1.5', conflict=True)
        self.tags['7.1.5'] = w.git(self.upstream, 'rev-parse', '7.1.5')
        original = self.lock_path.read_bytes()
        with self.assertRaisesRegex(w.Error, 'Rebase stopped; resolve conflicts'):
            w.sync(self.root, self.lock_path, '7.1.5', False)
        self.assertEqual(self.lock_path.read_bytes(), original)
        self.assertEqual(w.git(self.root, 'rev-parse', 'HEAD'), self.main)

    def test_new_series_requires_explicit_approval(self):
        self.tags['7.2'] = 'a' * 40
        with self.assertRaisesRegex(w.Error, 'requires --approve-series'):
            w.sync(self.root, self.lock_path, '7.2', False)

    def test_resolved_conflict_can_be_resumed_without_overwriting_upstream(self):
        w.sync(self.root, self.lock_path, '7.1.3', False)
        self.tag('7.1.5', conflict=True)
        self.tags['7.1.5'] = w.git(self.upstream, 'rev-parse', '7.1.5')
        before = set((self.root / '.cache/candidates').iterdir())
        with self.assertRaises(w.Error):
            w.sync(self.root, self.lock_path, '7.1.5', False)
        repo = (set((self.root / '.cache/candidates').iterdir()) - before).pop()
        with self.assertRaisesRegex(w.Error, 'Finish the candidate'):
            w.resume(self.root, self.lock_path, repo)
        # Upstream now owns WPLITE.md: relocate our overlay doc, keep the new
        # official file, and complete the pending rebase explicitly.
        source = self.root / 'runtime/root/WPLITE.md'
        source.rename(self.root / 'runtime/root/wplite/ABOUT.md')
        (repo / 'WPLITE.md').write_bytes(w.git_bytes(repo, 'show', self.tags['7.1.5'] + ':WPLITE.md'))
        w.git(repo, 'add', 'WPLITE.md')
        w.git(repo, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'core.editor=true', 'rebase', '--continue')
        result = w.resume(self.root, self.lock_path, repo)
        self.assertEqual(result['target'], '7.1.5')
        files = w.verify_integration(self.root, w.load_lock(self.lock_path))
        self.assertEqual(files['WPLITE.md'], b'Upstream collision\n')
        self.assertIn('wplite/ABOUT.md', files)

    def test_resume_refuses_to_replace_a_subsequently_changed_lock(self):
        candidate = w.sync(self.root, self.lock_path, '7.1.3', False)
        with self.assertRaisesRegex(w.Error, 'Dependency lock changed'):
            w.resume(self.root, self.lock_path, Path(candidate['candidate']))

    def test_refresh_overlay_is_explicit_and_preserves_official_base(self):
        initial = w.sync(self.root, self.lock_path, '7.1.3', False)
        lock = w.load_lock(self.lock_path)
        self.assertEqual(w.verify_overlay(self.root, lock), initial['overlay_sha256'])
        (self.root / 'runtime/root/wplite/bootstrap.php').write_text('<?php // revised overlay\n')
        self.assertEqual(w.sync(self.root, self.lock_path, '7.1.3', False)['status'], 'up-to-date')
        with self.assertRaisesRegex(w.Error, 'stale or different canonical overlay'):
            w.verify_overlay(self.root, lock)
        # A local build still reads the locked SHA, never dirty working files.
        self.assertTrue(w.build(self.root, self.lock_path, self.directory / 'locked')['archive'])
        refreshed = w.sync(self.root, self.lock_path, '7.1.3', False, refresh=True)
        self.assertNotEqual(initial['commit'], refreshed['commit'])
        self.assertEqual(refreshed['new_base'], self.first_sha)
        self.assertNotEqual(initial['overlay_sha256'], refreshed['overlay_sha256'])
        self.assertEqual(w.verify_overlay(self.root, w.load_lock(self.lock_path)), refreshed['overlay_sha256'])

    def test_modified_core_is_not_packaged(self):
        candidate = w.sync(self.root, self.lock_path, '7.1.3', False)
        repo = Path(candidate['candidate'])
        (repo / 'license.txt').write_text('tampered')
        w.git(repo, 'add', 'license.txt')
        w.git(repo, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Invalid core edit')
        changed = w.git(repo, 'rev-parse', 'HEAD')
        w.git(self.root, 'fetch', str(repo), changed)
        lock = w.load_lock(self.lock_path)
        lock['integration']['commit'] = changed
        with self.assertRaisesRegex(w.Error, 'Official core file modified'):
            w.verify_integration(self.root, lock)

    def test_driver_only_update_keeps_core_and_removes_old_vendor_files(self):
        old_payload = fixture_driver({'old-driver.php': '<?php // obsolete\n'})
        old = w.load_lock(self.lock_path)
        old['sqlite']['sha256'] = hashlib.sha256(old_payload).hexdigest()
        w.write_json(self.lock_path, old)
        with mock.patch.object(w, 'download', return_value=old_payload), mock.patch.object(w, 'sqlite_release', return_value=old['sqlite']):
            first = w.sync(self.root, self.lock_path, '7.1.3', False)
        new_payload = fixture_driver({'new-driver.php': '<?php // new\n'})
        new = copy.deepcopy(old['sqlite'])
        new.update(tag='v3.1.1', commit='b' * 40, sha256=hashlib.sha256(new_payload).hexdigest())
        new['archive_url'] = new['archive_url'].replace('/v3.1.0/', '/v3.1.1/')
        with mock.patch.object(w, 'download', return_value=new_payload), mock.patch.object(w, 'sqlite_release', return_value=new):
            result = w.sync(self.root, self.lock_path, '7.1.3', False)
        lock = w.load_lock(self.lock_path)
        files = w.verify_integration(self.root, lock)
        self.assertEqual(result['new_base'], self.first_sha)
        self.assertNotEqual(result['commit'], first['commit'])
        self.assertEqual(lock['sqlite']['tag'], 'v3.1.1')
        self.assertNotIn('wplite/vendor/sqlite/old-driver.php', files)
        self.assertIn('wplite/vendor/sqlite/new-driver.php', files)


if __name__ == '__main__':
    unittest.main()
