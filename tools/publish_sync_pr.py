#!/usr/bin/env python3
"""Publish a validated integration candidate and a draft metadata PR in CI only."""

import base64
import json
import os
import re
import sys
import urllib.request

from wplite import (ROOT, Error, SHA, git, git_bytes, load_lock, validate_lock, version,
                    verify_integration, verify_overlay, integration_overlay, source_identity)


MARKER = '<!-- wplite:stable-sync -->'
PR_BRANCH = re.compile(r'bot/sync-wp-(?P<wp>[0-9]+\.[0-9]+(?:\.[0-9]+)?)(?:-sqlite-(?P<sqlite>[0-9]+\.[0-9]+\.[0-9]+))?-[A-Za-z0-9_]+')


def approved_origin(url, repository):
    return url.rstrip('/').removesuffix('.git') == 'https://github.com/' + repository


def api(repository, token, method, resource, payload=None):
    request = urllib.request.Request(
        'https://api.github.com/repos/' + repository + resource,
        method=method,
        data=json.dumps(payload).encode() if payload is not None else None,
        headers={'Authorization': 'Bearer ' + token,
                 'Accept': 'application/vnd.github+json',
                 'Content-Type': 'application/json', 'User-Agent': 'WPLite-sync',
                 'X-GitHub-Api-Version': '2022-11-28'},
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.load(response)


def is_managed_pr(pr, repository):
    return (MARKER in (pr.get('body') or '')
            and pr.get('user', {}).get('login') == 'github-actions[bot]'
            and (pr.get('head', {}).get('repo') or {}).get('full_name') == repository
            and PR_BRANCH.fullmatch(pr['head']['ref']) is not None)


def pr_target(pr):
    return PR_BRANCH.fullmatch(pr['head']['ref'])['wp']


def pr_driver(pr):
    found = PR_BRANCH.fullmatch(pr['head']['ref'])['sqlite']
    if found:
        return found
    legacy = re.search(r'SQLite driver: `v([0-9]+\.[0-9]+\.[0-9]+)`', pr.get('body') or '')
    return legacy[1] if legacy else None


def superseded(pr, wordpress, driver):
    old_driver = pr_driver(pr)
    if old_driver is None:
        return False
    old = (version(pr_target(pr)), version(old_driver))
    new = (version(wordpress), version(driver))
    return old[0] <= new[0] and old[1] <= new[1] and old != new


def publication_identity(repo, source, lock, files=None):
    identity = source_identity(repo, source, lock)
    actual = integration_overlay(verify_integration(repo, lock) if files is None else files, lock)
    if identity['overlay_sha256'] != actual:
        raise Error('Candidate runtime does not match its committed source overlay')
    return {**identity, **{key: lock[key] for key in ('format', 'wplite_version', 'wordpress', 'sqlite')}}


def ensure_commit(commit, env):
    if not isinstance(commit, str) or not SHA.fullmatch(commit):
        raise Error('Expected a pinned commit SHA')
    try:
        git(ROOT, 'cat-file', '-e', commit + '^{commit}')
    except Error:
        git(ROOT, 'fetch', '--no-tags', 'origin', commit, env=env)


def inspect_pr(pr, repository, source, env):
    """Inspect real Git objects, never trust a digest pasted in the PR body."""
    if not is_managed_pr(pr, repository) or pr.get('base', {}).get('ref') != 'main':
        return None
    head = pr.get('head', {}).get('sha')
    if not isinstance(head, str) or not SHA.fullmatch(head):
        return None
    ensure_commit(head, env)
    try:
        ancestry = git(ROOT, 'rev-list', '--parents', '-n', '1', head).split()
        if len(ancestry) != 2:
            return None
        identity = git(ROOT, 'show', '-s', '--format=%an%x00%ae%x00%cn%x00%ce', head).split('\0')
        if identity != ['WPLite Bot', 'bot@wplite.invalid', 'WPLite Bot', 'bot@wplite.invalid']:
            return None
        parent = ancestry[1]
        git(ROOT, 'merge-base', '--is-ancestor', parent, source)
        if git(ROOT, 'diff', '--name-only', parent, head) != 'upstream.lock.json':
            return None
        payload = git_bytes(ROOT, 'show', head + ':upstream.lock.json')
        if len(payload) > 65536:
            return None
        lock = validate_lock(json.loads(payload))
        if lock['wordpress']['tag'] != pr_target(pr) or (pr_driver(pr) is not None and lock['sqlite']['tag'] != 'v' + pr_driver(pr)):
            return None
    except (Error, ValueError, TypeError):
        return None
    if lock['integration']['commit'] is None:
        return None
    ensure_commit(lock['integration']['commit'], env)
    try:
        identity = publication_identity(ROOT, parent, lock)
    except (Error, ValueError, KeyError, TypeError):
        return None
    return {'pr': pr, 'source': parent, 'identity': identity}


def base_unchanged(source, env):
    refs = git(ROOT, 'ls-remote', '--heads', 'origin', 'refs/heads/main', env=env).split()
    if len(refs) != 2 or refs[0] != source or refs[1] != 'refs/heads/main':
        raise Error('Remote main advanced during validation; rerun sync on the new main. Existing PRs are preserved.')


def same_pr_snapshot(latest, previous, repository):
    return (latest.get('head', {}).get('sha') == previous['head']['sha']
            and latest.get('head', {}).get('ref') == previous['head']['ref']
            and latest.get('base', {}).get('ref') == 'main'
            and latest.get('state') == 'open' and is_managed_pr(latest, repository))


def recheck_keeper(keep, repository, token):
    latest = api(repository, token, 'GET', '/pulls/' + str(keep['number']))
    if not same_pr_snapshot(latest, keep, repository):
        raise Error('The verified candidate PR changed during publication; rerun sync. Other PRs are preserved.')


def close_older(records, keep, identity, repository, token, source, env):
    base_unchanged(source, env)
    recheck_keeper(keep, repository, token)
    current = (version(identity['wordpress']['tag']), version(identity['sqlite']['tag'][1:]))
    for record in records:
        previous = record['identity']
        old = (version(previous['wordpress']['tag']), version(previous['sqlite']['tag'][1:]))
        if record['pr']['number'] != keep['number'] and old[0] <= current[0] and old[1] <= current[1]:
            # inspect_pr already proved that this exact PR head is a metadata-only
            # child of an ancestor main with a matching canonical runtime.
            latest = api(repository, token, 'GET', '/pulls/' + str(record['pr']['number']))
            if not same_pr_snapshot(latest, record['pr'], repository):
                print('Changed candidate PR preserved: #' + str(record['pr']['number']))
                continue
            base_unchanged(source, env)
            recheck_keeper(keep, repository, token)
            api(repository, token, 'PATCH', '/pulls/' + str(record['pr']['number']), {'state': 'closed'})


def open_prs(repository, token):
    results = []
    for page in range(1, 11):
        batch = api(repository, token, 'GET', f'/pulls?state=open&per_page=100&page={page}')
        results += batch
        if len(batch) < 100:
            return results
    raise Error('Too many open PRs to safely establish sync deduplication')


def publish():
    if (os.environ.get('GITHUB_ACTIONS') != 'true'
            or os.environ.get('GITHUB_EVENT_NAME') not in ('schedule', 'workflow_dispatch', 'push')
            or os.environ.get('GITHUB_REF') != 'refs/heads/main'):
        raise Error('PR publication is restricted to trusted main scheduled/manual/push CI runs')
    repository = os.environ.get('GITHUB_REPOSITORY', '')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        raise Error('Invalid GitHub repository identity')
    token = os.environ['GH_TOKEN']
    result = json.loads((ROOT / '.cache/sync-result.json').read_text())
    if result['status'] != 'candidate':
        print('No new integration candidate; no remote changes.')
        return
    lock = load_lock(ROOT / 'upstream.lock.json')
    if result['commit'] != lock['integration']['commit'] or result['branch'] != lock['integration']['branch']:
        raise Error('Candidate result and dependency lock disagree')
    files = verify_integration(ROOT, lock)
    verify_overlay(ROOT, lock, files)
    if git(ROOT, 'symbolic-ref', '--short', 'HEAD') != 'main':
        raise Error('Publish metadata PRs from main only')
    if not approved_origin(git(ROOT, 'remote', 'get-url', 'origin'), repository):
        raise Error('Unexpected origin remote; refusing to push')
    if not approved_origin(git(ROOT, 'remote', 'get-url', '--push', 'origin'), repository):
        raise Error('Unexpected origin push URL; refusing to push')
    if git(ROOT, 'diff', '--name-only') != 'upstream.lock.json' or git(ROOT, 'diff', '--cached', '--name-only'):
        raise Error('Only the dependency lock may be modified when publishing a metadata PR')
    if git(ROOT, 'ls-files', '--others', '--exclude-standard', '--', 'runtime/root', 'tools', 'tests', '.github/workflows'):
        raise Error('Uncommitted publication inputs must not enter a sync candidate')
    source = git(ROOT, 'rev-parse', 'HEAD')
    if result.get('source_commit') != source:
        raise Error('Candidate source commit changed after sync')
    identity = publication_identity(ROOT, source, lock, files)
    if result.get('overlay_sha256') != identity['overlay_sha256']:
        raise Error('Candidate overlay identity changed after sync')
    target = lock['wordpress']['tag']
    driver = lock['sqlite']['tag'].removeprefix('v')
    suffix = result['branch'].rsplit('-', 1)[-1]
    metadata_branch = 'bot/sync-wp-' + target + '-sqlite-' + driver + '-' + suffix
    env = os.environ.copy()
    authorization = base64.b64encode(('x-access-token:' + token).encode()).decode()
    env.update(GIT_CONFIG_COUNT='1',
               GIT_CONFIG_KEY_0='http.https://github.com/.extraheader',
               GIT_CONFIG_VALUE_0='AUTHORIZATION: basic ' + authorization)
    base_unchanged(source, env)
    prs = open_prs(repository, token)
    records = []
    for previous in prs:
        record = inspect_pr(previous, repository, source, env)
        if record is not None:
            records.append(record)
        elif is_managed_pr(previous, repository):
            print('Unverifiable candidate PR preserved: #' + str(previous['number']))
    base_unchanged(source, env)
    existing = sorted((record['pr'] for record in records if record['identity'] == identity), key=lambda pr: pr['number'])
    if existing:
        print('Existing verified candidate PR: ' + existing[0]['html_url'])
        close_older(records, existing[0], identity, repository, token, source, env)
        return
    branch = result['branch']
    if not re.fullmatch(r'sync/wp-[0-9]+\.[0-9]+(?:\.[0-9]+)?-[A-Za-z0-9_]+', branch):
        raise Error('Unexpected integration candidate branch')
    # Explicit refs only. No force pushes, no changes to main or release tags.
    git(ROOT, 'push', 'origin', f'refs/heads/{branch}:refs/heads/{branch}', env=env)
    git(ROOT, 'switch', '-c', metadata_branch)
    git(ROOT, 'add', '--', 'upstream.lock.json')
    git(ROOT, '-c', 'user.name=WPLite Bot', '-c', 'user.email=bot@wplite.invalid',
        'commit', '-m', 'Track WordPress ' + target + ' / SQLite ' + driver)
    git(ROOT, 'push', 'origin', f'HEAD:refs/heads/{metadata_branch}', env=env)
    compare = 'https://github.com/' + repository + '/compare/' + result['previous_integration'] + '...' + result['commit']
    driver_only = (result['old_base'] == result['new_base'] and result.get('previous_sqlite') is not None
                   and result['previous_sqlite'] != lock['sqlite']['tag'])
    summary = ('Update the embedded SQLite driver from **' + result['previous_sqlite'] + '** to **' + lock['sqlite']['tag']
               + '** while keeping WordPress **' + target + '**.' if driver_only
               else 'Update the WPLite integration to official WordPress stable tag **' + target + '** with SQLite **' + driver + '**.')
    body = (MARKER + '\n\n' + summary + '\n\n'
            '- Official base: `' + result['new_base'] + '`\n'
            '- Integration: `' + result['commit'] + '` on `' + branch + '`\n'
            '- SQLite driver: `' + lock['sqlite']['tag'] + '`\n'
            '- Source main: `' + source + '`\n'
            '- Overlay SHA-256: `' + identity['overlay_sha256'] + '`\n'
            '- Validation SHA-256: `' + identity['validation_sha256'] + '`\n'
            '- [Review integration changes](' + compare + ')\n'
            '- Core parity, build reproducibility, and SQLite installation smoke tests passed in this workflow.\n\n'
            'This draft requires maintainer review. It does not publish a production release or certify plugins.\n'
            'See this workflow run for the range-diff and build artifacts.\n')
    title = 'Update SQLite driver to ' + lock['sqlite']['tag'] if driver_only else 'Sync WordPress ' + target + ' / SQLite ' + driver
    base_unchanged(source, env)
    pr = api(repository, token, 'POST', '/pulls', {'title': title,
             'head': metadata_branch, 'base': 'main', 'body': body, 'draft': True})
    print('Created draft sync PR: ' + pr['html_url'])
    close_older(records, pr, identity, repository, token, source, env)


if __name__ == '__main__':
    try:
        publish()
    except Exception as error:
        print('WPLite sync publication failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
