# Uninstall verification — 2026-09-17

## Outcome

Milestone 1 ticket 5 passed the isolated single-site checks. Retain mode preserved
synthetic records and configuration; explicit delete mode removed the documented
owned data; activation after deletion recreated empty tables and capabilities.
No production code changes were needed. Bernie remains the final reviewer.

## Safety and environment

- Source commit: `7a9699404e86c5184c8f91b04024380abf42e583`, existing
  `fix/diagnostics-qa-followups` branch. Existing unrelated changes were preserved.
- Inspected local `origin/develop` history through `768fe9d`; no remote fetch.
- WordPress 7.1, PHP 8.2.12, MariaDB 10.4.32. Supported-database testing remains
  outstanding; this does not certify MariaDB 10.6+ or MySQL 8+.
- Used the earlier CLI-only clean installation under
  `build/clean-install-20260917/`, database `scalyn_clean_20260917`, prefix `qa_`.
- Helper refuses execution outside CLI and validates the database name, prefix,
  and resolved WordPress root before mutations. Each table had to be empty before
  seeding and contain exactly one synthetic fixture before destructive testing.
- SHA-256 checks matched copied uninstall, capabilities, lifecycle, and migration
  files against this checkout before each phase.
- The working `bernz` site was not uninstalled or used as a deletion target.
  No mail was sent. No credentials were printed or copied into the report.

## Procedure and observed results

The Git-ignored helper `build/clean-install-20260917/verify-uninstall.php` ran in
three separate PHP processes, using WordPress `deactivate_plugins()`,
`uninstall_plugin()`, and `activate_plugin()`, not a simulated database.

1. **Retain:** seeded one synthetic row in each of the six plugin tables and set
   the delete flag to boolean false. Deactivation cleared all four historical
   owned cron hooks. Uninstall preserved every row byte-for-byte as returned by
   the database, all three owned options, and seven Administrator capabilities.
   Reactivation preserved the same six records, then the test plugin was deactivated.
2. **Delete:** in a new process with the plugin inactive, enabled the flag with
   boolean true. Seeded owned cron events, both documented cache transients, and
   owned capabilities on Editor as well as Administrator. Uninstall removed all
   six tables, three options, all owned capabilities across every role, all four
   owned scheduled hooks, and both documented transients.
3. **Isolation:** unrelated option, transient, scheduled hook, and Administrator
   `manage_options` capability survived deletion.
4. **Reactivation:** a third process successfully activated the still-present
   plugin files, recreated six empty tables, and restored seven Administrator
   capabilities. No final database error was present in any phase.

The six removed tables were `qa_scalyn_mail_logs`, `qa_scalyn_mail_timeline`,
`qa_scalyn_diagnostics`, `qa_scalyn_health_scores`, `qa_scalyn_alerts`, and
`qa_scalyn_audit_logs`. Their synthetic records were permanently deleted, not
moved to trash; the helper can regenerate the fixtures. The test database and
plugin files remain, with fresh empty plugin tables after reactivation.

## Limitations and follow-ups

- This exercises WordPress's uninstall handler, not filesystem removal through
  the Plugins UI or installation from a release ZIP. Plugin files were retained
  deliberately to test reactivation without filesystem deletion.
- Missing-flag behavior is covered by unit tests; the live retain phase used false.
- The delete gate casts values to boolean: truthy strings can enable deletion.
  Use a real boolean. There is no administrator confirmation UI in 0.1.0; this
  remains deferred under ADR-0002 and milestone 2, not implemented by this ticket.
- The handler is single-site only. Multisite and persistent external object-cache
  configurations were not tested.
- The test verifies the documented two cache transients, not exhaustive removal
  of every possible short-lived wizard transient.
- Test sentinels for unrelated state remain in the isolated installation.

## Validation

- Full PHPUnit: **673 tests, 1347 assertions passed**, including uninstall and
  lifecycle unit tests. Safe persistence error output is expected from fault tests.
- WPCS: `php vendor/bin/phpcs --standard=phpcs.xml.dist` passed, exit 0.
- PHP lint: **103 files passed**, including the local verification helper.
- `git diff --check` passed; the helper is confirmed Git-ignored.
- No schema changes, release actions, commits, or pushes were made.
