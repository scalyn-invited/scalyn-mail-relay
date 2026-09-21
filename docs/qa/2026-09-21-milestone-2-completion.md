# Milestone 2 completion evidence — 2026-09-21

## Scope and baseline

Bernie requested completion of all remaining Milestone 2 tickets. Tickets 1–4
already had local implementation and evidence. Tickets 5–8 add scheduling,
overlap protection/status, validated Data Controls, explicit uninstall
confirmation and consistent lifecycle hooks. The completion gate checks bounded
deletion, recent-record preservation and resumption.

Refreshed `origin/develop` to `9224047` (PR #43), then rebased the existing
`feature/m2-mail-retention` branch with autostash. Git restored the existing
uncommitted work without conflict. All milestone changes remain uncommitted on
that existing branch; separate ticket PRs have not been created. This combined
working-tree delivery follows the request to finish the milestone. Unrelated
documentation and the release PDF were preserved.

## Implementation evidence

- `RetentionService`: hourly scheduling, upgrade recovery, one 100-aggregate
  batch per history, one site-time cutoff per tick, partial progress and safe
  failure status, retry on later ticks.
- `RetentionStateRepository`: database/site-scoped connection lock and allowlisted
  status; no secrets, SQL or exception content persisted.
- `DataControlsPage`: capability and nonce gates, strict 1–3650 day validation,
  explicit additional confirmation to enable deletion, policy/status explanations.
- `SettingsRepository`: strict boolean uninstall policy and partial-save isolation.
- `ScheduledHooks`: shared activation/deactivation/uninstall hook ownership.
- See [ADR-0005](../adr/0005-retention-execution-and-controls.md) for contracts,
  security/privacy, batching, lifecycle and rollback considerations.

## WordPress and database verification

The ignored `build/clean-install-20260917/verify-m2-controls.php` boots this source
tree against the isolated `scalyn_clean_20260917` database with `qa_` prefix.
It verifies guards before seeding named synthetic fixtures. The helper passed:

- Real hourly scheduling, listener registration and idempotence.
- An independent database connection holding the lock prevents cleanup/status
  mutation; closing that connection releases it and permits the next run.
- 101 expired synthetic messages are removed in batches of 100 then 1.
- The recent synthetic message and timeline remain; expired timelines are removed.
- A real WordPress nonce-protected form rejects unconfirmed deletion, accepts
  separate explicit confirmation, and allows disabling the policy again.
- Deactivation clears retention and reactivation restores hourly scheduling.
- No database errors. Synthetic fixtures were removed in `finally`; isolated
  settings, cleanup status and cron were restored. No customer records were seeded
  or targeted by this verification.

Earlier real-database evidence remains relevant for grouped diagnostics,
strict cutoff preservation, transactional failure and cross-process resumption:
[ticket 3](2026-09-21-mail-retention-recovery.md) and
[ticket 4](2026-09-21-diagnostic-retention.md).

The working-site browser redirected to login, so authenticated visual browser QA
could not be completed in that expired session. Real WordPress server rendering
and POST handling passed in isolation; automated page tests check labels,
capabilities, nonce rejection, validation, confirmation and secret exclusion.

## Validation and review gates

Final automated validation passed:

- Full PHPUnit suite: **726 tests, 1,527 assertions**.
- WordPress Coding Standards: complete configured source set passed.
- PHP syntax: all **115** PHP files under includes/admin/tests plus plugin
  bootstrap and uninstall passed.
- `git diff --check`: passed.
- The real WordPress/database exercise above passed and its helper is Git-ignored.

Tests include legacy daily-schedule replacement, duplicate scheduling prevention,
busy/unavailable locks, safe SQL-selection failure status, rollback/resumption,
partial committed counts, full-batch backlog status, cutoff consistency, strict
settings validation, advanced partial saves, confirmation, capability/nonce
rejection, secret exclusion and retained/deleted uninstall status behavior.

Local database verification used MariaDB 10.4.32, below the supported 10.6 minimum.
Supported MySQL/MariaDB compatibility, packaged release QA, authenticated visual
and accessibility review, and Bernie's final review remain release gates.
Milestone implementation completion does not approve a release. Multisite,
external cron installation, alert/audit retention and historical score provenance
remain outside this milestone. No commit, push, PR, merge or release was performed.
