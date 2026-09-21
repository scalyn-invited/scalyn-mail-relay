# ADR-0004: Diagnostic and health-history retention boundaries

**Status:** Accepted for implementation; Bernie retains final architecture review.

**Date:** 2026-09-21

## Context

A diagnostic run persists several rows sharing `diagnostic_uuid`. Deleting rows
individually could leave an incomplete run and make evidence or scoring reads
misleading. Health-score snapshots have their own `score_uuid`, but schema 0.1.0
does not persist the diagnostic UUID or frozen mail-history inputs that produced
a score. Retention must not invent that missing correlation.

## Decision

`DiagnosticRetentionRepository` owns bounded deletion for both histories under
one transaction and one explicit site-time cutoff.

- A diagnostic run expires only when `MAX(created_at) < cutoff` for every row in
  its UUID group. A mixed-age run is retained in full.
- Expired groups are selected oldest first by `MIN(created_at), MIN(id)` and all
  currently stored rows in each selected group are locked before deletion.
- Diagnostic UUIDs are immutable per-run identifiers and must never be reused.
- Health snapshots expire independently using their own `created_at < cutoff`.
  This is explicit independence, not an inferred association to a run.
- One call selects at most 250 run groups and at most 250 health snapshots; the
  default limit is 100 for each history.
- Both deletes are transactional. Query failures, incomplete locks, unexpected
  diagnostic counts and health-count mismatches roll back with fixed safe errors.
- Callers own stable cutoff calculation, scheduling, overlap protection and
  operational status. No cleanup is automatically activated by this decision.

No schema or database-version change is introduced. Future score provenance may
add a run reference and policy/input metadata through a deliberate migration; it
must not retroactively guess links for existing rows.

## Consequences

Diagnostic evidence remains readable as complete run snapshots. Health history
is bounded without falsely claiming correlation. Repeated calls can continue
until both selected counts are zero, while records at the cutoff remain.

The repository relies on transactional tables in the supported MySQL/MariaDB
baseline. Scheduling and settings remain subsequent milestone tickets.
