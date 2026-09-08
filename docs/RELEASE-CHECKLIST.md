# Release Checklist

Run through this list for every tagged release. Record the evidence (command
output, screenshots) against the release entry in `docs/releases/`.

## Evidence status

Last verified **2026-09-08** against `develop` @ `ed87103`, on PHP 8.2.12
(Windows). A tick is evidence from that run, not a standing guarantee — re-run
the checks against the actual release candidate before tagging.

- `[x]` — proven, with the evidence named inline.
- An unticked item carrying a *(unit-covered: …)* note is proven at the code
  level by the named test, but still owes its live-site check. The note tells
  you what you are re-confirming on a real install, not what you can skip.
- Everything else is still owed in full.

## 1. Supported baseline

| Component | Minimum | Verified against |
|---|---|---|
| PHP | 8.2 | 8.2, 8.3 (CI matrix) |
| WordPress | 6.5 | 6.5, latest |
| MySQL / MariaDB | 8.0 / 10.6 | — |
| Multisite | Not supported in 0.1.0 | See [ADR-0002](adr/0002-mvp-release-hardening-accepted-risks.md) |

Both minimums are enforced at activation by `Lifecycle::assert_environment()`,
and are declared in the plugin header (`Requires at least`, `Requires PHP`).

- [x] Plugin header, `SCALYN_MAIL_RELAY_VERSION` and `package.json` all agree on the version — all three read `0.1.0`
- [x] `SCALYN_MAIL_RELAY_DB_VERSION` bumped **only** if the schema changed this release — initial release; the schema is new at `0.1.0`, so there is nothing to bump against

## 2. CI and static checks

- [x] `composer validate --no-check-publish --strict` — `./composer.json is valid`
- [x] `composer check` green locally (`lint:php`, `lint:wpcs`, `test`) — WPCS reports 0 errors and 0 warnings; PHPUnit `OK`. `lint:php` was run as a per-file `php -l` sweep over all 102 tracked PHP files: the Composer script itself shells out to `find`/`xargs` and cannot execute on Windows, where Composer dispatches through `cmd.exe`
- [x] CI green on the PR for every PHP version in the matrix — PR #40: `php (8.2)` and `php (8.3)` both SUCCESS ([run 33845297608](https://github.com/scalyn-invited/scalyn-mail-relay/actions/runs/33845297608))
- [x] PHPUnit reports `OK` — not "OK, but there were issues"; warnings are failures for a release build — `OK (657 tests, 1311 assertions)`, exit 0

> `phpunit.xml.dist` sets no `failOn*` attributes, so PHPUnit exits 0 even when it
> prints "OK, but there were issues". The line above was confirmed by reading the
> output, not by the exit code.

## 3. Fresh install

Verified **2026-09-08** on a clean live installation running WordPress 7.1,
PHP 8.2.12, and MariaDB 10.6.20. Manual QA and screenshots were supplied for
the environment, activation, database, role capabilities, Admin UI, wizard, and
log checks.

- [x] Activate on a clean WordPress with no prior plugin data — activation completed without error, WordPress remained responsive, the Mail Relay menu appeared, and no recovery email was generated
- [x] All six tables created at the correct prefix — exactly six expected tables were returned under the site's prefix, with no equivalent tables under `wp_` or another prefix
- [x] `scalyn_mail_relay_db_version` and `scalyn_mail_relay_version` written — both options were verified at `0.1.0`
- [x] All seven `scalyn_mail_relay_*` capabilities present on Administrator — the serialized role record showed all seven capabilities enabled (`b:1`)
- [x] No PHP notices/warnings in `debug.log` during activation — no debug log was generated and no database, `dbDelta()`, credential, or plugin PHP errors were observed
- [x] Admin menu renders; every page loads without error — Dashboard, Setup Wizard, Providers, Email Logs, and Diagnostics rendered fully; assets, accessibility checks, empty states, and browser requests passed
- [x] Setup wizard completes end to end (provider → SMTP → connection test → test email) — every step passed, the completion screen rendered, and the Dashboard recognized the configured provider

