# ADR-0013: Consistent in-memory report snapshots

Date: 2026-09-23. Status: Implemented for Bernie's review.

## Decision

Introduce a versioned readonly report value and a lazy shared
`ReportSnapshotRepository`, composed from the existing mail, health and diagnostic
repositories plus the recommendation engine. Admin/export consumers must use this
service rather than reading tables. Existing interfaces and schema are unchanged.

Capture all reporting reads in one InnoDB read-only REPEATABLE READ transaction
with a consistent snapshot. Verify participating table engines first. Use
`SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` before START: it fails when a
transaction is active, so capture does not implicitly commit a caller's work.
Only roll back a transaction successfully started by the coordinator. Return a
fixed safe error on failure; never expose SQL or underlying exceptions.

The repository owns the shared connection during capture. Callers must not wrap
capture in a transaction or arrange re-entrant database operations. No session
default isolation level is changed. Future alternate DB adapters must provide the
same consistent-read semantics or fail closed, not silently degrade to separate
reads. WordPress-compatible MySQL/MariaDB InnoDB is the current supported path.

The snapshot persists only as an in-memory value for the request. Later export
formats consume the same value. Durable report files, storage, audit, permissions
and expiry are separate tickets; this decision does not authorize a public API.

## Rationale and consequences

Independent reads can disagree when mail outcomes update or retention deletes
evidence. A consistent read avoids that without locking normal mail writes or
introducing a new report schema. Date and output bounds remain; aggregation can
still be expensive and long-running read views can delay InnoDB undo cleanup.

Evidence is allowlisted metadata with explicit row/run/message references.
Free-text/raw payloads are excluded. Captures preserve the reporting period,
timezone, evaluation timestamp, partial-history caveats and the distinction
between Accepted and delivery. Diagnostic/health data is labelled site-wide and
not incorrectly attributed to a selected mail provider.

Selected findings are limited to the latest retained run in the period. Oversize
and boundary-spanning runs fail closed. Previously deleted rows cannot be detected
as complete history; absence is never converted to success. Legacy score and run
references remain independent. No schema, capability, retention or lifecycle
change; rollback removes only the new read contract and wiring.

Contract details and validation: [Report snapshots](../REPORT-SNAPSHOTS.md).
