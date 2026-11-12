# ADR-0010: Bounded diagnostic execution status

Date: 2026-09-21. Status: Implemented for Bernie's review.
Owner: Bernie. Scope: Milestone 4 ticket 4.

## Decision

DiagnosticRunStateRepository owns the non-autoloaded option
scalyn_mail_relay_diagnostic_run_status. It stores at most five records: latest
attempt, latest scheduled attempt, last committed completion, last scheduled
completion, and the previous unfinished attempt. Each contains only a UUID,
allowlisted runtime source, running/completed/failed state, UTC Unix start/finish
timestamps and a fixed failure code. No actors, credentials, payloads, exception
text, network evidence or database errors are stored here.

The shared run service acquires its existing lock before reading/mutating status.
Overlap or unavailable-lock responses remain 409 and do not overwrite active
status. A start marker must persist before executing checks; failure returns a
safe 503 and performs no diagnostics. Terminal outcome is UUID-guarded. Fixed
failure codes distinguish context_failed, checks_failed and publication_failed;
they identify the execution stage, not a speculative root cause.

Completed means the publication transaction committed, not that all checks passed
or a score was available. Last success is execution success, not email health.
Manual execution cannot advance scheduled freshness. State writes happen outside
the publication transaction. A failed terminal status write leaves the running
marker, emits a fixed warning and does not mislabel committed results as failed.

Uncaught termination or power loss can leave a start without a finish. A running
marker is therefore completion-unconfirmed, not proof of current activity or
failure. When the next lock owner starts, it preserves that predecessor in one
bounded slot. No fabricated end time or automatic failed outcome is assigned.
Commit acknowledgement loss can likewise leave uncertain evidence. Reading state
does not mutate it. Future ticket 5 UI must explain these distinctions.

## Privacy, retention and lifecycle

Both reads and writes enforce the fixed record shape. Unknown fields are dropped;
invalid records and incompatible slot states are null. UTC timestamps do not
change meaning with site timezone settings. Clock rollback is clamped so finish
cannot precede start; timestamps are not a high-precision duration metric.

This is bounded control metadata, not an append-only execution history. New runs
replace the relevant slots; old last-success pointers can outlive history expiry
and must not imply their evidence is still retained. Status is preserved on
deactivation/default uninstall, deleted on explicit destructive uninstall, and
does not add a new cron hook. No table/schema migration or database version bump.

The option/REST 503/service changes require Bernie's review. For rollback, revert
the state wiring and keep or remove the unused option deliberately; diagnostic
history needs no migration. Ticket 5 is responsible for the Admin read model and
freshness/overdue presentation; no new public status endpoint is introduced here.
