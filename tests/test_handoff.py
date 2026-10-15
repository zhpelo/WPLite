"""Real isolated Git repositories; no network services or GitHub mutations."""

import copy
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import sys
import unittest
from unittest import mock

import test_tooling

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
import wplite as w
import sync_handoff as h


class HandoffTest(unittest.TestCase):
    def setUp(self):
        self.fixture = test_tooling.IntegrationTest()
        self.fixture.setUp()
        self.addCleanup(self.fixture.tearDown)
        self.root = self.fixture.root
        w.sync(self.root, self.fixture.lock_path, '7.1.3', False)
        w.git(self.root, 'add', 'upstream.lock.json')
        w.git(self.root, 'commit', '-m', 'Pinned baseline')
        self.source = w.git(self.root, 'rev-parse', 'HEAD')
        patch = mock.patch.dict(os.environ, {'GITHUB_ACTIONS': 'true', 'GITHUB_EVENT_NAME': 'push',
            'GITHUB_REF': 'refs/heads/main', 'GITHUB_REPOSITORY': 'example/WPLite',
            'GITHUB_SHA': self.source, 'GITHUB_RUN_ID': '123', 'GITHUB_RUN_ATTEMPT': '1',
            'APPROVE_SERIES': 'false', 'WPLITE_HANDOFF_SHA256': ''})
        patch.start()
        self.addCleanup(patch.stop)
        self.directory = self.root / '.cache/sync-handoff'

    def seal(self, candidate=True):
        w.sync(self.root, self.fixture.lock_path, '7.1.4' if candidate else '7.1.3', False)
        result = h.export_handoff(self.root, self.directory)
        os.environ['WPLITE_HANDOFF_SHA256'] = result['handoff_sha256']
        return result

    def fresh(self, name):
        root = self.fixture.directory / name
        w.git(self.root, 'clone', '--no-local', '--single-branch', '--branch', 'main', str(self.root), str(root))
        w.git(root, 'remote', 'add', 'upstream', str(self.fixture.upstream))
        shutil.copytree(self.directory, root / '.cache/sync-handoff')
        return root

    def edit_manifest(self, edit):
        file = self.directory / 'handoff.json'
        value = json.loads(file.read_bytes())
        edit(value)
        w.write_json(file, value)
        os.environ['WPLITE_HANDOFF_SHA256'] = hashlib.sha256(file.read_bytes()).hexdigest()

    def test_candidate_round_trip_to_separate_validation_and_publisher_repositories(self):
        outputs = self.seal()
        self.assertEqual(outputs['ready'], 'true')
        expected = w.load_lock(self.fixture.lock_path)
        for name in ('validation', 'publisher'):
            root = self.fresh(name)
            with self.assertRaises(w.Error):
                w.git(root, 'cat-file', '-e', expected['integration']['commit'])
            result = h.import_handoff(root, root / '.cache/sync-handoff')
            self.assertTrue(result['candidate'])
            self.assertEqual(w.load_lock(root / 'upstream.lock.json'), expected)
            self.assertEqual(w.git(root, 'rev-parse', 'HEAD'), self.source)
            h.verify_receipt(root)
            result = json.loads((root / '.cache/sync-result.json').read_bytes())
            w.git(root, 'range-diff', result['old_base'] + '..' + result['previous_integration'],
                  result['new_base'] + '..' + result['commit'])
        # A validation runner can modify its own files, but they never replace
        # discovery's handoff or the publisher's independently imported objects.
        (self.fixture.directory / 'validation/upstream.lock.json').write_text('{}')
        h.verify_receipt(self.fixture.directory / 'publisher')

    def test_no_update_round_trip_has_no_bundle_and_preserves_source(self):
        self.assertEqual(self.seal(False)['ready'], 'false')
        self.assertEqual({p.name for p in self.directory.iterdir()}, {'handoff.json'})
        root = self.fresh('no-update')
        self.assertFalse(h.import_handoff(root, root / '.cache/sync-handoff')['candidate'])
        self.assertEqual(w.git(root, 'diff', '--name-only'), '')
        h.verify_receipt(root)

    def test_manifest_and_bundle_digest_changes_fail_before_git_import(self):
        self.seal()
        manifest = self.directory / 'handoff.json'
        original = manifest.read_bytes()
        manifest.write_bytes(original + b' ')
        with self.assertRaisesRegex(w.Error, 'manifest digest'):
            h.load_handoff(self.root, self.directory)
        manifest.write_bytes(original)
        bundle = self.directory / 'candidate.bundle'
        bundle.write_bytes(bundle.read_bytes() + b'tampered')
        with self.assertRaisesRegex(w.Error, 'bundle digest'):
            h.load_handoff(self.root, self.directory)

    def test_different_run_attempt_repository_or_source_is_rejected(self):
        self.seal()
        for key, value in (('GITHUB_RUN_ID', '124'), ('GITHUB_RUN_ATTEMPT', '2'),
                           ('GITHUB_REPOSITORY', 'other/WPLite'), ('GITHUB_SHA', 'f' * 40)):
            with self.subTest(key=key), mock.patch.dict(os.environ, {key: value}), self.assertRaises(w.Error):
                h.load_handoff(self.root, self.directory)
        with mock.patch.dict(os.environ, {'GITHUB_EVENT_NAME': 'pull_request'}), self.assertRaises(w.Error):
            h.load_handoff(self.root, self.directory)

    def test_extra_files_and_linked_bundle_are_rejected(self):
        self.seal()
        injected = self.directory / 'run-me.py'
        injected.write_text('raise RuntimeError("must never execute")')
        with self.assertRaisesRegex(w.Error, 'Unexpected handoff files'):
            h.load_handoff(self.root, self.directory)
        injected.unlink()
        bundle = self.directory / 'candidate.bundle'
        saved = self.root / '.cache/saved.bundle'
        bundle.rename(saved)
        bundle.symlink_to(saved)
        with self.assertRaisesRegex(w.Error, 'linked'):
            h.load_handoff(self.root, self.directory)

    def test_publisher_receipt_rejects_post_import_lock_and_result_changes(self):
        self.seal()
        root = self.fresh('receipt')
        h.import_handoff(root, root / '.cache/sync-handoff')
        for path in (root / 'upstream.lock.json', root / '.cache/sync-result.json'):
            original = path.read_bytes()
            changed = json.loads(original)
            changed['unexpected'] = 'test runner tampering'
            w.write_json(path, changed)
            with self.subTest(path=path.name), self.assertRaisesRegex(w.Error, 'Publication inputs differ'):
                h.verify_receipt(root)
            path.write_bytes(original)

    def test_wrong_candidate_relation_and_validation_fingerprint_are_rejected(self):
        self.seal()
        original = json.loads((self.directory / 'handoff.json').read_bytes())
        changes = [lambda m: m['result'].update(previous_integration='e' * 40),
                   lambda m: m['identity'].update(validation_sha256='e' * 64),
                   lambda m: m['result'].update(branch='sync/wp-7.1.4-unsafe/branch'),
                   lambda m: m['lock'].update(wplite_version='different-version')]
        for change in changes:
            w.write_json(self.directory / 'handoff.json', copy.deepcopy(original))
            self.edit_manifest(change)
            with self.assertRaises(w.Error):
                h.load_handoff(self.root, self.directory)

    def test_no_update_cannot_smuggle_dependency_changes_or_bundle(self):
        self.seal(False)
        self.edit_manifest(lambda m: m['lock']['integration'].update(commit='e' * 40))
        with self.assertRaisesRegex(w.Error, 'exact source lock'):
            h.load_handoff(self.root, self.directory)

    def test_same_tag_metadata_replacement_is_rejected(self):
        self.seal()
        self.edit_manifest(lambda m: m['lock']['sqlite'].update(sha256='e' * 64))
        with self.assertRaisesRegex(w.Error, 'same dependency tag'):
            h.load_handoff(self.root, self.directory)

    def test_new_series_approval_comes_from_workflow_context_not_artifact(self):
        self.fixture.tag('7.2')
        self.fixture.tags['7.2'] = w.git(self.fixture.upstream, 'rev-parse', '7.2')
        w.sync(self.root, self.fixture.lock_path, '7.2', True)
        with mock.patch.dict(os.environ, {'APPROVE_SERIES': 'true'}):
            outputs = h.export_handoff(self.root, self.directory)
        os.environ['WPLITE_HANDOFF_SHA256'] = outputs['handoff_sha256']
        with self.assertRaisesRegex(w.Error, 'trusted workflow approval'):
            h.load_handoff(self.root, self.directory)
        with mock.patch.dict(os.environ, {'APPROVE_SERIES': 'true'}):
            h.load_handoff(self.root, self.directory)

    def test_import_refuses_dirty_trusted_source_before_network_or_lock_write(self):
        self.seal()
        root = self.fresh('dirty-source')
        file = root / 'runtime/root/WPLITE.md'
        file.write_text('Unexpected source edit\n')
        before = (root / 'upstream.lock.json').read_bytes()
        with mock.patch.object(w, 'fetch_tag') as fetch, self.assertRaisesRegex(w.Error, 'fresh trusted checkout'):
            h.import_handoff(root, root / '.cache/sync-handoff')
        fetch.assert_not_called()
        self.assertEqual((root / 'upstream.lock.json').read_bytes(), before)

    def test_bundle_with_extra_ref_is_rejected_even_with_a_matching_digest(self):
        self.seal()
        manifest = json.loads((self.directory / 'handoff.json').read_bytes())
        other = self.root / '.cache/extra.bundle'
        w.git(self.root, 'bundle', 'create', str(other), 'main', 'refs/heads/' + manifest['result']['branch'],
              '^' + manifest['lock']['wordpress']['commit'])
        (self.directory / 'candidate.bundle').write_bytes(other.read_bytes())
        self.edit_manifest(lambda m: m.update(bundle_sha256=hashlib.sha256(other.read_bytes()).hexdigest()))
        root = self.fresh('extra-ref')
        with self.assertRaisesRegex(w.Error, 'exactly its pinned candidate ref'):
            h.import_handoff(root, root / '.cache/sync-handoff')

    def test_driver_self_consistent_manifest_is_not_a_substitute_for_archive_parity(self):
        self.seal()
        lock = w.load_lock(self.fixture.lock_path)
        candidate = Path(json.loads((self.root / '.cache/sync-result.json').read_bytes())['candidate'])
        driver = candidate / 'wplite/vendor/sqlite/wp-includes/sqlite/db.php'
        driver.write_text('<?php // altered vendor bytes\n')
        file = candidate / 'wplite/distribution.json'
        manifest = json.loads(file.read_bytes())
        manifest['files']['wplite/vendor/sqlite/wp-includes/sqlite/db.php'] = hashlib.sha256(driver.read_bytes()).hexdigest()
        w.write_json(file, manifest)
        w.git(candidate, 'add', '.')
        w.git(candidate, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Self-consistent vendor tampering')
        lock['integration']['commit'] = w.git(candidate, 'rev-parse', 'HEAD')
        w.verify_integration(candidate, lock)
        w.git(self.root, 'fetch', '--no-tags', str(candidate), lock['integration']['commit'])
        with self.assertRaisesRegex(w.Error, 'driver bytes differ'):
            h.verify_contents(self.root, lock)


class WorkflowIsolationTest(unittest.TestCase):
    def test_jobs_keep_candidate_execution_out_of_repository_write_environment(self):
        text = (test_tooling.ROOT / '.github/workflows/upstream-sync.yml').read_text()
        section = text.split('\njobs:\n', 1)[1]
        parts = re.split(r'^  ([a-z_]+):\n', section, flags=re.M)
        jobs = dict(zip(parts[1::2], parts[2::2]))
        self.assertEqual(set(jobs), {'discover', 'validate', 'publish'})
        for name in ('discover', 'validate'):
            self.assertIn('contents: read', jobs[name])
            self.assertNotIn(': write', jobs[name])
            self.assertNotIn('GH_TOKEN:', jobs[name])
        publish = jobs['publish']
        self.assertIn('needs: [discover, validate]', publish)
        self.assertIn('contents: write', publish)
        self.assertIn('pull-requests: write', publish)
        for forbidden in ('tests/', 'dist/', '.cache/test-runtime', 'actions/cache@', 'needs.validate.outputs', 'if: always()'):
            self.assertNotIn(forbidden, publish)
        for name in ('validate', 'publish'):
            self.assertIn('artifact-ids: ${{ needs.discover.outputs.artifact_id }}', jobs[name])
            self.assertIn('WPLITE_HANDOFF_SHA256: ${{ needs.discover.outputs.handoff_sha256 }}', jobs[name])
            self.assertIn('python3 tools/sync_handoff.py import', jobs[name])
            self.assertNotIn('pattern:', jobs[name])
        for job in jobs.values():
            self.assertIn('persist-credentials: false', job)
            self.assertIn('ref: ${{ github.sha }}', job)
        self.assertNotIn('tests/', jobs['discover'])


if __name__ == '__main__':
    unittest.main()
