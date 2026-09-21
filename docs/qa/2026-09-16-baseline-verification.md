# Baseline Verification Record 2026 09 16

Follow-up: the [September 17 clean-install run](2026-09-17-clean-install-verification.md)
subsequently verified fresh activation, tables, capabilities, and Admin render
paths in an isolated database. The limitations and blockers below describe the
September 16 attempts; see the follow-up for the local database-version limitation.

## Scope

This record covers the local automated portion of milestone 1 in
[IMPLEMENTATION-CHECKLIST.md](../IMPLEMENTATION-CHECKLIST.md). It was run from
the current checkout at commit `7a9699404e86c5184c8f91b04024380abf42e583`
(`fix/diagnostics-qa-followups`). The checkout also contains uncommitted
documentation and Git-ignore updates; no production PHP changes were made as
part of this verification.

## Automated results

| Check | Result | Evidence |
| --- | --- | --- |
| Composer metadata | Pass | `composer validate --no-check-publish --strict`: `./composer.json is valid` |
| PHP syntax | Pass | `php -l` against every tracked PHP file outside `vendor/` and `.git/`; no syntax errors |
| WordPress Coding Standards | Pass | `vendor\\bin\\phpcs.bat --standard=phpcs.xml.dist` exited successfully |
| Test suite | Pass | PHPUnit 10.5.64 on PHP 8.2.12: `OK (673 tests, 1347 assertions)` |
| Whitespace errors | Pass | `git diff --check` completed with no errors |

The test output includes expected, handled logging messages from failure-path
tests. PHPUnit completed successfully without warnings or failures.

## Code-level baseline confirmed

- Plugin header and constants declare version `0.1.0`, WordPress 6.5+, and PHP 8.2+.
- The automated suite covers the SMTP configuration/test-email path, mail logs,
  append-only timelines, diagnostics checks and execution, health scoring,
  capabilities, and uninstall code paths.
- The current release checklist retains prior live verification evidence from
  2026-09-08 against `develop` at `ed87103`; it is historical evidence and does
  not validate this checkout or a future release candidate.

## Live validation still required

The following milestone-1 items require a real WordPress installation and were
not performed in this local checkout:

- Clean installation, migration/table creation, Administrator capabilities, and all Admin pages.
- SMTP configuration, connection verification, and test-email acceptance against a real provider.
- DNS and SMTP/TLS diagnostics, persisted scoring, and correlated log/timeline rows against live targets.
- Retained-data and explicit delete-mode uninstall behavior against a real database.
- Real-environment privacy, error-redaction, accessibility, compatibility, and `debug.log` checks.

Run these against the exact release candidate and record the results in
[RELEASE-CHECKLIST.md](../RELEASE-CHECKLIST.md) before checking off the remaining
milestone-1 items or publishing a release.

## Clean-install ticket attempt

Attempted on 2026-09-16 against the two available local installations:

| Installation | Result |
| --- | --- |
| `C:\\xampp\\htdocs\\bernz` | `http://127.0.0.1/bernz/wp-login.php` timed out after 10 seconds; a CLI WordPress bootstrap did not return. |
| `C:\\xampp\\htdocs\\mailrelay` | `http://127.0.0.1/mailrelay/wp-login.php` timed out after 20 seconds; a CLI WordPress bootstrap did not return. |

Apache and MySQL ports were reachable, but neither installation completed a
WordPress request. No plugin was activated or deactivated, no database rows were
changed, and no Admin page could be inspected. Historical XAMPP logs contain
unrelated WordPress/plugin timeout errors, so this is an environment blocker,
not evidence of a Scalyn Mail Relay regression.

Resume this ticket after a responsive clean WordPress installation is available.
Then activate the plugin and verify the six prefixed tables, the version options,
the seven Administrator capabilities, and Dashboard, Setup Wizard, Providers,
Email Logs, and Diagnostics without notices or warnings.

## Existing installation verification

After a logged-in Administrator session became available, the existing
`C:\\xampp\\htdocs\\bernz` installation was checked on 2026-09-16. This is not
a clean install and therefore does not close the clean-install ticket, but it
confirms the current active installation is functioning at the checked surfaces.

| Check | Result |
| --- | --- |
| Plugin active | Pass: `scalyn-mail-relay/scalyn-mail-relay.php` is active. |
| Plugin tables | Pass: six tables use the active `gg_` prefix: `scalyn_alerts`, `scalyn_audit_logs`, `scalyn_diagnostics`, `scalyn_health_scores`, `scalyn_mail_logs`, and `scalyn_mail_timeline`. |
| Version options | Pass: database and plugin versions are both `0.1.0`. |
| Administrator capabilities | Pass: seven `scalyn_mail_relay_*` capabilities are present. |
| Dashboard | Pass: loaded and displayed health, configured SMTP, and recent accepted mail activity. |
| Setup Wizard | Pass: loaded and displayed all six setup stages. |
| Providers | Pass: loaded and displayed configured SMTP (PHPMailer). |
| Email Logs | Pass: loaded and displayed accepted SMTP outcomes and timeline links. |
| Diagnostics | Pass: loaded persisted DNS and SMTP/TLS results and the health score. |

The Admin pages showed unrelated WordPress-plugin update notices, but no Scalyn
Mail Relay error, warning, or fatal message was visible on the checked screens.
The remaining clean-install requirements are a newly provisioned site with no
existing Scalyn data, activation on that site, and a corresponding `debug.log`
review.
