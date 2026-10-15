#!/usr/bin/env python3
"""Publish a validated integration candidate and a draft metadata PR in CI only."""

import base64
import json
import os
from pathlib import Path
import re
import sys
import urllib.parse
import urllib.request

from wplite import ROOT, Error, git, load_lock, version, verify_integration


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


def open_prs(repository, token):
    results = []
    for page in range(1, 11):
        batch = api(repository, token, 'GET', f'/pulls?state=open&per_page=100&page={page}')
        results += batch
        if len(batch) < 100:
            return results
    raise Error('Too many open PRs to safely establish sync deduplication')


def publish():
    if os.environ.get('GITHUB_ACTIONS') != 'true' or os.environ.get('GITHUB_EVENT_NAME') not in ('schedule', 'workflow_dispatch'):
        raise Error('PR publication is restricted to trusted scheduled/manual CI runs')
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
    verify_integration(ROOT, lock)
    if git(ROOT, 'symbolic-ref', '--short', 'HEAD') != 'main':
        raise Error('Publish metadata PRs from main only')
    if not approved_origin(git(ROOT, 'remote', 'get-url', 'origin'), repository):
        raise Error('Unexpected origin remote; refusing to push')
    target = lock['wordpress']['tag']
    driver = lock['sqlite']['tag'].removeprefix('v')
    suffix = result['branch'].rsplit('-', 1)[-1]
    metadata_branch = 'bot/sync-wp-' + target + '-sqlite-' + driver + '-' + suffix
    prs = open_prs(repository, token)
    existing = [pr for pr in prs if is_managed_pr(pr, repository) and pr_target(pr) == target and pr_driver(pr) == driver]
    if existing:
        print('Existing candidate PR: ' + existing[0]['html_url'])
        return
    if git(ROOT, 'diff', '--name-only') != 'upstream.lock.json' or git(ROOT, 'diff', '--cached', '--name-only'):
        raise Error('Only the dependency lock may be modified when publishing a metadata PR')
    env = os.environ.copy()
    authorization = base64.b64encode(('x-access-token:' + token).encode()).decode()
    env.update(GIT_CONFIG_COUNT='1',
               GIT_CONFIG_KEY_0='http.https://github.com/.extraheader',
               GIT_CONFIG_VALUE_0='AUTHORIZATION: basic ' + authorization)
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
            '- [Review integration changes](' + compare + ')\n'
            '- Core parity, build reproducibility, and SQLite installation smoke tests passed in this workflow.\n\n'
            'This draft requires maintainer review. It does not publish a production release or certify plugins.\n'
            'See this workflow run for the range-diff and build artifacts.\n')
    title = 'Update SQLite driver to ' + lock['sqlite']['tag'] if driver_only else 'Sync WordPress ' + target + ' / SQLite ' + driver
    pr = api(repository, token, 'POST', '/pulls', {'title': title,
             'head': metadata_branch, 'base': 'main', 'body': body, 'draft': True})
    print('Created draft sync PR: ' + pr['html_url'])
    for older in prs:
        if not is_managed_pr(older, repository):
            continue
        if superseded(older, target, driver):
            api(repository, token, 'PATCH', '/pulls/' + str(older['number']), {'state': 'closed'})


if __name__ == '__main__':
    try:
        publish()
    except Exception as error:
        print('WPLite sync publication failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