## 4. Upgrade

> **N/A for 0.1.0** — this is the first release, so there is no previous version
> to upgrade from. Record it as N/A in the release entry rather than leaving the
> boxes unchecked and ambiguous. This section applies from 0.2.0 onward.

- [ ] Install the previous release, generate data (send mail, run diagnostics)
- [ ] Upgrade in place to the candidate build
- [ ] All pre-existing rows still present and readable
- [ ] `dbDelta()` applied any schema change without data loss
- [ ] No duplicate or orphaned scheduled events (`wp cron event list`)
- [ ] Settings written by the previous version still load and validate

## 5. Uninstall — both modes

Full policy: [UNINSTALL-POLICY.md](UNINSTALL-POLICY.md).

Both modes are covered end to end by `tests/unit/UninstallTest.php` (9 tests).
The live run re-confirms them against a real database.

**Retain (default):**

- [ ] Uninstall without touching any setting — *(unit-covered: `test_retains_everything_when_the_flag_is_absent`, `..._is_false`, `..._for_a_truthy_non_boolean_flag`)*
- [ ] All six tables still present, all rows intact — *(unit-covered: the same three assert no query is issued at all)*
- [ ] `scalyn_mail_relay_settings` still present — *(unit-covered: same three)*

**Delete (explicit opt-in):**

- [ ] Enable `delete_data_on_uninstall`, then uninstall
- [ ] All six tables dropped — *(unit-covered: `test_drops_every_owned_table_when_deletion_is_enabled`, six exact `DROP TABLE IF EXISTS` statements)*
- [ ] All three plugin options gone — *(unit-covered: `test_removes_plugin_options_but_leaves_others`)*
- [ ] No `scalyn_mail_relay_*` capability remains on any role (`wp cap list administrator`) — *(unit-covered: `test_revokes_every_granted_capability_from_every_role`, which sweeps `administrator` and `editor`)*
- [ ] No plugin cron events remain (`wp cron event list`) — *(unit-covered: `test_clears_every_owned_cron_hook`)*
- [ ] Options and capabilities belonging to other plugins untouched — *(unit-covered: `test_leaves_unrelated_capabilities_intact`, `test_deletes_plugin_transients_but_leaves_others`)*

## 6. Security and privacy

