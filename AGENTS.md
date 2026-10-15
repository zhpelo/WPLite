# WPLite development instructions

- WPLite is a SQLite integration package, NOT a WordPress fork/distribution.
  Read `docs/architecture.md` and `docs/roadmap.md` before changing scope.
- Never modify, vendor, rebase or publish WordPress core. No integration branches,
  core locks, upstream synchronization PRs, combined releases or core update blocks.
- Runtime source is `plugin/wp-content/db.php` and
  `plugin/wp-content/mu-plugins/`. Keep MU callbacks minimal and diagnostics lazy.
- Use the pinned official SQLite release in `dependencies.lock.json`; verify its
  SHA-256 before preparing dependencies. Never write our own SQL translator.
- `python3 tools/wplite.py prepare` assembles only the integration under `.cache/`.
  Never include site configuration, databases, test fixtures or WordPress core.
- `tools/install.php` is a local NEW-site setup helper, not a migration/updater.
  It must refuse existing configs, drop-ins, private data and legacy WPLite sites.
  Database storage must be outside the actual public root; do not add public-root
  storage probes, Web installers, backups, recovery or maintenance state machines.
- Keep WordPress core/plugin/theme update mechanisms unchanged. MU plugins still
  need explicit deployment updates; do not claim native MU automatic updates.
- Tests: `python3 -m unittest discover -s tests -v`,
  `.cache/test-runtime/install/bin/php -n tests/environment.php`, and
  `python3 tests/smoke.py --php .cache/test-runtime/install/bin/php`.
  `tools/prepare_test_runtime.py` prepares isolated patched PHP/SQLite if needed;
  never replace system PHP or reconfigure/start system services.
- CI only validates tooling and stable WordPress compatibility with read-only
  permissions. No auto PRs, repository writes or full-site build artifacts.
- Integration tests must run real PDO SQLite without mysqli/pdo_mysql and block
  external network/mail. Test fixtures never enter the plugin package.
- Preserve user work and data. Legacy generated `.cache/` and `dist/` files are
  not current deliverables; do not broadly delete them or historical Git refs.
- Record actual tested versions; never infer universal plugin, WooCommerce,
  hosting, future WordPress compatibility or resource savings from a smoke test.
