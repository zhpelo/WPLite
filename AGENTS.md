# WPLite development instructions

- The current priority is fast deployment. Keep only the upload-ready PHP
  installation package and essential installation/security logic.
- Do not develop tools/, checks/, tests/, test frameworks, CI platforms,
  backup/recovery, migration, auto-updaters, or new administration features
  without an explicit user request. The user intentionally removed tests/.
- Never modify, vendor, rebase, sync or publish WordPress core. No combined
  WordPress distributions, core update blocks, automated PRs or ePHPm.
- plugin/ is deployable as uploaded. Bundle the official SQLite dependency,
  retain its license, and verify the archive against dependencies.lock.json.
  Do not author a SQL translator or edit upstream vendor source.
- setup.php is only for new sites. Never overwrite site configuration,
  drop-ins or existing data. Protect setup ownership and database downloads.
- db-template.php is copied to wp-content/db.php during installation; its
  paths are intentionally relative to the installed drop-in location.
- Preserve WordPress update mechanisms. MU/driver updates require explicit
  deployment; do not imply native automatic MU updates or universal compatibility.
- Preserve unrelated user work and data, ignored historical artifacts and Git
  refs. Do not replace system PHP or reconfigure/start system services.
- Verify proportionately without creating a testing product. Record only actual
  results and limitations; no unmeasured performance or compatibility claims.
