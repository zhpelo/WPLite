# WPLite development build

This distribution defaults to SQLite and includes the WordPress community SQLite
driver as a mandatory system component. No MySQL service is needed.

This is an early development build, **not a certified production release**. The
owner-authorized Web installer is available; the signed bundle updater and
complete backup/recovery UI are not yet implemented. Direct WordPress core updates are blocked to prevent installing an
untested core/driver combination. Do not deploy this build on a live store.

## Initial CLI installation

Use PHP 8.3–8.5 with `pdo_sqlite`. Supported SQLite builds are 3.51.3+ except
withdrawn 3.52.0, or certified backports 3.44.6 and 3.50.7. The SQLite library
loaded by PHP matters, not the separately installed sqlite3 command.

Run `php wplite/cli.php doctor` first. Set `WPLITE_ADMIN_PASSWORD` securely in your
shell environment (at least 12 bytes; do not put secrets in shell history), then:

```sh
php wplite/cli.php install \
  --url https://example.com \
  --title 'My WPLite site' \
  --admin-user siteadmin \
  --admin-email admin@example.com \
  --data-dir /private/wplite-example
```

The data directory must not already exist, and its parent must be writable by
PHP. It must be outside the public web root and on the same host's local disk.
If WordPress is in a subdirectory of a larger document root, supply
`--public-root /actual/public/root` as well. This CLI prototype deliberately does
not support storing data under the public root; use the Web installer for
HTTP-verified shared-hosting storage.

Keep `wp-config.php`, the private data directory, and the matching system files
together. Do not delete `site.json`, move `DB_PATH`, disable the database drop-in,
or copy only the live `.sqlite` file as a backup. WAL data can contain committed
transactions that have not reached the main file.

## Initial Web installation (no server SSH)

On your own computer, use the project's `tools/prepare_web_install.py` to create
a new private local directory containing setup-key.php and owner-secret.txt.
Upload **only setup-key.php** to this site's wplite/ directory with the hosting
file manager. Never upload owner-secret.txt. Open the site over HTTPS and supply
the local secret in the SQLite installation form within one hour of the key
file's modification time. The used server key is deleted on success; an existing
or incomplete site configuration closes the installation endpoint.

Prefer a new absolute data path outside the actual document root. Leaving it
blank attempts .wplite-data under the site. Before any database or site secrets
are written there, installation checks a public random control response plus
HTTP 403/404 denial of database, WAL, SHM, JSON, hidden and nested probe files.
It does not follow redirects or accept timeouts/500 errors as protection.
Loopback HTTP(S) and an available PHP worker are required for these checks.

Apache must actually enforce the generated `Require all denied` rule. Nginx
requires an explicit location denying the entire directory and descendants;
it does not read .htaccess. A failed privacy check leaves only an owned prepared
directory and never a database; configure server protection and retry. Do not
delete unknown data to work around installation failures.

The immutable root wp-config.php now loads the generated wplite/site-config.php.
Keep the **site configuration**, storage metadata and matching release together.
Existing development installations are not automatically migrated. A missing or
altered deny file stops a protected public-storage site; changes to external
web-server/CDN rules still require manual HTTP protection revalidation.

Full instructions and the local key tool:
https://github.com/zhpelo/WPLite/blob/main/docs/web-install.md

## Operational diagnostics and Cron (development)

The administrator-only Tools → WPLite diagnostics page reports current runtime,
SQLite connection policy, storage configuration, disk headroom and a one-shot
Cron observation. It does not expose private paths, site IDs, salts or backup
keys. Version and disk information are still operational data; review exports
before sharing. Full database scanning is not performed on an ordinary page view.

```sh
php wplite/cli.php doctor
php wplite/cli.php doctor --data-dir /private/site --integrity quick
php wplite/cli.php doctor-site --integrity quick
php wplite/cli.php cron-probe --transport schedule
php wplite/cli.php cron-status --require-execution yes
```

`doctor --data-dir` works without loading WordPress, opens only an existing
database in read-only mode and never creates a replacement database. SQLite may
maintain read-coordination sidecars when reading a live WAL database. During
maintenance this report avoids opening the database. `doctor-site` loads the
ordinary WordPress environment and therefore may run normal plugin/load hooks.
Only explicit `--integrity quick` requests run SQLite quick_check.

Cron configuration is not execution evidence. A probe is a single non-recurring
event; it is not scheduled automatically and its option is not autoloaded. Let
your existing scheduler execute it, or explicitly choose loopback. That option
requests the canonical site's wp-cron.php with TLS verification and no redirects,
and **can execute all due WordPress tasks**, not just this diagnostic. A successful
HTTP response alone never proves event execution. Refresh the page or poll status.

