# Milestone 4 tickets 1–2 verification

Owner: Bernie. Date: 2026-09-21.
Baseline: origin/develop 8b12a65, merged PR #45.
Branch: feature/m4-monitoring-orchestration. Local changes, not committed.

## Implementation

- Shared DiagnosticRunService for manual REST and scheduled adapters.
- Opt-in Data Controls cadence, disabled/hourly/twicedaily/daily.
- Nonblocking connection-owned run lock, same-process re-entry guard.
- One run per tick, max 20 checks and cooperative monotonic 20-second check budget.
- Audit UUID correlation, existing safe context, own-run scoring read.
- Activation/init reconciliation and existing deactivation/uninstall hook cleanup.

See [ADR-0008](../adr/0008-shared-diagnostic-orchestration-and-scheduling.md).

## Evidence

PHPUnit: 765 tests, 1669 assertions passed on PHP 8.2.12. Covers shared scheduled
execution, cron audit attribution, REST overlap conflict, failure/retry lock
release, own-UUID reads, bounds/deadline, schedule defaults/validation/change/
disable/idempotency/retry, form validation and lifecycle cleanup. Existing nonce,
capability, privacy and isolated-check tests remain passing.

PHP lint passed for 127 source/test files. Full WPCS, strict Composer validation
and git diff --check passed; the final container registration also passed WPCS
and the full test suite after its last change.

Isolated WordPress helper build/clean-install-20260917/verify-m4-foundation.php
checks the database name, prefix and installation/source paths before fixtures.
It passed five-result persistence, two-connection database lock exclusion/release,
all three cadences and idempotency, scheduled adapter execution, disable/stale
callback behavior and real Data Controls rendering. Synthetic checks replaced
network probes; no email was sent. The fixture transaction was rolled back.
An initial duplicate cron-constant warning in the helper was corrected; rerun
passed without that warning.

## Limits and review

No authenticated browser or actual low-traffic cron timing certification in this
ticket. Local MariaDB 10.4.32 remains below supported 10.6; supported-engine and
packaged-candidate QA remain release gates. Remote CI has not run for this tree.
This does not complete Milestone 4: transactional publication, execution status,
freshness/overdue UI and operational cron verification remain tickets 3 onward.
Bernie's architecture and implementation review is outstanding.
