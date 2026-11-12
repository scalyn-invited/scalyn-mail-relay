# ADR-0003: Mail-log retention boundaries and deletion contract

**Status:** Accepted for implementation; Bernie retains final architecture review.

**Date:** 2026-09-21

## Context

Mail logs and timeline events currently grow without bound. They form one logical
aggregate through `message_uuid`, but the schema deliberately has no foreign key.
Cleanup must be bounded, preserve recent evidence, avoid orphaned timeline rows,
and remain resumable without putting SQL in cron or Admin code.

## Decision

`MailRetentionRepository` owns deletion of the mail-log aggregate. Its public
contract accepts an explicit cutoff in WordPress site time plus a requested batch
size and returns only non-sensitive counts.

- `scalyn_mail_logs.created_at` is the authoritative age of the aggregate.
- Expiry is exclusive: `created_at < cutoff`. A row equal to the cutoff remains.
- Selection is oldest first by `(created_at, id)` and locked for the transaction.
- Requested batch size is clamped to 1–250 messages; the default is 100.
- Every timeline row for a selected `message_uuid` is deleted, regardless of its
  own timestamp, followed by the selected expired mail-log row.
- Selection and both deletes run in one database transaction. Any failed query or
  unexpected parent-row count rolls the batch back and raises a fixed safe error.
- Empty batches commit successfully and return zero counts.
- Callers own cutoff calculation, scheduling, overlap protection and operational
  status. Those are later milestone tickets and must not query the tables directly.

This ticket introduces no schema or database-version change. It relies on the
transactional table engine used by the supported MySQL/MariaDB baseline.

## Consequences

Cleanup can be repeatedly called until it selects no messages. Each successful
call has bounded locks and memory use. Recent parent rows cannot be deleted by
this contract, and related events cannot be left behind by a committed batch.

Orphan timeline rows that already lack a mail-log parent are not age-evidenced by
this contract and are not removed. Diagnostic results and health snapshots use
different grouping rules and remain for a later ticket. Scheduling is still off,
so adding the repository does not silently activate retention.

The timestamp boundary is deliberately supplied rather than computed inside the
repository. A future orchestration service must compute it once per cleanup run
from validated settings and pass that stable value to every batch.