DISABLE_WP_CRON only disables traffic triggering; an external scheduler may still
run `php wp-cron.php`. A real event records whether it ran through CLI or HTTP.
Late execution and stale-release observations are identified separately. An old
observation is not current evidence; neither a single success nor a configuration
flag proves recurring scheduler reliability or commerce compatibility. The basic
content/configuration result and commerce certification are separate; commerce
remains unverified in this development release.

Protected public storage reports its installation-time HTTP verification and the
current deny-file hash, not a new server/CDN protection test. Recheck externally
after changing server rules. Full diagnostics instructions:
https://github.com/zhpelo/WPLite/blob/main/docs/diagnostics.md

## Database backup and recovery (development CLI)

```sh
php wplite/cli.php backup-create
php wplite/cli.php backup-verify --backup-dir /private/site/backups/BACKUP-ID --data-dir /private/site
php wplite/cli.php restore --backup-dir /private/site/backups/BACKUP-ID --data-dir /private/site
```

Creation uses SQLite `VACUUM INTO`, flushes the standalone snapshot, checks
integrity and site identity, and authenticates the manifest with a per-site key
kept only in private `site.json`. Keep that private state safe; losing it means
these authenticated recovery commands cannot verify your backups. The optional
`backup-create --output-dir /private/NEW-DIRECTORY` must name a new directory
outside the actual public document root. Existing backups are never overwritten.
For Web-installed protected storage, the default backups subdirectory can also
reside inside the same HTTP-denied data tree; arbitrary public directories remain
forbidden. Independent database recovery supports that verified storage mode.

These are **database-only** backups: they do not contain uploads, plugins,
themes, wp-config.php or the backup authentication key. Full-site snapshot
creation is separate (below); full-site replacement recovery and the
authenticated Web recovery UI are still pending. The database recovery commands
require this build's site metadata and the exact matching WPLite release; they
do not import arbitrary MySQL dumps or migrate earlier development installs.

Restore is an explicit data rollback to the snapshot. It rejects new traffic
with HTTP 503, waits for existing request shutdown hooks, preserves the original
database and WAL/SHM components under the private `rollback/` directory, installs
the snapshot, and checks integrity, identity, runtime files and core version
before reopening. External scripts that open SQLite directly must be stopped;
only WPLite requests and maintenance tools participate in its locks.

If recovery is interrupted, the durable maintenance marker remains even after
the owner process exits. Inspect it without loading WordPress:

```sh
php wplite/cli.php restore-status --data-dir /private/site
php wplite/cli.php restore-rollback --data-dir /private/site
```

Rollback is available while maintenance is still active and the original
database was preserved. It keeps displaced files for inspection. Alternatively,
repair the matching system files and explicitly accept the already-restored
snapshot using `restore-complete --data-dir /private/site`; health checks still
must pass. These commands do not automatically rewind a site after it has
reopened and accepted new orders.

## Full site snapshots (development CLI, not full-site restore)

```sh
php wplite/cli.php site-backup-create --data-dir /private/site
php wplite/cli.php site-backup-verify --data-dir /private/site --backup-dir /private/site/backups/site-BACKUP-ID
```

These independent commands never bootstrap WordPress or execute saved plugin or
theme PHP. Creation drains requests, snapshots SQLite and streams the entire site
tree into private files, including uploads, configuration, plugins/themes,
dotfiles and empty directories. Only the bound data tree is excluded, avoiding
recursive backups and copies of live database/WAL files. External filesystem or
direct database writers must be stopped first. Links, special files and changes
outside the drained request protocol reject the snapshot. Current limits are
50,000 entries, 64 path components and a 16 MiB manifest.

Snapshots contain configuration salts and application secrets and are **not
encrypted**. Keep them private and use encrypted off-site storage. The backup key
is deliberately excluded; independently retain the original private site.json
in a protected recovery vault. Never authenticate a backup using a key supplied
by that same backup. These snapshots are not public distribution ZIPs.

A killed backup owner leaves traffic at HTTP 503 and an incomplete snapshot.
After inspecting the cause, `site-backup-complete --data-dir /private/site`
checks the unchanged database/runtime and closes only a site-backup maintenance
window. It does not restore files or resume copying. Do not use database restore
commands on a kind=site snapshot: full-site replacement, independent Web recovery
and migration are not yet implemented. Details:
https://github.com/zhpelo/WPLite/blob/main/docs/backups.md