- [ ] Every admin page rejects a user without the required capability — *(unit-covered: `AdminMenuTest`, 10 tests spanning both "no capability" and "wrong capability" for all five pages)*
- [ ] REST endpoints reject unauthenticated and under-privileged requests (expect 401/403) — *(unit-covered: `DiagnosticsEndpointTest::test_endpoint_requires_permission` — the callback returns false without `RUN_DIAGNOSTICS` and true with it. It asserts the callback's return value, not an HTTP status code)*
- [ ] Every state-changing admin POST verifies a nonce — *(unit-covered: `WizardControllerTest::test_step{2,3,4,5}_invalid_nonce_dies`. `WizardController` is the only state-changing admin POST handler in the codebase)*
- [ ] No SMTP password, API key, OAuth token, authorization header or private key appears in: log tables, diagnostic `raw_result`, REST responses, admin HTML, or `debug.log` — *(unit-covered across `DiagnosticContextBuilderTest`, `DiagnosticsEndpointTest`, `MailEventSubscriberTest`, `MailLogRepositoryTest`, `SmtpProviderTest`, `WizardControllerTest`; `debug.log` is not)*
- [ ] No full email body is persisted or rendered — *(unit-covered: `MailLogRepositoryTest`, `TimelineRepositoryTest`)*
- [ ] Error responses carry no exception messages, SQL, or stack traces
- [ ] Accepted risks in [ADR-0002](adr/0002-mvp-release-hardening-accepted-risks.md) still hold — in particular, re-review the diagnostic probe accept if any capability was regranted to a lower-privileged role this release

## 7. Provider and diagnostic regression

- [ ] SMTP connection test against a real server: success path
- [ ] Connection test against a wrong port / wrong host: fails safely, no credential leakage in the message
- [ ] Test email delivered; mail log and timeline rows written with matching `message_uuid`
- [ ] Diagnostics run completes; SPF, MX, DKIM, DMARC and SMTP/TLS all return a result — *(unit-covered for registration: `DiagnosticsServiceWiringTest` asserts all five checks are in the registry. Real DNS and a real SMTP host are not)*
- [ ] Health score computes and persists
- [ ] A failing check renders its evidence and recommended action in the UI

## 8. Package

`vendor/` is gitignored, so a release ZIP is built from a clean export plus
production dependencies. `.gitattributes` `export-ignore` rules keep development
tooling out of the archive.

```bash
rm -rf build && mkdir -p build
git archive --format=tar --prefix=scalyn-mail-relay/ HEAD | tar -x -C build
composer install --no-dev --optimize-autoloader --working-dir=build/scalyn-mail-relay
rm -f build/scalyn-mail-relay/composer.json build/scalyn-mail-relay/composer.lock
cd build && zip -r ../scalyn-mail-relay-$(git describe --tags --always).zip scalyn-mail-relay
```

The `export-ignore` boundary was spot-checked on 2026-09-08:
`git archive --format=tar --prefix=scalyn-mail-relay/ HEAD | tar -t` yields only
`CHANGELOG.md`, `README.md`, `admin/`, `assets/`, `composer.json`,
`composer.lock`, `includes/`, `scalyn-mail-relay.php` and `uninstall.php`. That
clears the *export* step for the two content bullets below, but no ZIP has been
built, so none of them are ticked.

Verify the archive:

- [ ] Contains `scalyn-mail-relay.php`, `admin/`, `includes/`, `assets/`, `uninstall.php`, `vendor/`
- [ ] Contains **no** `tests/`, `docs/`, `.github/`, `AGENTS.md`, `phpcs.xml.dist`, `phpunit.xml.dist`, `package.json`, `.git/`
- [ ] `vendor/` contains no dev dependencies (no PHPUnit, no PHP_CodeSniffer)
- [ ] No `.env`, `*.pem`, `*.key`, `*.log`, or IDE directory
- [ ] Installs cleanly from the ZIP via Plugins → Add New → Upload

```bash
unzip -l scalyn-mail-relay-*.zip | grep -E "tests/|docs/|\.github/|phpunit|phpcs|\.env|\.pem|\.key" && echo "DIRTY PACKAGE" || echo "clean"
```

> Read the output — do not trust the exit code. Both branches of that `||` exit
> 0, so the command cannot fail a build and must not be wired into CI as-is.
> There is also no `zip` binary in Git Bash on the current dev machine, and the
> XAMPP PHP CLI has `extension=zip` commented out, so this section has to be run
> on Linux or from a shell with `zip` available.

## 9. Documentation

- [x] `CHANGELOG.md` updated with the real change set for this version — `## [0.1.0] - 2026-09-04`, covering Core, Mail transport, Logging, Diagnostics, Admin, Security, known limitations and release hardening
- [x] Known limitations still accurate in `README.md` and ADR-0002 — the three limitations (retention inert, uninstall UI programmatic-only, multisite unsupported) match across both
- [x] Any behaviour change reflected in the affected module README — **N/A**: no module carries a behaviour-documenting README. `docs/api/`, `docs/qa/` and `docs/releases/` hold only the foundation-directory placeholder

## 10. Release

- [ ] PR reviewed and merged to `develop`, then `develop` → `main` — first half done: PR #40 merged to `develop` 2026-09-04 with CI green, though GitHub records **no reviewer** on it. `develop` → `main` is still owed
- [ ] Tag created on `main`
- [ ] GitHub release created with the ZIP attached
- [ ] Rollback notes recorded: previous tag, and whether the DB version changed
      (a DB version bump means rollback needs a restore, not just a downgrade)
