# Local QA candidate: alert engine repair

Status: plugin 0.1.1-qa.2 / schema 0.15.0 candidate in draft PR #77. Jay's
integration/release decision and separately authorized staging retest remain
outstanding. No release approval.

## Scope

New versioned AlertEngineSchema converts only the current prefixed plugin alerts
table from MyISAM to InnoDB. It verifies engine metadata and DDL success before
Migrator persists the target version; existing InnoDB skips DDL. Fresh creation
explicitly requests InnoDB. The applied 0.7.0 migration and AlertRepository's
both-InnoDB guard remain unchanged. This alert-engine repair alone changes no
provider, recovery, channel, scheduler, capability, REST, retention, uninstall or
mail semantics; the broader PR #77 also integrates SMR-QA-03–07 changes.

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

At the time of the first ZIP verification, no remote Git, deployment,
staging/cPanel mutation, mail/provider request, channel activation or recovery
enablement was performed. The source was later committed and pushed in draft
PR #77; that action did not install the ZIP or send mail.
No live evaluation or notification delivery claim is made.

An early harness memory-exhaustion failure prevented `finally` cleanup, leaving
one dedicated local test schema: `scalyn_alert_engine_test_3dc1d3b292a8`.
A manual cleanup command was blocked by the tool's SQL DROP approval gate; no
bypass was attempted. Later successful test runs dropped their own exact schemas.
QA may clean up this dedicated leftover through an approved local DB workflow.

## Owner review, deployment and rollback

Jay must decide the migration and staging safety plan before installation.
See the [staging acceptance preflight](staging-acceptance-preflight.md) for the
operator's concrete go/no-go, safeguards and read-only evidence sequence.
Back up structure/data first; assess table size, ALTER permission, InnoDB support,
free disk space and maintenance window. ALTER can lock/rebuild and implicitly
commits. Failed version persistence does not undo completed engine conversion;
retry skips already converted InnoDB. Code rollback may retain InnoDB with the
same row/column/index contract. Do not automatically downgrade the engine, lower
the schema version, or restore an old backup over newer activity.

The verified pre-documentation-update ZIP is
`/home/jayryan_ai/Projects/scalyn-local-artifacts/alert-engine-repair/scalyn-mail-relay-0.1.1-qa.2-tree-433c07290250.zip`,
SHA-256 `3f7a5d8305c9c5a989c96dcbe7e5a6af7999c03712b2d141b7011f3583161dd7`.
It matches commit `d7e637b10ca508a74c7a23ff1de68878f014bec0`'s packaged
source; subsequent PR documentation and opt-in test updates are not in that ZIP
but do not change executable plugin bytes. Full Plugins-UI installation,
WordPress option-cache behavior, actual cron evaluation/notification flows,
MySQL/supported-version matrix, large-table locking and controlled staging
retest remain unverified.
