# Milestone 3 tickets 1–2 verification

Historical foundation evidence. Later attribution, UI and retention work is
verified in [Milestone 3 completion](2026-09-21-milestone-3-completion.md).

Based on merged `origin/develop` commit `513d02e` (PR #44), refreshed before work.
Branch: `feature/m3-audit-foundation`. Bernie owns implementation and review.

## Implemented

- Immutable allowlisted AuditEvent contract, append-only AuditRepository and
  failure-isolated AuditRecorder, registered in the shared container.
- Settings changes after successful persistence, including separate retention
  and uninstall-policy events. Only changed field names are stored; values and
  credential masks/hashes are never captured. No-op/invalid/failed saves produce
  no change event; failed-save retries preserve change detection.
- Correlated wizard verification and test-email start/result events; test success
  means accepted, never delivered. Existing capability and nonce gates remain.
- Diagnostic start/completion/failure events with the persisted run UUID.
- Existing audit schema reused without migration, dependencies or new endpoints.

See [ADR-0006](../adr/0006-audit-event-foundation.md) for event vocabulary,
security/privacy, failure semantics, retention boundaries and rollback.

## Verification

Automated tests exercise unknown actions/outcomes/identifiers/metadata rejection,
field-name-only capture, blank-password/no-op behavior, failure isolation,
save failure/retry, wizard result correlation, nonce rejection, test-email privacy,
and diagnostic completion/failure correlation. The UUID stub now produces valid
UUID-shaped values to match WordPress rather than weakening production validation.

The ignored `build/clean-install-20260917/verify-m3-audit.php` booted the source
plugin against guarded isolated database `scalyn_clean_20260917`, prefix `qa_`.
Real settings hooks plus six synthetic operation events appended eight rows.
Checks confirmed correlation, the exact metadata allowlist, absence of settings
values, empty IP/user-agent fields, and no database error. Fixtures were removed
and settings restored in `finally`. No email was sent and no working-site history
was targeted. This verifies real persistence/wiring; provider/diagnostic operation
paths were exercised with test doubles by the automated suite.

Final checks passed: 738 PHPUnit tests / 1,568 assertions, WordPress Coding
Standards, PHP syntax across 119 files, strict Composer validation and
`git diff --check`. Expected failure-path tests emit only the fixed audit/mail
persistence warnings; no exception payload is exposed.

## Remaining scope

Actor/source attribution is ticket 3; user_id 0 currently means unattributed.
Audit UI and audit retention are later tickets. Audit failures can leave gaps;
these records are not tamper-proof or exactly-once evidence. No new UI needs
visual QA in these tickets. Supported database compatibility and final owner
review remain release gates; local verification uses MariaDB 10.4.32.
No commit, PR, merge or release has been performed for this work.
