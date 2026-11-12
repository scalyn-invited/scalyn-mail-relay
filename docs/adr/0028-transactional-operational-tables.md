# ADR 0028: Repair legacy operational table storage engines

Date: 2026-10-05
Owner: Bernie
Status: Implemented; owner review pending

## Context

Foundation tables inherited the server default engine. A hosted installation used
MyISAM for diagnostics and health scores. Diagnostic publication correctly refused
to publish without transactions, producing the safe generic health-check error.
This was unrelated to HTTPS or the selected Postmark transport. Mail aggregates
and timelines also participate in transactional report/delivery operations.

## Decision

Database version 0.7.0 adds a dedicated migration for exactly four prefixed tables:
`scalyn_diagnostics`, `scalyn_health_scores`, `scalyn_mail_logs`, and
`scalyn_mail_timeline`. Applied column/index migrations remain unchanged.

Read each exact engine from information_schema. Skip InnoDB; convert MyISAM with
a prepared identifier and `ALTER TABLE ... ENGINE=InnoDB`, then verify the result.
Missing metadata, unsupported engines, query errors or an ineffective conversion
fail closed with a credential-free error. Do not advance the version on failure.
Already converted tables are skipped on retry. Fresh installs run this migration
after the existing dbDelta schema creation. dbDelta remains responsible for
columns and indexes; it cannot perform the engine conversion itself.

Do not weaken repository transaction guards or change unrelated WordPress tables.
No provider, REST, consent, retention or evidence interpretation changes are made.

## Operations and rollback

Back up structure and data before upgrade. Engine conversion can lock/rebuild large
tables, requires ALTER permission, available InnoDB and sufficient disk space; use
a maintenance window for large installations. DDL implicitly commits, so the four
conversions are not one atomic transaction. A failure may leave an intentionally
resumable partial conversion and the prior schema version.

A code rollback can retain InnoDB (the row/column/index contract is unchanged).
Do not automatically downgrade engines or restore an old backup over newer mail
activity. Any data restore needs a separately reviewed recovery plan.

## Validation

Regression tests cover the four-table allowlist, existing InnoDB no-op, successful
zero-row DDL return, partial failure/retry, missing or unsupported metadata,
metadata errors, and silently ineffective conversion. Existing schema upgrade
tests must still pass through the new version gate.

Live repair on 2026-10-05: exported structure and data for only the four approved
tables before conversion. phpMyAdmin then showed InnoDB with displayed row counts
100/18/5/5 unchanged. A fresh wizard health check completed and persisted a
Postmark configuration score at site time 03:12:03. This is not delivery evidence.
Live repair used scoped SQL; the new migration code has not been deployed there.

Local checks: 1,269 PHPUnit tests / 4,554 assertions, 240 PHP files linted,
WPCS, JavaScript syntax, seven JavaScript tests, and git diff whitespace checks
passed. Hosted CI has not been run for this uncommitted change.
