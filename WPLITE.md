# WPLite development build

This distribution defaults to SQLite and includes the WordPress community SQLite
driver as a mandatory system component. No MySQL service is needed.

This is an early development build, **not a certified production release**. The
web installer, signed bundle updater and complete backup/recovery UI are not yet
implemented. Direct WordPress core updates are blocked to prevent installing an
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
not support storing data under the public root; guarded shared-hosting storage
will be added with the web installer.

Keep `wp-config.php`, the private data directory, and the matching system files
together. Do not delete `site.json`, move `DB_PATH`, disable the database drop-in,
or copy only the live `.sqlite` file as a backup. WAL data can contain committed
transactions that have not reached the main file.
