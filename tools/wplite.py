#!/usr/bin/env python3
"""Stable-tag-only WordPress integration and reproducible WPLite packaging.

No third-party Python dependencies. Repository mutations happen only on explicit
sync/build commands; check is read-only. Never reset or rebase the tooling branch.
"""

from __future__ import annotations

import argparse
import copy
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from datetime import datetime, timezone


ROOT = Path(__file__).resolve().parents[1]
WP_REPOSITORY = "https://github.com/WordPress/WordPress.git"
SQLITE_REPOSITORY = "https://github.com/WordPress/sqlite-database-integration.git"
STABLE_TAG = re.compile(r"[0-9]+\.[0-9]+(?:\.[0-9]+)?\Z")
SHA = re.compile(r"[0-9a-f]{40}\Z")
MANAGED_ROOTS = ("wplite", "wp-config.php", "wp-content/db.php", "wp-content/mu-plugins/wplite.php", "WPLITE.md")
SITE_ONLY_FILES = frozenset(("wplite/site-config.php", "wplite/setup-key.php", "wplite/.install.lock", "wplite/owner-secret.txt"))
MAX_ARCHIVE = 64 * 1024 * 1024
DRIVER_ARCHIVE_ROOT = "plugin-sqlite-database-integration"
SQLITE_RELEASE_API = "https://api.github.com/repos/WordPress/sqlite-database-integration/releases/"


class Error(RuntimeError):
    pass


def git(repo: Path, *args: str, env: dict | None = None) -> str:
    result = subprocess.run(
        ["git", "-C", str(repo), *args], stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, env=env, check=False,
    )
    if result.returncode:
        raise Error(result.stderr.decode(errors="replace").strip())
    return result.stdout.decode().strip()


def git_bytes(repo: Path, *args: str) -> bytes:
    result = subprocess.run(["git", "-C", str(repo), *args], capture_output=True)
    if result.returncode:
        raise Error(result.stderr.decode(errors="replace").strip())
    return result.stdout


def version(tag: str) -> tuple[int, int, int]:
    if not isinstance(tag, str) or not STABLE_TAG.fullmatch(tag):
        raise Error(f"Not an official stable version name: {tag!r}")
    return tuple((list(map(int, tag.split("."))) + [0])[:3])


def parse_tags(output: str) -> dict[str, str]:
    refs = {}
    for line in output.splitlines():
        parts = line.split()
        if len(parts) != 2 or not SHA.fullmatch(parts[0]):
            continue
        sha, ref = parts
        if not ref.startswith("refs/tags/"):
            continue
        name = ref.removeprefix("refs/tags/").removesuffix("^{}")
        if STABLE_TAG.fullmatch(name):
            # Annotated tags: use the peeled commit, independent of line order.
            if ref.endswith("^{}") or name not in refs:
                refs[name] = sha
    return refs


def remote_tags(repository: str) -> dict[str, str]:
    return parse_tags(git(ROOT, "ls-remote", "--tags", repository))


def select_update(current: str, tags: dict[str, str]) -> dict:
    baseline = version(current)
    patches = [t for t in tags if version(t)[:2] == baseline[:2] and version(t) > baseline]
    series = [t for t in tags if version(t)[:2] > baseline[:2]]
    return {
        "status": "patch" if patches else "up-to-date",
        "current": current,
        "target": max(patches, key=version) if patches else None,
        "next_series": max(series, key=version) if series else None,
        "series_approval_required": bool(series),
    }


def write_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
    os.replace(temporary, path)


def load_lock(path: Path) -> dict:
    return validate_lock(json.loads(path.read_text()))


