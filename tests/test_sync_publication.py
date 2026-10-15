"""Real Git pushes/ancestry with a simulated GitHub PR API, never remote writes."""

import contextlib
import copy
import io
import json
import os
import shutil
from pathlib import Path
import sys
import unittest
from unittest import mock

import test_tooling

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
import wplite as w
import publish_sync_pr as publisher
import sync_handoff as handoff


class PublicationFlowTest(unittest.TestCase):
    def setUp(self):
        self.fixture = test_tooling.IntegrationTest()
        self.fixture.setUp()
        self.addCleanup(self.fixture.tearDown)
        self.root, self.lock = self.fixture.root, self.fixture.lock_path
        self.remote = self.fixture.directory / 'origin.git'
        w.git(self.root, 'init', '--bare', str(self.remote))
        w.git(self.root, 'remote', 'add', 'origin', str(self.remote))
        initial = w.sync(self.root, self.lock, '7.1.3', False)
        w.git(self.root, 'add', 'upstream.lock.json')
        w.git(self.root, 'commit', '-m', 'Lock baseline')
        w.git(self.root, 'push', 'origin', 'main', initial['branch'])
        self.prs = {}
        self.calls = []
        self.before_get_pull = None
        patches = [mock.patch.object(publisher, 'ROOT', self.root),
                   mock.patch.object(publisher, 'git', side_effect=self.transport),
                   mock.patch.object(publisher, 'api', side_effect=self.api),
                   mock.patch.object(publisher, 'verify_receipt'),
                   mock.patch.dict(os.environ, {'GITHUB_ACTIONS': 'true', 'GITHUB_EVENT_NAME': 'schedule',
                       'GITHUB_REF': 'refs/heads/main', 'GITHUB_REPOSITORY': 'example/WPLite', 'GH_TOKEN': 'test-only-token'})]
        for patch in patches:
            patch.start()
            self.addCleanup(patch.stop)

    def transport(self, repo, *arguments, env=None):
        # Only transport discovery is adapted. All actual refs, pushes, archives,
        # parents and ancestry checks execute against this test's bare origin.
        if arguments in [('remote', 'get-url', 'origin'), ('remote', 'get-url', '--push', 'origin')]:
            return 'https://github.com/example/WPLite.git'
        return w.git(repo, *arguments, env=env)

    def api(self, repository, token, method, resource, payload=None):
        self.assertEqual(repository, 'example/WPLite')
        self.assertEqual(token, 'test-only-token')
        self.calls.append((method, resource, copy.deepcopy(payload)))
        if method == 'GET' and resource.startswith('/pulls?'):
            return copy.deepcopy([pr for pr in self.prs.values() if pr['state'] == 'open'])
        if method == 'GET' and resource.startswith('/pulls/'):
            number = int(resource.rsplit('/', 1)[-1])
            if self.before_get_pull:
                self.before_get_pull(self.prs[number])
            return copy.deepcopy(self.prs[number])
        if method == 'POST' and resource == '/pulls':
            number = len(self.prs) + 1
            self.prs[number] = {'number': number, 'state': 'open', 'draft': payload['draft'],
                'user': {'login': 'github-actions[bot]'},
                'body': payload['body'], 'base': {'ref': payload['base']},
                'html_url': f'https://github.com/example/WPLite/pull/{number}',
                'head': {'ref': payload['head'], 'repo': {'full_name': repository},
                         'sha': w.git(self.remote, 'rev-parse', 'refs/heads/' + payload['head'])}}
            return copy.deepcopy(self.prs[number])
        if method == 'PATCH' and resource.startswith('/pulls/'):
            number = int(resource.rsplit('/', 1)[-1])
            self.prs[number].update(payload)
            return copy.deepcopy(self.prs[number])
        self.fail('Unexpected API request: ' + method + ' ' + resource)

    def main(self):
        if w.git(self.root, 'symbolic-ref', '--short', 'HEAD') != 'main':
            w.git(self.root, 'switch', 'main')
        # Discard only the fixture-generated candidate lock, never user files.
        self.lock.write_bytes(w.git_bytes(self.root, 'show', 'HEAD:upstream.lock.json'))

    def candidate(self):
        self.main()
        return w.sync(self.root, self.lock, '7.1.4', False)

    def publish(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            publisher.publish()
        return output.getvalue()

    def change_source(self, relative, content, refresh=False):
        self.main()
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        if refresh:
            w.sync(self.root, self.lock, '7.1.3', False, refresh=True)
        w.git(self.root, 'add', '--', relative, 'upstream.lock.json')
        w.git(self.root, 'commit', '-m', 'New source inputs')
        w.git(self.root, 'push', 'origin', 'main')

    def test_publish_and_repeat_deduplicate_verified_git_content(self):
        first = self.candidate()
        source = w.git(self.root, 'rev-parse', 'HEAD')
        self.publish()
        self.assertTrue(self.prs[1]['draft'])
        self.assertIn(first['overlay_sha256'], self.prs[1]['body'])
        self.assertIn(source, self.prs[1]['body'])
        refs = w.git(self.remote, 'show-ref')
        self.candidate()
        self.assertIn('Existing verified candidate', self.publish())
        self.assertEqual(len(self.prs), 1)
        self.assertEqual(w.git(self.remote, 'show-ref'), refs)
        self.assertEqual(w.git(self.remote, 'rev-parse', 'refs/heads/main'), source)

    def test_publisher_consumes_discovery_handoff_in_a_fresh_repository(self):
        self.candidate()
        source = w.git(self.root, 'rev-parse', 'HEAD')
        with mock.patch.dict(os.environ, {'GITHUB_SHA': source, 'GITHUB_RUN_ID': '123',
                'GITHUB_RUN_ATTEMPT': '1', 'APPROVE_SERIES': 'false'}):
            directory = self.root / '.cache/sync-handoff'
            output = handoff.export_handoff(self.root, directory)
            fresh = self.fixture.directory / 'isolated-publisher'
            w.git(self.root, 'clone', '--no-local', '--single-branch', '--branch', 'main', str(self.remote), str(fresh))
            w.git(fresh, 'remote', 'add', 'upstream', str(self.fixture.upstream))
            shutil.copytree(directory, fresh / '.cache/sync-handoff')
            with mock.patch.dict(os.environ, {'WPLITE_HANDOFF_SHA256': output['handoff_sha256']}):
                handoff.import_handoff(fresh, fresh / '.cache/sync-handoff')
                with mock.patch.object(publisher, 'ROOT', fresh), \
                        mock.patch.object(publisher, 'verify_receipt', wraps=handoff.verify_receipt):
                    self.assertIn('Created draft sync PR', self.publish())
        self.assertTrue(self.prs[1]['draft'])
        self.assertEqual(w.git(self.remote, 'rev-parse', 'refs/heads/main'), source)

    def test_runtime_change_replaces_same_versions_and_ignores_forged_body(self):
        self.candidate()
        self.publish()
        old_ref, old_sha = self.prs[1]['head']['ref'], self.prs[1]['head']['sha']
        self.change_source('runtime/root/wplite/bootstrap.php', '<?php // new runtime\n', refresh=True)
        new = self.candidate()
        self.prs[1]['body'] = publisher.MARKER + '\nOverlay SHA-256: `' + new['overlay_sha256'] + '`'
        self.publish()
        self.assertEqual(len(self.prs), 2)
        self.assertEqual(self.prs[1]['state'], 'closed')
        self.assertEqual(self.prs[2]['state'], 'open')
        self.assertEqual(w.git(self.remote, 'rev-parse', 'refs/heads/' + old_ref), old_sha)

    def test_changed_validation_retests_and_replaces_even_identical_runtime(self):
        first = self.candidate()
        self.publish()
        self.change_source('tests/new_regression.py', '# new required validation\n')
        second = self.candidate()
        self.assertEqual(first['overlay_sha256'], second['overlay_sha256'])
        self.publish()
        self.assertEqual(len(self.prs), 2)
        self.assertEqual(self.prs[1]['state'], 'closed')

    def test_documentation_only_changes_do_not_create_duplicate_candidates(self):
        self.candidate()
        self.publish()
        self.change_source('docs/note.md', 'Documentation only\n')
        self.candidate()
        self.assertIn('Existing verified candidate', self.publish())
        self.assertEqual(len(self.prs), 1)

    def test_export_ignored_tests_still_participate_in_validation_identity(self):
        self.change_source('.gitattributes', 'tests/** export-ignore\n')
        self.candidate()
        self.publish()
        self.change_source('tests/required.py', '# required even when export-ignored\n')
        self.candidate()
        self.publish()
        self.assertEqual(len(self.prs), 2)
        self.assertEqual(self.prs[1]['state'], 'closed')

    def test_newer_core_does_not_close_candidate_with_a_newer_driver(self):
        original = copy.deepcopy(self.fixture.driver_record)
        self.fixture.driver_record.update(tag='v3.1.1', commit='e' * 40,
            archive_url=original['archive_url'].replace('/v3.1.0/', '/v3.1.1/'))
        self.candidate()
        self.publish()
        self.fixture.driver_record.clear()
        self.fixture.driver_record.update(original)
        self.main()
        self.fixture.tag('7.1.5')
        self.fixture.tags['7.1.5'] = w.git(self.fixture.upstream, 'rev-parse', '7.1.5')
        w.sync(self.root, self.lock, '7.1.5', False)
        self.publish()
        self.assertEqual(len(self.prs), 2)
        self.assertTrue(all(pr['state'] == 'open' for pr in self.prs.values()))

    def test_human_owned_pr_is_not_deduplicated_or_closed(self):
        self.candidate()
        self.publish()
        self.prs[1]['user']['login'] = 'maintainer'
        self.candidate()
        self.publish()
        self.assertEqual(len(self.prs), 2)
        self.assertEqual(self.prs[1]['state'], 'open')

    def test_remote_main_advance_aborts_before_publication(self):
        self.candidate()
        directory = self.fixture.directory / 'concurrent'
        w.git(self.root, 'clone', '--branch', 'main', str(self.remote), str(directory))
        (directory / 'new.txt').write_text('Concurrent main change\n')
        w.git(directory, 'add', 'new.txt')
        w.git(directory, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Advance main')
        w.git(directory, 'push', 'origin', 'main')
        before = w.git(self.remote, 'show-ref')
        with self.assertRaisesRegex(w.Error, 'main advanced'):
            self.publish()
        self.assertEqual(self.prs, {})
        self.assertEqual(w.git(self.remote, 'show-ref'), before)

    def test_main_advance_after_push_preserves_branches_without_creating_pr(self):
        result = self.candidate()
        original = self.transport
        def race(repo, *arguments, env=None):
            output = original(repo, *arguments, env=env)
            if arguments[:2] == ('push', 'origin') and arguments[-1].startswith('HEAD:refs/heads/bot/'):
                directory = self.fixture.directory / 'late-main'
                w.git(self.root, 'clone', '--branch', 'main', str(self.remote), str(directory))
                (directory / 'late.txt').write_text('Later main\n')
                w.git(directory, 'add', 'late.txt')
                w.git(directory, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Later main')
                w.git(directory, 'push', 'origin', 'main')
            return output
        with mock.patch.object(publisher, 'git', side_effect=race), self.assertRaisesRegex(w.Error, 'main advanced'):
            self.publish()
        self.assertEqual(self.prs, {})
        self.assertEqual(w.git(self.remote, 'rev-parse', 'refs/heads/' + result['branch']), result['commit'])

    def test_existing_matching_pr_changed_before_deduplication_is_rejected(self):
        self.candidate()
        self.publish()
        self.candidate()
        def change(pr):
            pr['head']['sha'] = 'f' * 40
        self.before_get_pull = change
        with self.assertRaisesRegex(w.Error, 'candidate PR changed'):
            self.publish()
        self.assertEqual(len(self.prs), 1)
        self.assertEqual(self.prs[1]['state'], 'open')

    def test_head_or_base_changed_after_inspection_is_never_closed(self):
        for changed in ('head', 'base'):
            with self.subTest(changed=changed):
                # Separate proven old PRs at the current source each time.
                self.before_get_pull = None
                self.candidate()
                self.publish()
                current = max(number for number, pr in self.prs.items() if pr['state'] == 'open')
                self.change_source('tests/new_regression.py', '# ' + changed + '\n')
                self.candidate()
                def edit(pr):
                    if pr['number'] == current:
                        if changed == 'head':
                            pr['head']['sha'] = 'f' * 40
                        else:
                            pr['base']['ref'] = 'other'
                self.before_get_pull = edit
                self.publish()
                self.assertEqual(self.prs[current]['state'], 'open')
                # Remove the synthetic race from the next iteration's inventory.
                self.prs[current]['body'] = 'Now maintained manually'

    def test_unverifiable_manually_edited_pr_is_preserved(self):
        self.candidate()
        self.publish()
        old = self.prs[1]['head']['ref']
        (self.root / 'manual-note.txt').write_text('Maintainer work\n')
        w.git(self.root, 'add', 'manual-note.txt')
        w.git(self.root, 'commit', '-m', 'Manual review edits')
        w.git(self.root, 'push', 'origin', 'HEAD:refs/heads/' + old)
        self.prs[1]['head']['sha'] = w.git(self.root, 'rev-parse', 'HEAD')
        self.candidate()
        self.assertIn('Unverifiable candidate PR preserved', self.publish())
        self.assertEqual(self.prs[1]['state'], 'open')
        self.assertEqual(len(self.prs), 2)

    def test_single_metadata_commit_rewritten_by_a_human_is_preserved(self):
        self.candidate()
        branch = 'bot/sync-wp-7.1.4-sqlite-3.1.0-manual'
        w.git(self.root, 'switch', '-c', branch)
        w.git(self.root, 'add', 'upstream.lock.json')
        w.git(self.root, 'commit', '-m', 'Maintainer-owned metadata')
        w.git(self.root, 'push', 'origin', 'HEAD:refs/heads/' + branch)
        self.api('example/WPLite', 'test-only-token', 'POST', '/pulls',
                 {'head': branch, 'base': 'main', 'body': publisher.MARKER, 'draft': True})
        self.candidate()
        self.assertIn('Unverifiable candidate PR preserved', self.publish())
        self.assertEqual(self.prs[1]['state'], 'open')
        self.assertEqual(len(self.prs), 2)

    def test_uncommitted_source_cannot_be_published(self):
        self.candidate()
        (self.root / 'runtime/root/WPLITE.md').write_text('Uncommitted edit\n')
        with self.assertRaisesRegex(w.Error, 'stale or different canonical overlay'):
            self.publish()
        self.assertEqual(self.prs, {})

    def test_untrusted_event_or_ref_has_no_publication_side_effects(self):
        self.candidate()
        for environment in [{'GITHUB_EVENT_NAME': 'pull_request'}, {'GITHUB_REF': 'refs/heads/other'}]:
            with mock.patch.dict(os.environ, environment), self.assertRaisesRegex(w.Error, 'trusted main'):
                self.publish()
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
