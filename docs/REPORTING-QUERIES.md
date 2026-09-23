# Milestone 6 ticket 1: reporting reads

Owner: Bernie. Based on merged develop `e96878a` (PR #47).

`ReportPeriod` validates strict site-local MySQL datetime boundaries. Reads use
start-inclusive/end-exclusive creation timestamps and permit at most 366 days
(up to 367 partial calendar dates). This matches existing writers; timestamps
are not reinterpreted as UTC. Historical timezone changes and DST ambiguity
cannot be reconstructed from these timezone-less records.

Existing table-owning repositories expose these internal read models:

- `MailLogRepository::activity_totals(period, provider)` returns total retained
  rows, counts for every canonical status, and unrecognized status counts.
- `MailLogRepository::report_failures(period, provider, limit)` returns latest
  failed rows by creation time/id, limited to 1–250. Only ID, UUID, provider and
  timestamps are selected, never response text or message content.
- `HealthScoreRepository::daily_trend(period)` returns site-wide daily snapshot
  counts, scored counts, average/min/max configuration scores and latest evidence
  time. Missing dates remain absent and unavailable scores remain null.

Provider null means all, empty string means unattributed, and other values are
exact provider identifiers (1–100 ASCII letters/digits/underscore/hyphen).
Health snapshots have no provider attribution; no provider filter is offered for
trends. Consumers must label them site-wide even when mail activity is filtered.

Counts describe retained current log outcomes grouped by creation time, not
immutable lifecycle events or lifetime totals. Retention may remove evidence.
Accepted is not delivered. Separate reads are not a transactional report snapshot;
snapshot consistency and report metadata belong to ticket 4. Missing data must
not be described as a healthy period. SQL errors throw fixed safe exceptions,
not fabricated zero activity.

No schema, container, shared interface, REST endpoint, UI or dependency changes.
Queries stay within their owning repositories. Future privileged callers must
enforce capabilities; this ticket exposes no new external access path. Date
windows/output bounds do not guarantee a fixed database execution time: aggregate
reads may scan retained history under existing indexes. Production-volume query
plans should inform any separately reviewed index migration.

Validation includes unit contracts and isolated real WordPress/database boundary
fixtures. No external email or webhook is needed. Rollback removes only these
unused read methods and the value object; no data migration is needed.

## Verification — 2026-09-23

- PHPUnit: **889 tests, 2,032 assertions passed**, including 16 new cases for
  invalid/bounded dates, leap-year limits, provider rejection, bounded private
  projections, unavailable scores and safe database-error handling.
- Full WPCS, Composer strict validation, PHP syntax (154 files) and diff checks
  passed. Final date-input hardening was additionally rechecked with WPCS/tests.
- Real isolated WordPress/MariaDB reads verified start inclusion/end exclusion,
  provider filtering, same-timestamp ID ordering, row limits, daily average/count
  and absent-day preservation. Synthetic rows were enclosed in a transaction and
  rollback was verified. No live site settings or external mail were changed.
- No Admin UI changed, so browser interaction was not required for this ticket.
  Bernie review and eventual PR/remote CI remain outstanding; local validation
  is not represented as remote CI evidence.