def validate_lock(data: dict) -> dict:
    try:
        if data["format"] != 1:
            raise Error("Unsupported dependency lock format")
        if data["wordpress"]["repository"] != WP_REPOSITORY:
            raise Error("WordPress upstream must be the approved official repository")
        version(data["wordpress"]["tag"])
        if data["sqlite"]["repository"] != SQLITE_REPOSITORY:
            raise Error("SQLite upstream must be the approved community repository")
        if not re.fullmatch(r"v[0-9]+\.[0-9]+\.[0-9]+", data["sqlite"]["tag"]):
            raise Error("SQLite dependency must use a stable release tag")
        for item in (data["wordpress"], data["sqlite"]):
            if not SHA.fullmatch(item["commit"]):
                raise Error("Dependencies must be pinned to full commit SHAs")
        if not re.fullmatch(r"[0-9a-f]{64}", data["sqlite"]["sha256"]):
            raise Error("SQLite archive requires a SHA-256 digest")
        expected_url = SQLITE_REPOSITORY.removesuffix(".git") + "/releases/download/"
        expected_url += data["sqlite"]["tag"] + "/plugin-sqlite-database-integration.zip"
        if data["sqlite"]["archive_url"] != expected_url:
            raise Error("SQLite archive must belong to the pinned official release")
        integration = data["integration"]["commit"]
        if integration is not None and not SHA.fullmatch(integration):
            raise Error("Integration must be pinned to a full commit SHA")
        if not re.fullmatch(r"[0-9A-Za-z][0-9A-Za-z.+_-]*", data["wplite_version"]):
            raise Error("Invalid WPLite version")
    except (KeyError, TypeError) as exc:
        raise Error(f"Malformed dependency lock: {exc}") from exc
    return data


class SafeDownloadRedirect(urllib.request.HTTPRedirectHandler):
    """Never forward repository credentials or follow a TLS downgrade."""

    def redirect_request(self, request, fp, code, message, headers, new_url):
        if not new_url.startswith("https://"):
            raise Error("Insecure download redirect")
        redirected = super().redirect_request(request, fp, code, message, headers, new_url)
        if redirected is not None:
            redirected.remove_header("Authorization")
        return redirected


def download(url: str, maximum: int = MAX_ARCHIVE) -> bytes:
    if not url.startswith("https://"):
        raise Error("Only HTTPS downloads are allowed")
    request = urllib.request.Request(url, headers={"User-Agent": "WPLite-maintainer/0.1"})
    token = os.environ.get("WPLITE_GITHUB_API_TOKEN")
    if token and re.fullmatch(re.escape(SQLITE_RELEASE_API) + r"(?:latest|tags/v[0-9]+\.[0-9]+\.[0-9]+)", url):
        request.add_header("Authorization", "Bearer " + token)
    with urllib.request.build_opener(SafeDownloadRedirect()).open(request, timeout=60) as response:
        if not response.url.startswith("https://"):
            raise Error("Insecure download redirect")
        payload = response.read(maximum + 1)
    if len(payload) > maximum:
        raise Error("Download exceeds the configured size limit")
    return payload


def sqlite_release(tag: str | None = None) -> dict:
    """Resolve a published stable driver release, never an un-released Git tag."""
    if tag is not None and not re.fullmatch(r"v[0-9]+\.[0-9]+\.[0-9]+", tag):
        raise Error("SQLite target must be a stable vX.Y.Z release tag")
    endpoint = "tags/" + tag if tag else "latest"
    release = json.loads(download(SQLITE_RELEASE_API + endpoint))
    name = release.get("tag_name", "")
    if (not re.fullmatch(r"v[0-9]+\.[0-9]+\.[0-9]+", name)
            or release.get("draft") is not False or release.get("prerelease") is not False
            or not release.get("published_at") or (tag and name != tag)):
        raise Error("SQLite target is not a published stable release")
    assets = [a for a in release.get("assets", []) if a.get("name") == "plugin-sqlite-database-integration.zip"]
    url = SQLITE_REPOSITORY.removesuffix(".git") + f"/releases/download/{name}/plugin-sqlite-database-integration.zip"
    if (len(assets) != 1 or assets[0].get("state") != "uploaded"
            or assets[0].get("browser_download_url") != url
            or not re.fullmatch(r"sha256:[0-9a-f]{64}", assets[0].get("digest") or "")):
        raise Error("SQLite release requires its expected uploaded ZIP and published SHA-256 digest")
    refs = git(ROOT, "ls-remote", "--tags", SQLITE_REPOSITORY,
               f"refs/tags/{name}", f"refs/tags/{name}^{{}}")
    values = dict(line.split()[::-1] for line in refs.splitlines() if line)
    commit = values.get(f"refs/tags/{name}^{{}}", values.get(f"refs/tags/{name}", ""))
    if not SHA.fullmatch(commit):
        raise Error("SQLite release tag does not resolve to a full commit SHA")
    return {"repository": SQLITE_REPOSITORY, "tag": name, "commit": commit,
            "archive_url": url, "sha256": assets[0]["digest"].removeprefix("sha256:")}


