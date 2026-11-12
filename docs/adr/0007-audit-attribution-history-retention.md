# ADR-0007: Audit attribution, history and retention

Status: Implemented for Bernie's review. Date: 2026-09-21.

## Decision

Extends ADR-0006 for Milestone 3 tickets 3–6. No schema migration, new dependency,
REST endpoint or capability is introduced. Bernie owns architecture and review.

AuditActor captures the WordPress user ID when an event is created, not when it
is persisted. Execution context priority is scheduled, CLI, REST, authenticated
Admin (manual), then application. Scheduled events have user ID zero. Submitted
actor/source fields are ignored. Context is not proof of human intent; IDs can
outlive deleted accounts. Metadata version 2 adds the allowlisted source;
version 1 records retain unknown attribution. Scheduled diagnostics themselves
remain Milestone 4 work.

AuditRepository owns reads and retention as well as append-only event capture.
Reads fetch at most 51 rows, display 50 and use an exclusive ID cursor. Every row
is projected through allowlists; malformed or unsupported metadata is displayed
as unknown, never as raw JSON. Admin requires MANAGE_SETTINGS before reading.
The read-only cursor does not mutate data and needs no nonce. Rendering escapes
all fields and provides scoped table headers and labelled pagination.

Only action, outcome, field names, UUID, time, numeric actor and execution context
are exposed. No setting values, credentials, headers, bodies, recipient addresses,
IP addresses or user agents are captured. This is operational traceability, not
a tamper-proof security ledger. Failure-isolated writes can leave gaps. Accepted
still means provider acknowledgement, not inbox delivery.

The existing Data Controls period (default 30, range 1–3650 days) also applies to
audit rows. Under the shared retention execution lock, a repository transaction
locks and deletes at most 100 oldest rows strictly before the site-time cutoff.
Exact-cutoff and newer rows survive. Query errors, short deletes or failed commits
fail the batch and request rollback. Full batches resume on later hourly ticks.
Status includes audit_rows; earlier successful categories remain reported if a
later category fails. Cleanup does not recursively generate audit events.

Audit expiry is individual, not grouped by UUID: one part of an operation may
expire before another. The UI explains this. Append-only semantics apply until
policy expiry or explicitly enabled destructive uninstall. Lifecycle hooks and
uninstall policy are otherwise unchanged.

## Consequences and rollback

Actor IDs are minimal personal data, subject to the same retention. Existing
history is not retroactively attributed. No guaranteed complete trail or delivery
claim can be inferred from missing outcomes. Low-traffic WP-Cron can delay expiry.
Supported database and authenticated browser/accessibility QA remain release gates.

Revert the new wiring, view and actor/retention extension together to stop this
behavior; no schema rollback is required. Already expired rows cannot be restored
without backups. Older readers may not understand version 2 metadata.
