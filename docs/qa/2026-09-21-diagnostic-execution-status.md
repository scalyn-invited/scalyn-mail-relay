# Milestone 4 ticket 4 verification

Owner: Bernie. Date: 2026-09-21.
Baseline: origin/develop 8b12a65; uncommitted tickets 1–3 preserved on
feature/m4-monitoring-orchestration. This ticket remains uncommitted.

## Delivered

Durable start/finish timestamps, execution source and fixed failure-stage codes
for shared manual/scheduled runs. Scheduled freshness is tracked separately.
Unfinished markers survive process termination without inventing an outcome.
Status-write failure and overlap semantics are documented in
[ADR-0010](../adr/0010-diagnostic-execution-status.md).

## Tests

PHPUnit on PHP 8.2.12: 783 tests / 1771 assertions passed. Full WPCS passed.
PHP lint passed for 132 files; strict Composer validation and git diff --check passed.
Coverage includes start/completion UUID and UTC timestamps; context, check-budget
and publication failures; prior-success preservation; separate scheduled state;
unfinished predecessors; wrong-UUID rejection; strict payload projection; initial
write failure preventing checks; final write failure preserving committed results;
and retained versus explicitly deleted status on uninstall.

The isolated helper build/clean-install-20260917/verify-m4-state.php ran in two
separate PHP processes. The second read the unresolved start and preceding
scheduled success from WordPress options, then verified predecessor capture and
safe failure completion without altering scheduled freshness. Database name,
prefix and source/install paths were guarded. Previous status was restored and
the temporary fixture backup removed. No email or external probe was sent.

## Remaining scope

Ticket 5 implements the Admin display, freshness and overdue/failed monitoring.
No UI changes or browser QA are claimed for this backend-only ticket. A completed
execution is not a healthy diagnosis or proof of inbox delivery. An unconfirmed
marker is not asserted to be a failed run. Status pointers may outlive retained
evidence. Supported database compatibility (local MariaDB is 10.4.32, below the
10.6 requirement), packaged-candidate QA and Bernie review remain release gates.
Remote CI has not run on the uncommitted candidate.