def choose_driver(current: dict, release: dict, explicit: bool = False) -> dict:
    old, new = version(current["tag"][1:]), version(release["tag"][1:])
    if new == old and release != current:
        raise Error("Recorded SQLite release metadata changed; refusing replacement")
    if new < old:
        if explicit:
            raise Error("SQLite driver downgrades are forbidden")
        return current
    return release


def verify_recorded_tags(lock: dict, wp_tags: dict[str, str]) -> None:
    wp = lock["wordpress"]
    if wp_tags.get(wp["tag"]) != wp["commit"]:
        raise Error(f"Recorded WordPress tag {wp['tag']} disappeared or changed SHA; refusing sync")
    dep = lock["sqlite"]
    refs = git(ROOT, "ls-remote", "--tags", dep["repository"],
               f"refs/tags/{dep['tag']}", f"refs/tags/{dep['tag']}^{{}}")
    values = dict(line.split()[::-1] for line in refs.splitlines() if line)
    actual = values.get(f"refs/tags/{dep['tag']}^{{}}", values.get(f"refs/tags/{dep['tag']}"))
    if actual != dep["commit"]:
        raise Error("Recorded SQLite tag disappeared or changed SHA; refusing sync")


def ensure_upstream(repo: Path, repository: str) -> None:
    remotes = git(repo, "remote").splitlines()
    if "upstream" not in remotes:
        git(repo, "remote", "add", "upstream", repository)
    elif git(repo, "remote", "get-url", "upstream") != repository:
        raise Error("Existing upstream remote does not match the dependency lock")
    # remote add normally installs a wildcard branch refspec. Remove it so an
    # accidental plain fetch cannot track master/trunk/development branches.
    removal = subprocess.run(["git", "-C", str(repo), "config", "--unset-all", "remote.upstream.fetch"], capture_output=True)
    if removal.returncode not in (0, 5):
        raise Error(removal.stderr.decode(errors="replace"))
    git(repo, "config", "remote.upstream.tagOpt", "--no-tags")
    git(repo, "config", "remote.upstream.pushurl", "DISABLED")


def fetch_tag(repo: Path, tag: str, expected: str) -> str:
    version(tag)
    if not SHA.fullmatch(expected):
        raise Error("Tag must be pinned to a full SHA")
    ref = f"refs/tags/upstream/{tag}"
    git(repo, "fetch", "--no-tags", "upstream", f"refs/tags/{tag}:{ref}")
    if git(repo, "rev-parse", f"{ref}^{{commit}}") != expected:
        raise Error(f"Tag {tag} changed while being fetched")
    return expected


def verify_official_source(repo: Path, tag: str, commit: str) -> None:
    source = git_bytes(repo, "show", f"{commit}:wp-includes/version.php")
    found = re.search(rb"\$wp_version\s*=\s*['\"]([^'\"]+)['\"]", source)
    if not found or found[1].decode() != tag:
        raise Error("Tag name does not match the WordPress source version")
    query = urllib.parse.urlencode({"version": tag, "locale": "en_US"})
    checksums = json.loads(download("https://api.wordpress.org/core/checksums/1.0/?" + query))
    expected = checksums.get("checksums", {}).get("wp-includes/version.php")
    if not expected or hashlib.md5(source).hexdigest() != expected:
        raise Error("WordPress.org release checksum does not confirm this tag")


