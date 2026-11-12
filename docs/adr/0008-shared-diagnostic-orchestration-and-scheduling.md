# ADR-0008: Shared diagnostic orchestration and opt-in scheduling

Date: 2026-09-21. Status: Implemented for Bernie's review.
Owner: Bernie. Scope: Milestone 4 tickets 1 and 2.

Publication deferrals below are superseded by [ADR-0009](0009-atomic-diagnostic-publication.md).

## Decision

DiagnosticRunService owns context construction, isolated check execution,
UUID-correlated persistence, scoring and safe audit outcomes. REST and cron are
adapters to the same lazy container service. The REST permission callback remains
RUN_DIAGNOSTICS; existing success/error bodies remain, with HTTP 409 for a busy
or unavailable execution lock. Scoring reads this run's UUID, not latest globally.

DiagnosticRunLock owns a nonblocking, database/site-scoped GET_LOCK. Both adapters
use it before any check or write. Process-local guards prevent MySQL recursive
lock acquisition on the same connection; finally releases the lock after success
or failure. Connection loss/process death releases the database lock. Acquisition
errors fail closed; uncertain release blocks further acquisition in that process.
This is distinct from the retention lock; atomic publication/retention interaction
is part of ticket 3, not claimed solved here.

DiagnosticSchedule reconciles one existing owned hook on init and activation.
Data Controls adds a capability/nonce-protected cadence setting, validated against
disabled/hourly/twicedaily/daily. Default is disabled, including malformed stored
values. Changes clear the prior schedule and begin a fresh interval; disabled
callbacks do no work. Reconciliation is idempotent and failed scheduling can retry
on a later request. The form reports settings/scheduling application failures.
Deactivation and both uninstall policies clear the hook via ScheduledHooks.
No new option or schema is needed: advanced.diagnostic_schedule lives in the
existing settings option and is an allowlisted audit field (name only).

One tick attempts one run, never loops or backfills. Overlap skips that attempt.
The runner accepts at most 20 checks and uses a monotonic 20-second cooperative
budget, checked before each check and after execution. Exceeding either bound
fails safely before persisting check results; skipped checks are not a pass.
An active blocking DNS/socket call cannot be preempted; the budget is not a hard
wall-clock deadline and excludes database persistence time. Existing transport
timeouts still apply. A hard deadline would require a separate worker model.

## Privacy and dependencies

No new packages, database migration, capabilities, email sends or credentials
are introduced. Scheduled checks use the credential-free context builder and
existing bounded SMTP/DNS probes. Scheduling is opt-in because it initiates
network probes. Cron audit context remains scheduled/unattributed; it is not
assigned to the user who configured the schedule.

## Remaining work and rollback

Ticket 3 must address atomic grouped result/health publication and persistence
failure consistency; partial writes remain possible today. Tickets 4–6 cover
durable execution timestamps/failure status, overdue UI and operational cron QA.
WP-Cron relies on traffic and does not guarantee exact cadence. User-facing
monitoring freshness must not be inferred from the mere presence of a schedule.

Disable monitoring to stop future ticks without deleting evidence. For rollback,
disable it first, then revert these service/adapter changes together; no migration
rollback is needed. Already running work may finish. Existing historical hook
cleanup remains compatible with prior code. Bernie reviews the shared service,
REST 409, runner limits and settings/audit contract extensions before merge.
