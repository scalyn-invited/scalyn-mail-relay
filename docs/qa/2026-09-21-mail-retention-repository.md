# Mail retention repository verification — 2026-09-21

## Outcome

Milestone 2 tickets 1 and 2 are implemented. ADR-0003 defines the boundary and
`MailRetentionRepository` implements bounded transactional deletion for mail-log
aggregates. Related timeline events are deleted before their expired parents.
The repository is registered as a shared lazy service, but no hook invokes it yet.

## Contract and acceptance evidence

- The parent mail log's `created_at` is authoritative; expiry uses strict `<`.
- Rows exactly at the cutoff are retained.
- Selection is oldest first and locked with `FOR UPDATE`.
- Calls default to 100 messages and clamp to 1–250.
- All timeline events for selected UUIDs are deleted, followed by parent logs,
  in one transaction. Failed queries and count mismatches roll back.
- Results expose only cutoff and aggregate counts; exceptions use fixed safe text.
- Empty calls succeed with zero counts, so a caller can batch until completion.
- No schema/database-version, capability, REST, UI, uninstall or lifecycle hook
  changed. Scheduling, overlap protection and settings remain later tickets.

## Real database verification

The Git-ignored `build/clean-install-20260917/verify-mail-retention.php` ran
against the isolated `scalyn_clean_20260917` database and `qa_` prefix. Root,
database and prefix guards ran before mutation. Five named synthetic aggregates
were inserted, each with two timeline events: three older than the cutoff, one
exactly at it, and one newer.

With a batch size of two, the first call removed two parents and four events;
the next removed the remaining expired parent and two events; a final call
returned zero. The cutoff and newer aggregates and all four related events were
preserved. Only named synthetic UUIDs were cleaned afterward. No working-site
mail, logs, configuration, credentials or customer data were touched.

## Validation

- Focused retention/log/timeline/container suite:
  **94 tests, 170 assertions passed**.
- Full suite on the new `origin/develop`-based feature branch:
  **672 tests, 1351 assertions passed**. The safe persistence-error lines are
  expected fault-test output.
- WPCS passed for the full configured production scope.
- PHP lint: **105 files, zero failures**.
- Changed-document links: zero missing local targets.
- `git diff --check` passed.

## Remaining limitations

- This is a callable repository contract, not enforced retention. The stored
  retention setting is not yet validated/wired to an orchestrator and no cron
  event is scheduled.
- Cross-process resumption and real-database rollback/retry were subsequently
  verified in [milestone 2 ticket 3](2026-09-21-mail-retention-recovery.md).
- The supported-engine transaction assumption still needs supported MySQL 8 /
  MariaDB 10.6 candidate verification; local MariaDB is 10.4.32.
- Existing orphan timeline rows without a parent are intentionally not deleted.
- Diagnostic and health-score retention use different grouping semantics and
  remain a later ticket.

Bernie retains architecture, merge and release approval. No commit, push, PR,
merge, scheduling change or release action was performed by this verification.
