# ADR-0009: Atomic diagnostic publication

Date: 2026-09-21. Status: Implemented for Bernie's review.
Owner: Bernie. Scope: Milestone 4 ticket 3.

## Decision

DiagnosticPublicationRepository coordinates existing diagnostic/health repositories
and the deterministic scorer. Network checks finish before the transaction starts.
The shared run service retains its overlap lock through publication. A publication
accepts 1–20 distinct normalized check results and a generated UUID.

Both owned tables must report InnoDB in information_schema; errors, missing tables
or other engines fail closed without writes. No engine conversion is attempted.
The repository owns START TRANSACTION, exact-one-row inserts, readback by its UUID,
complete-set count verification, operational count reading, scoring, optional
snapshot insertion and COMMIT. Any failure requests ROLLBACK and throws a fixed
safe error. No raw SQL, database or check exception is exposed. No network work
occurs while this transaction is open. Like existing retention transactions,
this method requires ownership of the connection transaction: callers must not
invoke it from an enclosing transaction. Connection loss during commit can make
acknowledgement uncertain, but cannot intentionally publish only part of the set.

New run rows share one site-time publication timestamp. New health snapshots use
the same UUID as the run in the existing score_uuid column; this is a one-to-one
identity convention for new publications, not a backfill or schema change.
Legacy standalone HealthScoreRepository callers still generate an independent
UUID. Optional UUID/timestamp parameters preserve existing callers. Diagnostic
and health inserts now require exactly one affected row.

Scoring consumes the just-persisted complete set, including normalized isolated
check errors, and one observation of operational counts. Unknown/error checks
remain unknown/error under the existing scoring policy. If no component has
evidence, publish results with a null response score and no fabricated snapshot.
An older health snapshot is not deleted; its freshness presentation is ticket 5.
Historical operational count inputs are not newly persisted by this ticket.

Start audit events occur before publication; completed is emitted only after
commit, and failed follows rollback. Audit writes remain failure-isolated and
outside the publication transaction. A completed run can include failed checks.

## Retention, compatibility and rollback

InnoDB readers cannot see a partial new publication at normal WordPress isolation.
Retention sees either no new run or its committed full group. Identical timestamps
avoid per-row expiry drift. Existing policy still expires diagnostic groups and
health snapshots independently in bounded batches; a snapshot is not promised
permanent evidence linkage after retention. Legacy groups are not rewritten.
Read models fetching separate latest queries are not a single database snapshot;
UI freshness and consistent view selection remain later monitoring work.

No dbDelta migration, database-version bump, dependencies or capabilities.
InnoDB engine metadata must be readable. Supported MySQL 8/MariaDB 10.6 QA remains
a release gate. Repository/service/UUID conventions require Bernie's review.
Revert publisher wiring and optional repository arguments together to restore the
old path; no schema rollback. Already committed history and UUIDs remain valid.
