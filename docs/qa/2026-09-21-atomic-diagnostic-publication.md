# Milestone 4 ticket 3 verification

Owner: Bernie. Date: 2026-09-21.
Baseline: origin/develop 8b12a65, with uncommitted Milestone 4 tickets 1–2 preserved.
Branch: feature/m4-monitoring-orchestration. Not committed or CI-certified.

## Outcome

Shared manual/scheduled runs now publish a complete diagnostic group and any
computed health snapshot atomically through DiagnosticPublicationRepository.
New snapshots use the run UUID and the same publication timestamp. Isolated
check exceptions still become error results without aborting remaining checks.
The existing schema is unchanged. See [ADR-0009](../adr/0009-atomic-diagnostic-publication.md).

## Automated evidence

PHPUnit on PHP 8.2.12: 773 tests / 1717 assertions, no failures or deprecations.
Full WPCS passed. Tests cover complete commit, matching UUID/time, unknown/error
without invented score, every insert-failure position, previous-history
preservation, rollback and retry, begin/commit/read/count failures, non-InnoDB
rejection, empty/duplicate/oversized input, and a throwing check published
alongside the remaining checks. Existing tests remain passing.

PHP syntax passed for 130 files. Strict Composer validation and git diff --check
also passed.

## Real database evidence

Ignored helper build/clean-install-20260917/verify-m4-publication.php guards the
isolated database, prefix and paths. Two real database connections verified
that diagnostic and score rows remain invisible during each insert, all three
insert failure positions roll back fully, and a retry makes two results plus one
snapshot visible together. UUID/timestamp equality and preserved error status
passed. Only named synthetic fixtures were removed afterward. No email, live
client records or external probes were involved.

Local MariaDB 10.4.32 is below supported 10.6. Supported-engine compatibility,
packaged-candidate checks and Bernie review remain outstanding. No Admin markup
changed for this ticket. The earlier monitoring helper's outer-transaction
strategy is not suitable for the new transaction-owning publisher; use the
dedicated publication verification instead.

## Remaining work

Ticket 4 adds durable run timestamps/failure state; ticket 5 adds freshness and
overdue UI. Independent retention and old standalone health snapshots remain
supported. No claim of complete Milestone 4 or release approval.
