#!/usr/bin/env python3
"""Build an isolated test-only PHP CLI and patched SQLite under .cache.

Does not install packages, change PATH, modify a system PHP, or start a service.
Requires a C compiler and make. This minimal PHP has no MySQL extensions.
"""

import hashlib
import os
from pathlib import Path
import subprocess
import sys
import tarfile

from wplite import ROOT, download, safe_member


SOURCES = {
    "php": ("https://www.php.net/distributions/php-8.4.26.tar.xz",
            "32a2de53862ad44ed4a5005244ce4f1b50c271e74dced215449a4443b40569f1"),
    "sqlite": ("https://sqlite.org/2026/sqlite-autoconf-3530400.tar.gz",
               "0e9483900e92cd5de8fd48d16bf9200145a61f7fd5be542a5ac81d8a9516eb9c"),
}


def run(arguments, directory, env, log):
    print("Running: " + " ".join(map(str, arguments)), flush=True)
    with log.open("ab") as output:
        subprocess.run(list(map(str, arguments)), cwd=directory, env=env,
                       stdout=output, stderr=subprocess.STDOUT, check=True)


def source(kind, directory):
    url, expected = SOURCES[kind]
    archive = directory / url.rsplit("/", 1)[1]
    if not archive.exists():
        archive.write_bytes(download(url))
    if hashlib.sha256(archive.read_bytes()).hexdigest() != expected:
        raise RuntimeError("Test runtime source checksum mismatch: " + kind)
    with tarfile.open(archive) as bundle:
        members = bundle.getmembers()
        top = safe_member(members[0].name).parts[0]
        target = directory / top
        if target.exists():
            return target
        for entry in members:
            relative = safe_member(entry.name)
            if relative.parts[0] != top:
                raise RuntimeError("Mixed source archive roots")
            destination = directory / entry.name
            if entry.isdir():
                destination.mkdir(parents=True, exist_ok=True)
            elif entry.isfile():
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(bundle.extractfile(entry).read())
                destination.chmod(entry.mode & 0o755)
            else:
                raise RuntimeError("Unexpected source archive link")
    return target


def main():
    directory = ROOT / ".cache" / "test-runtime"
    directory.mkdir(parents=True, exist_ok=True)
    prefix = directory / "install"
    php = prefix / "bin" / "php"
    if php.is_file():
        print(php)
        return
    env = os.environ.copy()
    log = directory / "build.log"
    sqlite = source("sqlite", directory)
    run(["./configure", "--prefix=" + str(prefix), "--disable-shared", "--enable-static"], sqlite, env, log)
    run(["make", "-j4"], sqlite, env, log)
    run(["make", "install"], sqlite, env, log)
    php_source = source("php", directory)
    env.update(SQLITE_CFLAGS="-I" + str(prefix / "include"),
               SQLITE_LIBS="-L" + str(prefix / "lib") + " -lsqlite3 -lm",
               PKG_CONFIG="/usr/bin/false")
    run(["./configure", "--prefix=" + str(prefix), "--disable-all", "--enable-cli",
         "--disable-cgi", "--disable-phpdbg", "--without-pear", "--enable-pdo",
         "--with-pdo-sqlite", "--with-sqlite3", "--enable-session", "--enable-filter",
         "--enable-ctype", "--enable-tokenizer", "--enable-fileinfo", "--enable-bcmath"],
        php_source, env, log)
    run(["make", "-j4"], php_source, env, log)
    run(["make", "install"], php_source, env, log)
    subprocess.run([str(php), "-r", 'echo PHP_VERSION, " SQLite ", (new PDO("sqlite::memory:"))->query("SELECT sqlite_version()")->fetchColumn(), PHP_EOL;'], check=True)
    print(php)


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("Runtime preparation failed: " + str(error), file=sys.stderr)
        print("See .cache/test-runtime/build.log", file=sys.stderr)
        sys.exit(1)
