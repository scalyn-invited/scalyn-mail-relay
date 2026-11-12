# QA5 Dashboard progress package — 2026-11-07

Decision owner: Jay. Status: verified local QA candidate; dedicated-site version,
active state and corrected Dashboard acceptance observed in user screenshots.
Not a production release or remote installed-file checksum verification.

## Artifact identity

- Plugin version: `0.1.1-qa.5`.
- Database schema: `0.15.0` (unchanged).
- ZIP: `/home/jayryan_ai/Projects/scalyn-local-artifacts/core-purpose-2026-11-07/qa5-package/scalyn-mail-relay-0.1.1-qa.5-src-91e3922d4a58.zip`.
- ZIP SHA-256: `a31e03dccaeda2856af81ab70c2245b26a1b11af9690edd65eebd5cfd805476b`.
- Source manifest fingerprint: `91e3922d4a58da00b6ddaa1714bd718cce4bc04528902707259464f1257fc5ca`.
- Base HEAD: `064942eb57279651391308d308e459caee6915f8` plus preserved uncommitted
  QA4 work and the reviewed Dashboard fix. Fingerprint is not a commit/Git tree.

## Contents and source scope

The only distributed source differences from the checksum-verified QA4 ZIP are:
`admin/Pages/DashboardPage.php`, `admin/views/dashboard.php`, the version-only
change in `scalyn-mail-relay.php`, and `CHANGELOG.md`. Dashboard changes match
[the scoped implementation evidence](core-purpose-2026-11-07.md) and its
independent review. QA4 default SMTP, credential protection and recovery-read
repairs remain present unchanged. No schema migration was added.

Repository version metadata changes: plugin header/constant, `package.json`,
`tests/bootstrap.php`, and the expected version in `tests/unit/LifecycleTest.php`
now consistently name QA5. Unrelated working-tree edits were not staged, reset,
committed or discarded.

## Verification

Evidence root:
`/home/jayryan_ai/Projects/scalyn-local-artifacts/core-purpose-2026-11-07/qa5-package/`.

Entry points: `build.py` and `verify-handoff.py`. Full commands/exit codes and
source/archive per-file SHA-256 inventory are in `package-manifest.json`;
`handoff-verification.json` records independent parent read-back.

- 583 unique safe archive entries, exact distribution allowlist plus production
  vendor only; CRC and every entry hash checked after writing and extraction.
- Current source parity and no source mutation during build checked.
- 468 packaged PHP files linted successfully on PHP 8.5.12.
- Actual extracted production autoloader resolves changed Dashboard code,
  credential storage, WordPress mail bridge and Dompdf from the extracted tree.
- Full strict packaged-runtime suites: 2,171 tests / 8,860 assertions each on
  PHP 8.2.35, 8.3.36 and 8.5.12; all exit 0. Deprecations, warnings, notices and
  risky tests fail the invocation.
- Tests use external development tooling/fixtures with all plugin runtime
  directories/files mounted read-only from the exact extracted ZIP in an
  isolated network namespace. This is packaged-source regression testing,
  not a new full installed WordPress or provider/inbox test.
- Packaged-runtime WPCS and `git diff --check` passed.
- Independent packaging audit passed against the exact ZIP hash;
  `package-review.json` records source/version/dependency checks and independent
  production-autoload, full PHP 8.5 strict-suite and exact focused-test execution.
- Audit caveat: an unanchored `DashboardPageTest|LifecycleTest` filter also selects
  Postmark webhook lifecycle tests and exposes three fixture/global-state
  order-dependent failures. Parent reproduced the same three named Postmark
  failures against both QA4 and QA5 runtime mounts with current tests. The exact
  Postmark class alone passes 9 tests / 65 assertions on both; exact Dashboard/
  Lifecycle selection and the full strict suite pass. This pre-existing partial-
  suite isolation gap remains a separate test-maintenance follow-up, not a claim
  that every possible test selection passes. Full comparison evidence is in
  `partial-unanchored-characterization.json`; QA4 also fails four new Dashboard
  expectations as expected for the pre-fix code. No test was suppressed/changed
  to conceal this result.
- Earlier installed WordPress success/failure/Dashboard evidence remains in the
  linked record with its actual QA4-era source identities, not relabeled QA5
  acceptance. The Dashboard runtime bytes are identical; entrypoint version and
  changelog differ as described above.

## Dependencies

Production vendor is reused byte-for-byte from verified QA4 ZIP SHA-256
`f55543882b19a36aa3f9a9e8b73d9def8e21eeb34355fbce55ba6b3d43014ae0`.
Its production package inventory matches current `composer.lock` exactly and
`installed.json` declares `dev: false`:

- dompdf/dompdf v3.1.6
- dompdf/php-font-lib 1.0.2
- dompdf/php-svg-lib 1.0.2
- masterminds/html5 2.11.0
- sabberworm/php-css-parser v9.5.0

No dependency installation, version upgrade or fresh advisory lookup occurred
*during packaging*. A later [release preflight](release-preflight-2026-11-07.md)
ran a real locked-production Composer audit on November 7, exit 0, with no reported
advisories or abandoned packages. That audit does not change the QA5 ZIP bytes.

## Observed dedicated-site acceptance — 2026-11-07

Jay supplied `clip_20261107_204307_20.png`: Installed Plugins displays QA5 and the
Deactivate action, establishing visible version and active status. The subsequent
`clip_20261107_204632_21.png` shows the new recorded-SPF/DKIM/DMARC step grey/pending,
steps 1–3 and the run-diagnostics step green, and the earlier SMTP password-reset
Accepted activity still visible. Images are local evidence in this profile's
images directory; browser/account identifiers are not reproduced here.

This closes the bounded installed-site display-fix acceptance. It does not
cryptographically identify every remote file, rerun mail after replacement, or
constitute broad production approval. No extra send or diagnostics run is required
to close this display repair.

## Replacement and acceptance

Jay authorized the next QA package/replacement step. No remote write, upload,
configuration change, real email/provider request, credential operation, commit,
push, merge or production deployment was performed during packaging.

On the dedicated test site: ensure a current database/files backup includes the
matching private credential key. Use Plugins → Add New Plugin → Upload Plugin,
select this ZIP, Install Now, then Replace current with uploaded. Do not
uninstall/delete the old plugin or change SMTP/routing/recovery/webhook settings.
Ordinary WordPress requests may trigger scheduled work; no deliberate mail test
is needed just to verify this display-only fix.

Read back the Installed Plugins QA5 active row and Dashboard setup progress.
With DKIM still Unknown, the recorded-SPF/DKIM/DMARC step should be pending while
the run-diagnostics step remains complete. Do not invent a DKIM selector or edit
DNS merely to make progress green. Retain earlier working SMTP settings.

Remote integration/CI, complete compatibility/accessibility coverage, refreshed
dependency audit and production release decision remain separate gates.
