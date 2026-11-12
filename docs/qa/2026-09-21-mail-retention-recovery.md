# Mail retention recovery verification — 2026-09-21

## Outcome

Milestone 2 ticket 3 passed automated and isolated real-database recovery tests.
Committed batches resume after process boundaries; partial deletion failures roll
back; retrying the same cutoff is safe; and records at or newer than the cutoff
remain intact. No production behavior changed in this ticket.

## Automated coverage

`MailRetentionRepositoryTest` now covers:

- timeline-delete failure before the parent delete;
- mail-log query failure after the timeline delete;
- an unexpected database exception and safe error normalization;
- a parent row-count mismatch treated as failure;
- commit and transaction-start failures;
- rollback followed by a successful retry with the same cutoff;
- later invocation selecting the next oldest candidate;
- strict `< cutoff`, defensive cutoff repetition and bounded selection.

The focused repository test passed **15 tests / 46 assertions** before the full
suite. Query failures use fixed messages and unexpected driver detail is not
propagated through the repository exception.

## Cross-process database verification

The Git-ignored helper
`build/clean-install-20260917/verify-mail-retention-recovery.php` ran in two
separate PHP processes against only the guarded `scalyn_clean_20260917` database
and `qa_` prefix. It first verified that the mail-log table uses InnoDB.

The `interrupt` phase inserted five named synthetic aggregates, committed a
two-record batch, confirmed one expired aggregate remained, then exited. The
`resume` phase verified the committed rows stayed deleted and the remaining work
was still present after the connection/process boundary.

The resume phase installed a temporary `BEFORE DELETE` trigger on the isolated
mail-log table that raised a synthetic database error after related timeline
deletion was attempted. The repository returned its fixed safe error. Both the
parent and timeline event remained, proving transaction rollback. After dropping
the trigger, the same cutoff completed the aggregate; a further call returned
zero. The parent and timeline at the cutoff and newer than it remained intact.
All named fixtures and the temporary trigger were removed afterward.

## Scope and limitations

- The working WordPress site and its real mail history were untouched.
- No customer data, credentials, settings, cron hooks, schema migration or
  production trigger was created.
- The interruption is between committed batches, which is the repository's
  supported resumption boundary. Abrupt termination inside a transaction relies
  on InnoDB connection-close rollback and was not simulated by killing PHP.
- This evidence uses local MariaDB 10.4.32. Supported MySQL 8 / MariaDB 10.6
  candidate verification remains a release gate.
- Automatic scheduling, overlap protection and cleanup status remain ticket 5.

Bernie retains review, merge and release authority. No commit, push, PR, merge,
or release action was performed.

## Final validation

- Full PHPUnit: **674 tests / 1359 assertions passed**. Safe persistence-error
  lines are expected failure-path test output.
- WPCS passed for the configured production scope.
- PHP lint: **105 files, zero failures**.
- Changed-document links: zero missing local targets.
- `git diff --check` passed.
- The recovery helper is Git-ignored and its final phase confirmed removal of
  every named synthetic fixture.
