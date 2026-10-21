# SMR-QA-07: recovery history composite cursor candidate

Status: local source candidate; pending independent QA and Bernie's contract/integration review. No UI pagination is added.

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

The regression fixture evaluates the prepared comparison predicate in memory and asserts the exact keyset SQL shape, bound arguments, metadata projection, ordering and 50-row cap. It is NOT an actual MySQL/WordPress execution test. Test equal-timestamp pages on a disposable WordPress/database environment before runtime acceptance.

QA-06's SQL-error distinction is a separate uncommitted branch under independent review; it is intentionally not bundled into this patch. Bernie must decide integration and review combined behavior before merge. No migrations, provider operations or deployment are part of this change.
