# Provider health failure categories - 2026-10-07

Owner: Bernie. Branch: feature/m8-health-failure-categories.
Baseline: develop 71a12bd (PR #73 merged).

## Implementation

Approved schema 0.10.0 adds nullable failure_category columns to mail logs and
connection evidence. Connection results gain an optional category. No old
response text is interpreted or backfilled. Allowlisted categories feed
current-revision Critical (fresh auth/config connection failure or latest three
consecutive auth/config failed submissions in 24 hours) and Warning (definite
provider rejection/rate limiting in 24 hours) rules. Independent existing
submission-rate and bounce rules remain active.

Unknown and stale are not inferred failures. Missing category reads and
unclassified failures cannot establish Healthy. A sequence includes unknown
and unconfirmed outcomes as breaks, rather than skipping them. No automatic
retry is added; no credentials/settings changed or mail sent.

## Verification

- Focused tests cover migration failure/retry/idempotency, nullable legacy data,
  backward-compatible writes, allowlisted persistence, success/unconfirmed
  exclusion, late-result ordering, stale removal, provider mappings, streak
  breaks, minimum-three boundary, Warning/Critical precedence and missing data.
- Actual local dbDelta + MariaDB smoke passed. Disposable tables only, SHORTINIT
  with no plugin boot. Legacy rows stayed NULL; the newer successful check
  cleared its category; late old failures did not win. Scope, dates and sequence
  checks passed. Disposable tables removed.
- Run: php tests/manual/failure-category-smoke.php.
- Full PHPUnit: 1,521 tests / 5,782 assertions passed.
- JavaScript: 7 tests passed. PHP syntax: 291 files passed.
- Full WordPress Coding Standards and git diff whitespace checks passed.

## Controlled QA still needed

Use approved staging only, with restore-capable backup before applying schema:
1. Confirm database upgrade to 0.10.0 and ordinary sending/logging still work.
2. Run a connection check with intentionally invalid test credentials. A definite
   auth/config observation should show Critical with actionable guidance.
   Generic network errors should not be labelled auth/config.
3. Restore valid credentials and verify: the new configuration must not inherit
   the old failed check or submission streak.
4. Use controlled fixtures for three auth/config failures, a breaking Accepted
   or unconfirmed outcome, a single provider rejection, and a rate limit.
   Do not deliberately throttle an external account or send customer test mail.
5. Verify new explanations in the active provider card on desktop/mobile.
   Previous slice's visual QA does not validate these new explanations.
6. Complete remaining SMTP2GO/Brevo live webhook delivery/bounce/retry QA separately.

## Release and rollback

No PR/CI or live transport QA is claimed here. Owner review remains pending.
Revert code to roll back; retain additive nullable columns and database version.
Existing row retention/uninstall policy covers both categories. Never backfill
historical failures. Deliverability Score remains separate.
