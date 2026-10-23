# SMR-QA-07: recovery history composite cursor candidate

Status: included in draft integration PR #77; unit and isolated real-database
cursor regressions passed. Live installed-site behavior remains unverified. No UI
pagination is added.

## Calling contract

`RecoveryQueueRepository::summaries()` still returns at most 50 metadata-only rows ordered by `created_at DESC, logical_uuid DESC`. The array return shape and selected fields are unchanged.

For the first page, call `summaries()` without arguments. To continue without skipping equal-timestamp records, take BOTH values from the last returned row and call:

```php
$next = $repository->summaries( $last['created_at'], $last['logical_uuid'] );
```

The pair is an exclusive keyset boundary, not an offset or a snapshot. The query selects earlier timestamps OR equal timestamps with smaller logical UUIDs. The cursor need not reference a row that still exists. Concurrent insertions above the cursor will not appear on later pages; deletes/retention can remove rows. No cross-request snapshot consistency is promised.

## Compatibility and validation

Existing no-argument and valid timestamp-only calls retain their behavior. A timestamp-only call remains strictly `created_at < $before`, excluding all rows at that second; it cannot provide gap-free traversal of same-second rows. Callers needing stable traversal must provide the new optional second UUID argument. The current RecoveryPanel reads only the first page and is unchanged.

Both cursor components are validated before preparing/executing SQL. The timestamp must be an exact real UTC calendar value in `Y-m-d H:i:s` format, with four-digit year 1000–9999; impossible dates/times and extra suffixes are rejected rather than normalized. A supplied UUID must match the repository's canonical lowercase UUIDv4 validator. Empty UUID is invalid; null explicitly selects legacy time-only mode. Previously tolerated malformed timestamp strings now raise a fixed `InvalidArgumentException` without reflecting cursor values. All values remain prepared parameters.

Cursors and rows contain only metadata already returned by this repository: no payload, sender plan, credentials, subject, recipients, body or attachments are added. A cursor is not an authorization token; any future endpoint still requires appropriate access checks. No new endpoint or URL encoding contract is introduced.

## Validation boundary

The unit regression fixture evaluates the prepared comparison predicate in memory
and asserts the exact keyset SQL shape, bound arguments, metadata projection,
ordering and 50-row cap. The opt-in
`tests/integration/recovery-history-real-db.php` test uses WordPress 6.5 `wpdb`
against socket-only MariaDB 12.3.3: 61 persisted synthetic rows split into
50/11 pages without skips or duplicates, synthetic payload excluded from history,
legacy timestamp-exclusive behavior, and exact disposable-schema cleanup/readback.
It failed against the older `origin/develop` reader at the expected equal-
timestamp boundary. This is not a full WordPress installation, SQL-failure or
invalid-cursor real-DB test, MySQL version matrix or live staging retest.

QA-06's SQL-error distinction is included in the same integration candidate.
The recovery-history cursor change itself adds no migration, provider operation
or deployment. Jay decides integration; do not infer live acceptance from unit
coverage of the SQL shape.
