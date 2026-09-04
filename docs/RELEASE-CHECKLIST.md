# Release Checklist

Run through this list for every tagged release. Record the evidence (command
output, screenshots) against the release entry in `docs/releases/`.

## 1. Supported baseline

| Component | Minimum | Verified against |
|---|---|---|
| PHP | 8.2 | 8.2, 8.3 (CI matrix) |
| WordPress | 6.5 | 6.5, latest |
| MySQL / MariaDB | 8.0 / 10.6 | — |
| Multisite | Not supported in 0.1.0 | See [ADR-0002](adr/0002-mvp-release-hardening-accepted-risks.md) |

Both minimums are enforced at activation by `Lifecycle::assert_environment()`,
and are declared in the plugin header (`Requires at least`, `Requires PHP`).

- [ ] Plugin header, `SCALYN_MAIL_RELAY_VERSION` and `package.json` all agree on the version
- [ ] `SCALYN_MAIL_RELAY_DB_VERSION` bumped **only** if the schema changed this release

## 2. CI and static checks

- [ ] `composer validate --no-check-publish --strict`
- [ ] `composer check` green locally (`lint:php`, `lint:wpcs`, `test`)
- [ ] CI green on the PR for every PHP version in the matrix
- [ ] PHPUnit reports `OK` — not "OK, but there were issues"; warnings are failures for a release build

## 3. Fresh install

- [ ] Activate on a clean WordPress with no prior plugin data
- [ ] All six tables created at the correct prefix
- [ ] `scalyn_mail_relay_db_version` and `scalyn_mail_relay_version` written
- [ ] All seven `scalyn_mail_relay_*` capabilities present on Administrator
- [ ] No PHP notices/warnings in `debug.log` during activation
- [ ] Admin menu renders; every page loads without error
- [ ] Setup wizard completes end to end (provider → SMTP → connection test → test email)

## 4. Upgrade

- [ ] Install the previous release, generate data (send mail, run diagnostics)
- [ ] Upgrade in place to the candidate build
- [ ] All pre-existing rows still present and readable
- [ ] `dbDelta()` applied any schema change without data loss
- [ ] No duplicate or orphaned scheduled events (`wp cron event list`)
- [ ] Settings written by the previous version still load and validate

## 5. Uninstall — both modes

Full policy: [UNINSTALL-POLICY.md](UNINSTALL-POLICY.md).

**Retain (default):**

- [ ] Uninstall without touching any setting
- [ ] All six tables still present, all rows intact
- [ ] `scalyn_mail_relay_settings` still present

**Delete (explicit opt-in):**

- [ ] Enable `delete_data_on_uninstall`, then uninstall
- [ ] All six tables dropped
- [ ] All three plugin options gone
- [ ] No `scalyn_mail_relay_*` capability remains on any role (`wp cap list administrator`)
- [ ] No plugin cron events remain (`wp cron event list`)
- [ ] Options and capabilities belonging to other plugins untouched

## 6. Security and privacy

- [ ] Every admin page rejects a user without the required capability
- [ ] REST endpoints reject unauthenticated and under-privileged requests (expect 401/403)
- [ ] Every state-changing admin POST verifies a nonce
- [ ] No SMTP password, API key, OAuth token, authorization header or private key appears in: log tables, diagnostic `raw_result`, REST responses, admin HTML, or `debug.log`
- [ ] No full email body is persisted or rendered
- [ ] Error responses carry no exception messages, SQL, or stack traces
- [ ] Accepted risks in [ADR-0002](adr/0002-mvp-release-hardening-accepted-risks.md) still hold — in particular, re-review the diagnostic probe accept if any capability was regranted to a lower-privileged role this release

## 7. Provider and diagnostic regression

- [ ] SMTP connection test against a real server: success path
- [ ] Connection test against a wrong port / wrong host: fails safely, no credential leakage in the message
- [ ] Test email delivered; mail log and timeline rows written with matching `message_uuid`
- [ ] Diagnostics run completes; SPF, MX, DKIM, DMARC and SMTP/TLS all return a result
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

Verify the archive:

- [ ] Contains `scalyn-mail-relay.php`, `admin/`, `includes/`, `assets/`, `uninstall.php`, `vendor/`
- [ ] Contains **no** `tests/`, `docs/`, `.github/`, `AGENTS.md`, `phpcs.xml.dist`, `phpunit.xml.dist`, `package.json`, `.git/`
- [ ] `vendor/` contains no dev dependencies (no PHPUnit, no PHP_CodeSniffer)
- [ ] No `.env`, `*.pem`, `*.key`, `*.log`, or IDE directory
- [ ] Installs cleanly from the ZIP via Plugins → Add New → Upload

```bash
unzip -l scalyn-mail-relay-*.zip | grep -E "tests/|docs/|\.github/|phpunit|phpcs|\.env|\.pem|\.key" && echo "DIRTY PACKAGE" || echo "clean"
```

## 9. Documentation

- [ ] `CHANGELOG.md` updated with the real change set for this version
- [ ] Known limitations still accurate in `README.md` and ADR-0002
- [ ] Any behaviour change reflected in the affected module README

## 10. Release

- [ ] PR reviewed and merged to `develop`, then `develop` → `main`
- [ ] Tag created on `main`
- [ ] GitHub release created with the ZIP attached
- [ ] Rollback notes recorded: previous tag, and whether the DB version changed
      (a DB version bump means rollback needs a restore, not just a downgrade)