def safe_member(name: str) -> PurePosixPath:
    path = PurePosixPath(name)
    if not name or "\\" in name or path.is_absolute() or ".." in path.parts:
        raise Error(f"Unsafe archive member: {name!r}")
    return path


def driver_files(payload: bytes, expected_sha: str) -> dict[str, bytes]:
    if hashlib.sha256(payload).hexdigest() != expected_sha:
        raise Error("SQLite archive SHA-256 mismatch")
    files = {}
    with zipfile.ZipFile(io.BytesIO(payload)) as archive:
        total = 0
        for entry in archive.infolist():
            path = safe_member(entry.filename)
            if entry.is_dir():
                continue
            if (entry.external_attr >> 16) & 0o170000 == 0o120000:
                raise Error("Symlinks are not allowed in the driver archive")
            if len(path.parts) < 2 or path.parts[0] != DRIVER_ARCHIVE_ROOT:
                raise Error("Unexpected SQLite archive root")
            relative = str(PurePosixPath(*path.parts[1:]))
            if relative in files:
                raise Error("Duplicate driver archive member")
            total += entry.file_size
            if total > MAX_ARCHIVE:
                raise Error("Expanded SQLite archive exceeds size limit")
            files[relative] = archive.read(entry)
    if "wp-includes/sqlite/db.php" not in files:
        raise Error("SQLite release is missing its database entrypoint")
    return files


def runtime_files(root: Path) -> dict[str, bytes]:
    files = {}
    for source in sorted((root / "runtime" / "root").rglob("*")):
        if source.is_symlink():
            raise Error("Runtime overlays must not contain symbolic links")
        if source.is_file():
            relative = source.relative_to(root / "runtime" / "root").as_posix()
            if relative in SITE_ONLY_FILES or relative == "wplite/distribution.json":
                raise Error(f"Site credentials/state or generated manifest must never enter an overlay: {relative}")
            if not any(relative == p or relative.startswith(p + "/") for p in MANAGED_ROOTS):
                raise Error(f"Overlay attempts to modify an unmanaged path: {relative}")
            if relative.startswith("wplite/vendor/sqlite/"):
                raise Error("SQLite vendor files must come from the pinned official archive")
            files[relative] = source.read_bytes()
    return files


