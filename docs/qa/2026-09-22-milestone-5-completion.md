# Milestone 5 implementation and verification

Date: 2026-09-22. Owner: Bernie. Branch: `feature/m5-alerts-recovery`.
Baseline: merged `origin/develop` at `fec3d4f` (Milestone 4, PR #46).

## Implemented scope

- Fixed tri-state rules for repeated send failures, scheduled monitoring failures,
  and health degradation. Unknown/stale observations never imply recovery.
- Atomic incident/outbox transitions, site/database connection locking,
  deduplication, bounded reopen cooldown, resolution and recovery cancellation.
- Opt-in independent HTTPS webhook with server-only URL/token configuration,
  safe HTTP, stable idempotency UUID, bounded attempts and no response storage.
- Capability/nonce-protected Alerts & Recovery screen, safe cursor history,
  last evaluation status, fixed remediation text and notification outcome/count.
- Allowlisted incident/notification/configuration audit events.
- Schema 0.2.0 outbox migration, activation/Admin upgrade, scheduled hook cleanup,
  resolved incident/outbox retention and explicit uninstall coverage.

Architecture/privacy contract: [ADR-0012](../adr/0012-alerts-and-webhook-outbox.md).
Setup and limitations: [operator guide](../ALERTS-AND-RECOVERY.md).

## Automated checks

- PHPUnit: **848 tests, 1,928 assertions passed**.
- WPCS: passed with no errors or warnings.
- `composer validate --strict`: passed.
- `git diff --check`: passed.
- PHP syntax checks: **147 source/test files passed** (vendor/build excluded).

New unit coverage exercises failure-window boundaries, intervening Accepted
records, health hysteresis/freshness, disabled/missing/overdue monitoring,
epoch/site-time conversion, transaction rollback, exclusion, deduplication,
cooldown, recovery cancellation, retention failure, bounded retry exhaustion,
interrupted final attempts, failed claims preventing HTTP, disabled channels,
audit outcomes, safe historical projections, strict settings, capability/nonce
gates, webhook allowlisting, configuration rejection and bounded transport.
Existing activation/uninstall tests include the alert tick, outbox and status.
Expected failure-injection logging in PHPUnit is not a failing check.

## Isolated WordPress/database verification

The local CLI helper `build/clean-install-20260917/verify-m5-alerts.php` enforces
CLI-only execution, the isolated WordPress path, database `scalyn_clean_20260917`,
prefix `qa_`, source-plugin identity and disabled automatic cron. It requires an
empty incident table, creates synthetic UUID fixtures and removes only those
fixtures afterward. Settings/status/cron options are restored. The isolated
schema upgrade to 0.2.0 remains installed; the additive migration is intentional.

Verified against real WordPress/wpdb and independent database connections:

1. Initial and repeated `dbDelta()` upgrade; required outbox table/version.
2. A second connection cannot enter a held alert lock.
3. Independent readers cannot see a partial incident/outbox transaction.
4. Injected outbox insertion failure rolls back the incident.
5. Registered alert hook observes a low health score and queues the first attempt.
6. Repeated observations create no second incident/job or premature retry.
7. Simulated HTTP 503 reaches exactly three attempts with the same notification UUID.
8. A fresh score of 80 resolves the incident and sends an event-specific recovery.
9. Reopening queues behind cooldown; recovery cancels that unsent opening.
10. Resolved retention removes both incident and notification records together.
11. Incident/notification audit entries and last evaluation completion are present.
12. Safe HTTP flags, disabled redirects and omission of token/receiver body are checked.

All HTTP calls were intercepted with synthetic 503/204 responses. **No external
webhook or email was sent.** The first helper run emitted a duplicate cron-constant
warning from the harness; the redundant definition was removed before rerunning.
This is local evidence on PHP 8.2.12 / MariaDB 10.4.32, not proof for the supported
database deployment matrix or a production receiver.

## UI review

Browser-reviewed a static rendering of the actual alert view with WordPress
styles, synthetic active/resolved incidents and failed/successful notification
states. Verified headings, labelled opt-in control, readable history, UTC labels,
provider-acceptance disclaimer and configuration/cron limitations. No live Admin
form was submitted during this review. Capability/nonce enforcement and the empty
state are tested in PHPUnit. Full authenticated production/browser QA remains a
release check; the static fixture is not represented as that check.

## Release/owner gates

- Bernie still reviews architecture, fixed thresholds, schema and implementation.
- Configure and verify an actual receiver before enabling notifications.
- Supply an external cron runner/uptime monitor for unattended production use.
- Validate the supported PHP/database matrix in CI/release QA; no M5 PR or remote
  CI run was created by this implementation task.
- Additive-schema rollback/deactivation instructions are in the operator guide.
- Unrelated local release-readiness PDF was left untouched.
