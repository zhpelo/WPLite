#!/usr/bin/env python3
"""Data-only handoff from trusted discovery to isolated tests and publication.

The expected manifest digest and run identity come from discovery job outputs,
never from the test runner or an artifact's self-declared metadata.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import sys

import wplite as w


BRANCH = re.compile(r'sync/wp-[0-9]+\.[0-9]+(?:\.[0-9]+)?-[A-Za-z0-9_]+')
RESULT_FIELDS = ('status', 'target', 'branch', 'old_base', 'new_base', 'previous_integration',
                 'previous_sqlite', 'commit', 'source_commit', 'overlay_sha256', 'next_series')
MAX_BUNDLE = 128 * 1024 * 1024


def context(root):
    repository = os.environ.get('GITHUB_REPOSITORY', '')
    source = os.environ.get('GITHUB_SHA', '')
    run, attempt = os.environ.get('GITHUB_RUN_ID', ''), os.environ.get('GITHUB_RUN_ATTEMPT', '')
    if (os.environ.get('GITHUB_ACTIONS') != 'true'
            or os.environ.get('GITHUB_REF') != 'refs/heads/main'
            or os.environ.get('GITHUB_EVENT_NAME') not in ('push', 'schedule', 'workflow_dispatch')
            or not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository)
            or not w.SHA.fullmatch(source) or not re.fullmatch(r'[1-9][0-9]*', run)
            or not re.fullmatch(r'[1-9][0-9]*', attempt)):
        raise w.Error('Handoff requires an identified trusted main workflow run')
    if (w.git(root, 'rev-parse', 'HEAD') != source
            or w.git(root, 'symbolic-ref', '--short', 'HEAD') != 'main'):
        raise w.Error('Handoff checkout does not match the workflow source main')
    return {'repository': repository, 'source_commit': source, 'run_id': run, 'run_attempt': attempt}


def read_regular(path, maximum):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > maximum:
        raise w.Error('Missing, linked or oversized handoff file: ' + path.name)
    return path.read_bytes()


def transition(root, manifest, approve_series=False):
    binding = context(root)
    if manifest.get('format') != 1 or any(manifest.get(k) != v for k, v in binding.items()):
        raise w.Error('Handoff belongs to a different source, repository, run or attempt')
    baseline = w.validate_lock(json.loads(w.git_bytes(root, 'show', binding['source_commit'] + ':upstream.lock.json')))
    lock = w.validate_lock(manifest['lock'])
    result = manifest['result']
    identity = w.source_identity(root, binding['source_commit'], lock)
    if manifest.get('identity') != identity:
        raise w.Error('Handoff validation source or overlay fingerprint differs')
    if result == {'status': 'up-to-date'}:
        if lock != baseline or manifest.get('bundle_sha256') is not None:
            raise w.Error('No-update handoff must preserve the exact source lock')
        return lock, result
    if result.get('status') != 'candidate' or set(result) != set(RESULT_FIELDS):
        raise w.Error('Unexpected handoff candidate result')
    expected = {'target': lock['wordpress']['tag'], 'new_base': lock['wordpress']['commit'],
                'old_base': baseline['wordpress']['commit'], 'previous_integration': baseline['integration']['commit'],
                'previous_sqlite': baseline['sqlite']['tag'], 'commit': lock['integration']['commit'],
                'branch': lock['integration']['branch'], 'source_commit': binding['source_commit'],
                'overlay_sha256': identity['overlay_sha256']}
    if any(result[k] != v for k, v in expected.items()):
        raise w.Error('Handoff candidate does not follow its trusted source lock')
    if (not isinstance(result['branch'], str) or not BRANCH.fullmatch(result['branch'])
            or not result['branch'].startswith('sync/wp-' + result['target'] + '-')
            or not isinstance(result['commit'], str) or not w.SHA.fullmatch(result['commit'])
            or not re.fullmatch(r'[0-9a-f]{64}', manifest.get('bundle_sha256') or '')):
        raise w.Error('Invalid pinned candidate ref or bundle digest')
    if {k: v for k, v in baseline.items() if k not in ('wordpress', 'sqlite', 'integration')} != {
            k: v for k, v in lock.items() if k not in ('wordpress', 'sqlite', 'integration')}:
        raise w.Error('Handoff may only update dependencies and integration')
    if (w.version(lock['wordpress']['tag']) < w.version(baseline['wordpress']['tag'])
            or w.version(lock['sqlite']['tag'][1:]) < w.version(baseline['sqlite']['tag'][1:])):
        raise w.Error('Handoff dependency downgrades are forbidden')
    for key in ('wordpress', 'sqlite'):
        if lock[key]['tag'] == baseline[key]['tag'] and lock[key] != baseline[key]:
            raise w.Error('Handoff cannot replace metadata under the same dependency tag')
    if (w.version(lock['wordpress']['tag'])[:2] != w.version(baseline['wordpress']['tag'])[:2]
            and not approve_series):
        raise w.Error('Handoff new series requires trusted workflow approval')
    return lock, result


def verify_contents(root, lock):
    files = w.verify_integration(root, lock)
    w.verify_overlay(root, lock, files)
    cache = root / '.cache/downloads' / (lock['sqlite']['sha256'] + '.zip')
    payload = cache.read_bytes() if cache.is_file() else w.download(lock['sqlite']['archive_url'])
    expected = {'wplite/vendor/sqlite/' + name: value for name, value in w.driver_files(payload, lock['sqlite']['sha256']).items()}
    actual = {name: value for name, value in files.items() if name.startswith('wplite/vendor/sqlite/')}
    if actual != expected:
        raise w.Error('Handoff driver bytes differ from the pinned official archive')


def export_handoff(root, directory):
    binding = context(root)
    changed = w.git(root, 'diff', '--name-only')
    if changed not in ('', 'upstream.lock.json') or w.git(root, 'diff', '--cached', '--name-only'):
        raise w.Error('Discovery must use clean trusted source apart from its candidate lock')
    lock = w.load_lock(root / 'upstream.lock.json')
    original = json.loads((root / '.cache/sync-result.json').read_bytes())
    if original.get('status') == 'up-to-date':
        result = {'status': 'up-to-date'}
    else:
        result = {k: original[k] for k in RESULT_FIELDS}
    directory.mkdir(parents=True, exist_ok=False)
    bundle_digest = None
    if result['status'] == 'candidate':
        branch = result['branch']
        if not isinstance(branch, str) or not BRANCH.fullmatch(branch):
            raise w.Error('Invalid discovery candidate ref')
        bundle = directory / 'candidate.bundle'
        w.git(root, 'bundle', 'create', str(bundle), 'refs/heads/' + branch, '^' + lock['wordpress']['commit'])
        bundle_digest = hashlib.sha256(read_regular(bundle, MAX_BUNDLE)).hexdigest()
    manifest = {'format': 1, **binding, 'identity': w.source_identity(root, binding['source_commit'], lock),
                'lock': lock, 'result': result, 'bundle_sha256': bundle_digest}
    transition(root, manifest, approve_series=os.environ.get('APPROVE_SERIES') == 'true')
    verify_contents(root, lock)
    w.write_json(directory / 'handoff.json', manifest)
    return {'ready': str(result['status'] == 'candidate').lower(),
            'handoff_sha256': hashlib.sha256((directory / 'handoff.json').read_bytes()).hexdigest()}


def load_handoff(root, directory):
    expected = os.environ.get('WPLITE_HANDOFF_SHA256', '')
    if not re.fullmatch(r'[0-9a-f]{64}', expected):
        raise w.Error('Missing discovery job handoff digest')
    if directory.is_symlink() or not directory.is_dir():
        raise w.Error('Handoff directory must be a real directory')
    payload = read_regular(directory / 'handoff.json', 131072)
    if hashlib.sha256(payload).hexdigest() != expected:
        raise w.Error('Handoff manifest digest mismatch')
    manifest = json.loads(payload)
    lock, result = transition(root, manifest, approve_series=os.environ.get('APPROVE_SERIES') == 'true')
    names = {'handoff.json', 'candidate.bundle'} if result['status'] == 'candidate' else {'handoff.json'}
    if {p.name for p in directory.iterdir()} != names:
        raise w.Error('Unexpected handoff files; test artifacts must not enter publication')
    if result['status'] == 'candidate':
        bundle = read_regular(directory / 'candidate.bundle', MAX_BUNDLE)
        if hashlib.sha256(bundle).hexdigest() != manifest['bundle_sha256']:
            raise w.Error('Handoff bundle digest mismatch')
    return manifest, lock, result


def import_handoff(root, directory):
    manifest, lock, result = load_handoff(root, directory)
    if w.git(root, 'diff', '--name-only') or w.git(root, 'diff', '--cached', '--name-only'):
        raise w.Error('Handoff import requires a fresh trusted checkout')
    # Metadata, Git objects and archive bytes only: never load PHP or a plugin.
    w.ensure_upstream(root, lock['wordpress']['repository'])
    w.fetch_tag(root, lock['wordpress']['tag'], lock['wordpress']['commit'])
    w.verify_official_source(root, lock['wordpress']['tag'], lock['wordpress']['commit'])
    if w.sqlite_release(lock['sqlite']['tag']) != lock['sqlite']:
        raise w.Error('Handoff SQLite release metadata no longer matches its pin')
    if result['status'] == 'candidate':
        bundle = (directory / 'candidate.bundle').resolve()
        w.git(root, 'bundle', 'verify', str(bundle))
        wanted = result['commit'] + ' refs/heads/' + result['branch']
        if w.git(root, 'bundle', 'list-heads', str(bundle)) != wanted:
            raise w.Error('Handoff bundle must contain exactly its pinned candidate ref')
        w.git(root, '-c', 'core.hooksPath=/dev/null', 'fetch', '--no-tags', str(bundle),
              'refs/heads/' + result['branch'] + ':refs/heads/' + result['branch'])
        if w.git(root, 'rev-parse', 'refs/heads/' + result['branch']) != result['commit']:
            raise w.Error('Imported candidate ref does not match its pin')
        # range-diff needs the source lock's previous integration too. It is
        # deliberately not supplied by the validation runner's Git state.
        previous = result['previous_integration']
        try:
            w.git(root, 'cat-file', '-e', previous + '^{commit}')
        except w.Error:
            w.git(root, 'fetch', '--no-tags', 'origin', previous)
    verify_contents(root, lock)
    w.write_json(root / 'upstream.lock.json', lock)
    w.write_json(root / '.cache/sync-result.json', result)
    return {'status': 'handoff-verified', 'candidate': result['status'] == 'candidate', **manifest['identity']}


def verify_receipt(root):
    _, lock, result = load_handoff(root, root / '.cache/sync-handoff')
    if (w.load_lock(root / 'upstream.lock.json') != lock
            or json.loads((root / '.cache/sync-result.json').read_bytes()) != result):
        raise w.Error('Publication inputs differ from the trusted discovery handoff')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('export', 'import'))
    args = parser.parse_args()
    directory = w.ROOT / '.cache/sync-handoff'
    try:
        result = export_handoff(w.ROOT, directory) if args.command == 'export' else import_handoff(w.ROOT, directory)
        if args.command == 'export':
            with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
                for key, value in result.items():
                    print(key + '=' + value, file=output)
        print(json.dumps(result, indent=2))
        return 0
    except Exception as error:
        print('WPLite handoff failed: ' + str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