def fingerprint(files: dict[str, bytes], wplite_version: str | None = None) -> str:
    identity = {"format": 1, "files": {name: hashlib.sha256(data).hexdigest() for name, data in files.items()}}
    if wplite_version is not None:
        identity["wplite_version"] = wplite_version
    return hashlib.sha256(json.dumps(identity, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def source_identity(repo: Path, commit: str, lock: dict) -> dict:
    files = source_files(repo, commit, ("runtime/root", "tools", "tests", ".github/workflows",
                                      "upstream.lock.json", ".gitattributes", "AGENTS.md"))
    recorded = validate_lock(json.loads(files["upstream.lock.json"]))
    if any(recorded[key] != lock[key] for key in ("format", "wplite_version")):
        raise Error("Candidate distribution version does not match its source main")
    overlay_files = {name.removeprefix("runtime/root/"): data for name, data in files.items() if name.startswith("runtime/root/")}
    validation = {name: data for name, data in files.items() if name.startswith(("tools/", "tests/", ".github/workflows/"))
                  or name in (".gitattributes", "AGENTS.md")}
    if not overlay_files:
        raise Error("Source commit has no canonical runtime overlay")
    return {"overlay_sha256": fingerprint(overlay_files, lock["wplite_version"]),
            "validation_sha256": fingerprint(validation)}


def overlay(repo: Path, root: Path, lock: dict) -> None:
    paths = list(runtime_files(root).items())
    official = set(git(repo, "ls-tree", "-r", "--name-only", lock["wordpress"]["commit"]).splitlines())
    for relative, _ in paths:
        if relative in official:
            raise Error(f"Overlay would overwrite an official core file: {relative}")
    cache = root / ".cache" / "downloads" / (lock["sqlite"]["sha256"] + ".zip")
    if cache.exists():
        payload = cache.read_bytes()
    else:
        payload = download(lock["sqlite"]["archive_url"])
        driver_files(payload, lock["sqlite"]["sha256"])
        cache.parent.mkdir(parents=True, exist_ok=True)
        cache.write_bytes(payload)
    paths += [("wplite/vendor/sqlite/" + p, b) for p, b in driver_files(payload, lock["sqlite"]["sha256"]).items()]
    wanted = {p for p, _ in paths}
    if wanted & official:
        raise Error("Driver or runtime overlay would overwrite official core files")
    manifest_path = repo / "wplite/distribution.json"
    previous = json.loads(manifest_path.read_bytes()).get("files", {}) if manifest_path.exists() else {}
    owned = set(previous) | set(git(repo, "ls-files", "--", "wplite/vendor/sqlite").splitlines())
    for obsolete in sorted(owned - wanted - official):
        safe_member(obsolete)
        if not any(obsolete == p or obsolete.startswith(p + "/") for p in MANAGED_ROOTS):
            raise Error("Previous manifest contains an unmanaged path")
        (repo / obsolete).unlink(missing_ok=True)
    # This manifest intentionally excludes the integration commit to avoid a
    # circular, self-referential commit hash.
    metadata = {k: lock[k] for k in ("format", "wplite_version", "wordpress", "sqlite")}
    metadata["file_manifest_version"] = 1
    metadata["files"] = {p: hashlib.sha256(b).hexdigest() for p, b in paths}
    paths.append(("wplite/distribution.json", (json.dumps(metadata, indent=2) + "\n").encode()))
    for relative, data in paths:
        target = repo / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
    git(repo, "add", "--", *MANAGED_ROOTS)
    if git(repo, "diff", "--cached", "--name-only"):
        env = os.environ.copy()
        timestamp = git(repo, "show", "-s", "--format=%cI", lock["wordpress"]["commit"])
        env.update(GIT_AUTHOR_DATE=timestamp, GIT_COMMITTER_DATE=timestamp)
        git(repo, "-c", "user.name=WPLite Bot", "-c", "user.email=bot@wplite.invalid",
            "commit", "-m", f"WPLite {lock['wplite_version']}: SQLite {lock['sqlite']['tag']}", env=env)


def sync(root: Path, lock_path: Path, target: str | None, approve_series: bool,
         refresh: bool = False, sqlite_tag: str | None = None) -> dict:
    lock = load_lock(lock_path)
    tags = remote_tags(lock["wordpress"]["repository"])
    verify_recorded_tags(lock, tags)
    update = select_update(lock["wordpress"]["tag"], tags)
    selected = target or update["target"] or lock["wordpress"]["tag"]
    if selected not in tags:
        raise Error("Target is not an existing official stable tag")
    if version(selected) < version(lock["wordpress"]["tag"]):
        raise Error("Automatic upstream downgrades are forbidden")
    if version(selected)[:2] != version(lock["wordpress"]["tag"])[:2] and not approve_series:
        raise Error("A new WordPress release series requires --approve-series")
    driver = choose_driver(lock["sqlite"], sqlite_release(sqlite_tag), explicit=sqlite_tag is not None)
    driver_changed = driver != lock["sqlite"]
    if selected == lock["wordpress"]["tag"] and lock["integration"]["commit"] is not None and not refresh and not driver_changed:
        result = {**update, "status": "up-to-date", "candidate": None, "sqlite": driver["tag"]}
        write_json(root / ".cache" / "sync-result.json", result)
        return result
    ensure_upstream(root, lock["wordpress"]["repository"])
    old_base = fetch_tag(root, lock["wordpress"]["tag"], lock["wordpress"]["commit"])
    new_base = fetch_tag(root, selected, tags[selected])
    verify_official_source(root, selected, new_base)
    previous = lock["integration"]["commit"] or old_base
    try:
        git(root, "cat-file", "-e", previous + "^{commit}")
    except Error:
        git(root, "fetch", "--no-tags", "origin", previous)
    parent = root / ".cache" / "candidates"
    parent.mkdir(parents=True, exist_ok=True)
    candidate = Path(tempfile.mkdtemp(prefix=f"wp-{selected}-", dir=parent))
    git(root, "clone", "--shared", "--no-checkout", str(root), str(candidate))
    branch = f"sync/wp-{selected}-{candidate.name.rsplit('-', 1)[-1]}"
    git(candidate, "switch", "-c", branch, previous)
    state = {"root": str(root), "lock_sha256": hashlib.sha256(lock_path.read_bytes()).hexdigest(),
             "target": selected, "branch": branch, "old_base": old_base, "new_base": new_base,
             "previous_integration": previous, "next_series": update["next_series"],
             "sqlite": driver, "previous_sqlite": lock["sqlite"]["tag"]}
    write_json(candidate / ".git" / "wplite-sync.json", state)
    if new_base != old_base:
        try:
            git(candidate, "-c", "user.name=WPLite Bot", "-c", "user.email=bot@wplite.invalid",
                "rebase", "--committer-date-is-author-date", "--onto", new_base, old_base)
        except Error as exc:
            raise Error(f"Rebase stopped; resolve conflicts in {candidate}. Lock unchanged.\n{exc}") from exc
    return finish_candidate(root, lock_path, candidate, state)


def finish_candidate(root: Path, lock_path: Path, candidate: Path, state: dict) -> dict:
    if state["root"] != str(root) or hashlib.sha256(lock_path.read_bytes()).hexdigest() != state["lock_sha256"]:
        raise Error("Dependency lock changed since this candidate was created; refusing to overwrite it")
    for marker in ("rebase-merge", "rebase-apply"):
        if (candidate / ".git" / marker).exists():
            raise Error("Finish the candidate's rebase before resuming integration")
    if git(candidate, "status", "--porcelain"):
        raise Error("Candidate contains uncommitted changes; commit the conflict resolution first")
    new_lock = copy.deepcopy(load_lock(lock_path))
    new_lock["wordpress"].update(tag=state["target"], commit=state["new_base"])
    new_lock["sqlite"] = state.get("sqlite", new_lock["sqlite"])
    overlay(candidate, root, new_lock)
    commit = git(candidate, "rev-parse", "HEAD")
    new_lock["integration"] = {"commit": commit, "branch": state["branch"]}
    files = verify_integration(candidate, new_lock)
    digest = verify_overlay(root, new_lock, files)
    try:
        source_commit = git(root, "rev-parse", "--verify", "HEAD")
    except Error:
        # Local first-time bootstrapping may precede main's first commit. Such
        # candidates remain buildable, but the publisher requires a real source.
        source_commit = None
    # Import only the tested candidate ref; do not move or rebase main.
    git(root, "fetch", "--no-tags", str(candidate), f"HEAD:refs/heads/{state['branch']}")
    write_json(lock_path, new_lock)
    result = {k: v for k, v in state.items() if k not in ("root", "lock_sha256")}
    result.update(status="candidate", candidate=str(candidate), commit=commit,
                  source_commit=source_commit, overlay_sha256=digest)
    write_json(root / ".cache" / "sync-result.json", result)
    return result


def resume(root: Path, lock_path: Path, candidate: Path) -> dict:
    candidate = candidate.resolve()
    if candidate.parent != (root / ".cache" / "candidates").resolve() or not (candidate / ".git").is_dir():
        raise Error("Only candidates created inside this project's .cache/candidates may be resumed")
    state = json.loads((candidate / ".git" / "wplite-sync.json").read_text())
    lock = load_lock(lock_path)
    tags = remote_tags(lock["wordpress"]["repository"])
    verify_recorded_tags(lock, tags)
    if tags.get(state["target"]) != state["new_base"]:
        raise Error("Candidate's official stable tag has changed; refusing to resume")
    if state.get("sqlite") and sqlite_release(state["sqlite"]["tag"]) != state["sqlite"]:
        raise Error("Candidate's SQLite release changed; refusing to resume")
    verify_official_source(candidate, state["target"], state["new_base"])
    return finish_candidate(root, lock_path, candidate, state)


def source_files(repo: Path, commit: str, paths: tuple[str, ...] = ()) -> dict[str, bytes]:
    if not SHA.fullmatch(commit):
        raise Error("Source commit must be a full SHA")
    # Read literal tree blobs. git archive honors export-ignore/export-subst,
    # which must not hide a core file or alter the source being fingerprinted.
    entries = []
    for record in git_bytes(repo, "ls-tree", "-rz", "--full-tree", commit, "--", *paths).split(b"\0"):
        if not record:
            continue
        header, name = record.split(b"\t", 1)
        mode, kind, sha = header.decode().split()
        relative = name.decode("utf-8")
        safe_member(relative)
        if mode not in ("100644", "100755") or kind != "blob" or not SHA.fullmatch(sha):
            raise Error(f"Unsupported source member: {relative}")
        entries.append((relative, sha))
    process = subprocess.run(["git", "-C", str(repo), "cat-file", "--batch"],
                             input="".join(sha + "\n" for _, sha in entries).encode(), capture_output=True)
    if process.returncode:
        raise Error(process.stderr.decode(errors="replace"))
    stream = io.BytesIO(process.stdout)
    files = {}
    for name, sha in entries:
        fields = stream.readline().decode().split()
        if len(fields) != 3 or fields[:2] != [sha, "blob"] or not fields[2].isdigit():
            raise Error("Invalid Git blob response")
        size = int(fields[2])
        data = stream.read(size)
        if len(data) != size or stream.read(1) != b"\n":
            raise Error("Incomplete Git blob response")
        files[name] = data
    if stream.read():
        raise Error("Unexpected trailing Git blob response")
    return files


def verify_integration(repo: Path, lock: dict) -> dict[str, bytes]:
    commit = lock["integration"]["commit"]
    if not commit:
        raise Error("Run sync once to create the initial integration")
    try:
        git(repo, "cat-file", "-e", commit + "^{commit}")
    except Error:
        git(repo, "fetch", "--no-tags", "origin", commit)
    base = lock["wordpress"]["commit"]
    git(repo, "merge-base", "--is-ancestor", base, commit)
    files = source_files(repo, commit)
    if SITE_ONLY_FILES.intersection(files):
        raise Error("Site credentials or installation state must never enter a distribution")
    original = source_files(repo, base)
    for name, content in original.items():
        if files.get(name) != content:
            raise Error(f"Official core file modified or removed: {name}")
    for name in files.keys() - original.keys():
        if not any(name == p or name.startswith(p + "/") for p in MANAGED_ROOTS):
            raise Error(f"Unexpected integration file: {name}")
    expected = {k: lock[k] for k in ("format", "wplite_version", "wordpress", "sqlite")}
    manifest = json.loads(files.get("wplite/distribution.json", b"{}"))
    if any(manifest.get(k) != v for k, v in expected.items()):
        raise Error("Integration distribution manifest does not match the dependency lock")
    if manifest.get("file_manifest_version") == 1:
        additions = files.keys() - original.keys() - {"wplite/distribution.json"}
        if set(manifest.get("files", {})) != additions:
            raise Error("Manifest must describe every added distribution file")
        for name, digest in manifest["files"].items():
            if digest != hashlib.sha256(files[name]).hexdigest():
                raise Error(f"Distribution file does not match its manifest: {name}")
    for name in ("wp-content/db.php", "wplite/bootstrap.php"):
        if manifest.get("files", {}).get(name) != hashlib.sha256(files.get(name, b"")).hexdigest():
            raise Error(f"Mandatory runtime file does not match its manifest: {name}")
    if "wp-content/db.php" not in files or "wplite/bootstrap.php" not in files:
        raise Error("Missing mandatory SQLite integration components")
    return files


def build(root: Path, lock_path: Path, output: Path) -> dict:
    lock = load_lock(lock_path)
    files = verify_integration(root, lock)
    timestamp = int(git(root, "show", "-s", "--format=%ct", lock["integration"]["commit"]))
    date = datetime.fromtimestamp(max(timestamp, 315532800), timezone.utc)
    output.mkdir(parents=True, exist_ok=True)
    name = f"wplite-{lock['wplite_version']}-wp-{lock['wordpress']['tag']}"
    target = output / (name + ".zip")
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path, data in sorted(files.items()):
            info = zipfile.ZipInfo("wplite/" + path, date.timetuple()[:6])
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data, compresslevel=9)
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    (output / (name + ".sha256")).write_text(f"{digest}  {target.name}\n")
    write_json(output / (name + ".json"), {"dependencies": lock, "sha256": digest,
                                          "source_date_epoch": timestamp})
    return {"archive": str(target), "sha256": digest, "files": len(files)}


def integration_overlay(files: dict[str, bytes], lock: dict) -> str:
    manifest = json.loads(files["wplite/distribution.json"])
    if manifest.get("file_manifest_version") != 1:
        raise Error("Overlay freshness requires a complete file manifest; explicitly refresh the overlay")
    own = {name: files[name] for name in manifest["files"] if not name.startswith("wplite/vendor/sqlite/")}
    return fingerprint(own, lock["wplite_version"])


def verify_overlay(root: Path, lock: dict, files: dict[str, bytes] | None = None) -> str:
    actual = integration_overlay(verify_integration(root, lock) if files is None else files, lock)
    expected = fingerprint(runtime_files(root), lock["wplite_version"])
    if actual != expected:
        raise Error("Locked integration contains a stale or different canonical overlay; run sync --refresh-overlay explicitly")
    return actual


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--lock", type=Path)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("check", help="Read-only discovery of official stable versions")
    command = commands.add_parser("sync", help="Create an isolated rebase candidate and update the lock")
    command.add_argument("--tag")
    command.add_argument("--approve-series", action="store_true")
    command.add_argument("--refresh-overlay", action="store_true", help="Explicitly integrate local runtime edits on the current stable tag")
    command.add_argument("--sqlite-tag", help="Optional explicit published stable SQLite driver release")
    command = commands.add_parser("resume", help="Finalize an isolated candidate after manual conflict resolution")
    command.add_argument("--candidate", type=Path, required=True)
    command = commands.add_parser("build", help="Build a reproducible ZIP from the locked integration")
    command.add_argument("--output", type=Path)
    command = commands.add_parser("verify", help="Verify integration contents and core parity")
    command.add_argument("--overlay", action="store_true", help="Also require the locked runtime to match canonical source (CI gate)")
    args = parser.parse_args()
    root = args.root.resolve()
    lock_path = args.lock or root / "upstream.lock.json"
    try:
        lock = load_lock(lock_path)
        if args.command == "check":
            tags = remote_tags(lock["wordpress"]["repository"])
            verify_recorded_tags(lock, tags)
            result = select_update(lock["wordpress"]["tag"], tags)
            driver = choose_driver(lock["sqlite"], sqlite_release())
            result["sqlite"] = {"current": lock["sqlite"]["tag"], "latest": driver["tag"],
                                "target": driver["tag"] if driver != lock["sqlite"] else None}
            if driver != lock["sqlite"]:
                result["status"] = "combined-update" if result["target"] else "driver-update"
        elif args.command == "sync":
            result = sync(root, lock_path, args.tag, args.approve_series, args.refresh_overlay, args.sqlite_tag)
        elif args.command == "build":
            result = build(root, lock_path, args.output or root / "dist")
        elif args.command == "resume":
            result = resume(root, lock_path, args.candidate)
        else:
            files = verify_integration(root, lock)
            result = {"status": "verified", "files": len(files), "core": lock["wordpress"]["tag"]}
            if args.overlay:
                result["overlay_sha256"] = verify_overlay(root, lock, files)
        print(json.dumps(result, indent=2))
        return 0
    except (Error, OSError, ValueError, urllib.error.URLError, zipfile.BadZipFile) as exc:
        print(f"WPLite: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
