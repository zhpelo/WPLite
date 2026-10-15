# WPLite development instructions

- Read `docs/architecture.md` and `docs/roadmap.md` before changing scope. The full
  product is not complete just because the M1 development ZIP can install.
- Main contains tooling and canonical overlays under `runtime/root/`. The actual
  distribution is an integration commit based on a pinned official stable tag.
  Do not copy WordPress core into main or edit upstream core files.
- Only official numeric stable tags are inputs. Never track master, trunk,
  nightly, Beta or RC. Never force-push main, integration history or release tags.
- Use explicit `rebase --onto NEW_BASE OLD_BASE` on isolated candidates. Preserve
  conflicts. Do not auto-resolve with ours/theirs.
- Update runtime code in `runtime/root/`, then explicitly run
  `python3 tools/wplite.py sync --refresh-overlay` before verifying/packaging it.
  Build reads the lock's integration SHA, not uncommitted overlay files.
- Tests: `python3 -m unittest discover -s tests -v`, `php tests/environment.php`,
  and `python3 tests/smoke.py --php .cache/test-runtime/install/bin/php`.
  Web checks: `python3 tests/web_install.py --php .cache/test-runtime/install/bin/php`;
  require `--httpd /path/to/httpd --apache-modules /path/to/modules` for the real
  protected-storage success path. Tests start isolated unprivileged processes;
  never reconfigure or start the user's system Apache/Nginx services.
  Diagnostics/Cron: `python3 tests/diagnostics.py --php .cache/test-runtime/install/bin/php`.
  Its HTTP fixture permits only the exact local cron URL, blocks all other
  network/mail, and must never enter a distribution. HTTP 200 is not Cron proof.
  `python3 tools/prepare_test_runtime.py` builds that isolated PHP when needed;
  it must not modify or install a system PHP.
- Never remove environment safeguards to make tests pass. Tests must use real
  PDO SQLite, a patched SQLite build, and no mysqli/pdo_mysql extensions.
- Never send email or call external payment/network services from tests. The
  offline test MU plugin must never enter a distributable artifact.
- Keep sync discovery, candidate validation and repository-write publication on
  separate fresh runners. Discovery/validation are contents:read; only publication
  has repository write permission and it must never run candidate PHP/plugins.
  Publish only discovery's immutable artifact ID and source/run-bound manifest
  digest. Never consume test-runner ZIPs, code, Git state or caches in the publisher.
  Third-party plugin tests still require offline network/mail/payment fixtures.
- Preserve user data and Git work. `.cache/` and `dist/` are generated; source
  archives, compiler logs and candidate conflict state remain useful evidence.
- Current local proof covers PHP 8.4 only. Do not claim a live GitHub workflow,
  PHP 8.3/8.5 matrix, complete shared-hosting/TLS matrix, signed updater, or WooCommerce
  compatibility without their actual execution evidence.
