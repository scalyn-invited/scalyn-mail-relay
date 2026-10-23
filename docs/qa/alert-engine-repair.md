# Local QA candidate: alert engine repair

Status: Local-only candidate, plugin 0.1.1-qa.2, schema 0.15.0. Bernie's review
and separately authorized staging retest are outstanding. No release approval.

## Scope

New versioned AlertEngineSchema converts only the current prefixed plugin alerts
table from MyISAM to InnoDB. It verifies engine metadata and DDL success before
Migrator persists the target version; existing InnoDB skips DDL. Fresh creation
explicitly requests InnoDB. The applied 0.7.0 migration and AlertRepository's
both-InnoDB guard remain unchanged. No provider, recovery, channel, scheduler,
capability, REST, retention, uninstall or mail semantics change.

## Local evidence

Evidence directory:
`/home/jayryan_ai/Projects/scalyn-local-artifacts/alert-engine-repair/`

- `unit-red.log`: upgrade regression failed with expected InnoDB / actual MyISAM.
- `db-red.log`: installed 0.14.0 real-DB fixture failed: alerts must be InnoDB.
- `fresh-red.log`: fresh-definition assertion failed because ENGINE was absent.
- `focused.log`: OK (65 tests, 231 assertions).
- `phpunit.log`: Tests: 1963, Assertions: 7711, Deprecations: 5; exit 0.
  Baseline was 1957/7669 with the same five PHP 8.5 reflection deprecations.
  Expected failure-fixture fixed-label log messages also occur at baseline.
- `wpcs.log`: no output, exit 0.
- `php-lint.log`: 381 tracked/new PHP files passed syntax checks.
- `javascript.log`: 52 tests, 52 pass, 0 fail.
- `diff-check.log`: no output, exit 0.
- `check-results.json` and `run-checks.py`: commands, exit codes and local replay.
- `static-scan.json`: no matches in the narrow added-line security scan; this is
  not proof of comprehensive security or secret detection.
- `db-upgrade.log`: PASS upgrade: 14 checks.
- `db-fresh.log`: PASS fresh: 12 checks.

PHP runtime: extracted PHP 8.5.12. Real database: isolated MariaDB 12.3.3,
Unix socket only, `@@skip_networking=1`; tests set session default MyISAM.
WordPress 6.5 wpdb/dbDelta are unmodified downloaded files, not mocked SQL.
Minimal admin/schema bootstrap shims and option adapter are used; this is not a
full installed WordPress site. See `tests/integration/README.md` for exact fixture
sources, hashes, prerequisites, commands and limitations.

The real-DB checks preserve a synthetic incident and index contract, leave an
unrelated MyISAM table untouched, confirm both alert tables use InnoDB, verify
stored version/no-op retry, complete local no-op exclusion work, and prove a
missing-table failure retains version 0.14.0 before a successful retry. Unit
fault injection covers metadata/ALTER errors, silent ineffective conversion,
missing/unsupported engine, and retry after failed version persistence.

## Boundaries and cleanup

No remote Git, push, PR, deployment, staging/cPanel mutation, credential operation,
mail/provider request, channel activation or recovery enablement was performed.
No live evaluation or notification delivery claim is made.

An early harness memory-exhaustion failure prevented `finally` cleanup, leaving
one dedicated local test schema: `scalyn_alert_engine_test_3dc1d3b292a8`.
A manual cleanup command was blocked by the tool's SQL DROP approval gate; no
bypass was attempted. Later successful test runs dropped their own exact schemas.
QA may clean up this dedicated leftover through an approved local DB workflow.

## Owner review, deployment and rollback

Bernie must review ADR 0041 and the migration before any schema/release action.
Back up structure/data first; assess table size, ALTER permission, InnoDB support,
free disk space and maintenance window. ALTER can lock/rebuild and implicitly
commits. Failed version persistence does not undo completed engine conversion;
retry skips already converted InnoDB. Code rollback may retain InnoDB with the
same row/column/index contract. Do not automatically downgrade the engine, lower
the schema version, or restore an old backup over newer activity.

Independent archive checks and exact ZIP hash/source revision are recorded in the
external artifact manifest/handoff after packaging; this document alone does not
assert a package exists. Full Plugins-UI install, WordPress option-cache behavior,
actual cron evaluation/notification flows, MySQL/supported-version matrix,
large-table locking and controlled staging retest remain unverified.
