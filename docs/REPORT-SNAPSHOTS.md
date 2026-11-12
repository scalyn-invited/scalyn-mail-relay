# Milestone 6 ticket 4: report snapshots

Owner: Bernie. Baseline: merged develop `00abca1` (PR #50).

`ReportSnapshotRepository::capture(ReportPeriod, ?provider, cadence)` is a lazy,
shared internal service. It returns `ReportSnapshot` with a readonly `data` array.
Consumers reuse this instance rather than rerunning queries for each section or
format. Captures are in-memory only: no files, report tables, endpoints, UI,
scheduled work or message sending are introduced.

## Version 1 contract

| Field | Meaning |
| --- | --- |
| `version`, `report_uuid` | Contract version and unique capture reference. Not a stored lookup token. |
| `generated_at_utc` | UTC time at the beginning of capture, ISO 8601. |
| `period` | Inclusive start, exclusive end and current WordPress timezone name. Stored site-local boundaries, maximum 366 days. |
| `mail` | Exact provider filter, retained current-outcome totals, latest 25 failures, limit and omitted failure count. Null provider means all; empty string means unattributed. |
| `health` | Explicitly site-wide daily aggregates and latest score in the period, or null. No missing-day zero fill. |
| `diagnostics` | Explicitly site-wide latest retained run selected by newest row in the period; at most 250 retained findings. |
| `recommendations` | Existing rule engine's refresh flag and ordered safe guidance from those findings. |
| `freshness` | UTC evaluation time and supplied cadence. Historical findings are assessed at capture time, not made artificially fresh at period end. |
| `limitations` | Mandatory caveats to preserve in downstream report formats. |

Evidence references live beside the captured values: failure row ID/message UUID,
finding row ID/run UUID/check name, and latest score row ID/score UUID, each with
its stored timestamp. Daily trend rows contain date, counts, average/min/max and
latest observation time, not an unbounded list of every contributing UUID.
Database scalar metadata retains the owning read model's types; counts and trend
aggregates use their existing normalized numeric types. Exporters must not infer
meaning from numeric strings versus integers.

The latest health score and latest diagnostic run are selected independently;
their references are not assumed to correlate. New publications normally share a
UUID, but legacy rows and independent retention can break that correspondence.
Findings are check metadata/status/severity/score, not raw DNS/provider payloads.
Impact and remediation wording comes only from the reviewed recommendation rules.
Unknown remains unknown; absent scores remain null. Stale findings remain visible
as historical evidence while guidance requests fresh diagnostics.

## Consistency and limits

All three tables must be InnoDB. The coordinator opens a read-only REPEATABLE READ
consistent snapshot, calls only owning repositories, and commits after capture.
Concurrent writes/retention cannot change later sections within that capture.
An existing transaction is rejected without committing or rolling it back.
Read, engine, transaction or commit failures return a fixed safe exception,
never partial data or fabricated zeros. See [ADR-0013](adr/0013-report-snapshots.md).

A selected diagnostic run with more than 250 retained rows, or any retained row
outside the selected period, is rejected instead of silently truncating it.
The schema has no original run-row count: a previously partially retained run
cannot be proven complete. The limitation text makes this explicit. No claim of
full history, authenticated messages, confirmed delivery or inbox placement is
made. Counts describe retained current outcomes by message creation date.

Output is bounded, but aggregate SQL can scan retained history. Long periods on
large installations can hold an InnoDB read view and increase undo retention;
avoid capturing repeatedly within one export. No index/schema change is included.
Historical timezone changes and DST ambiguity cannot be reconstructed from
existing timezone-less timestamps.

## Security, callers and follow-on work

No recipient, subject, body, response text, raw diagnostic JSON, free-text result
or health summary is selected. References and provider metadata are still
operational data. Future CSV/JSON/PDF entry points must enforce capabilities,
request validation and nonce checks where applicable, escape output for their
format, protect CSV formulas, preserve limitations, and implement export audit
and file expiry. This internal contract is not an authorization boundary.

Tickets 5–7 implement those export workflows; this ticket adds no download button.
No schema migration or new dependency. Rollback removes the new contract,
repository reads and container registrations; no stored report data needs cleanup.

## Verification — 2026-09-23

- PHPUnit: **935 tests, 2,166 assertions passed** (17 new cases).
- Full WPCS, PHP syntax checks (163 files), Composer strict validation and
  `git diff --check` passed.

Focused unit coverage exercises empty/unknown evidence, immutability, provider
scope, omitted failures, private projections, historical/fresh recommendations,
oversized/boundary-spanning runs, database failures, engine rejection, transaction
failure and lazy shared-service resolution.

Isolated real WordPress/MariaDB QA used two database connections. A concurrent
mail outcome update and score/finding deletion committed between report reads:
the first capture retained its original totals, failures, score/trend and finding
references; the next capture saw the new state. Nested capture was rejected
without committing or rolling back the caller's fixture. Private sentinel text
was excluded, and exact synthetic fixtures were removed afterward. No live site
settings, customer rows, UI or external mail were changed.

Bernie's contract review remains outstanding. Local checks are not remote CI.
