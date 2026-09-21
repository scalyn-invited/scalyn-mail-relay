# Diagnostic retention verification — 2026-09-21

## Outcome

Milestone 2 ticket 4 is implemented. `DiagnosticRetentionRepository` removes
only complete expired diagnostic run groups and independently removes expired
health snapshots. It is registered as a shared lazy service. Scheduling remains
disabled, so this implementation does not yet enforce the stored retention value.

## Contract

- A run is eligible only when every row sharing its `diagnostic_uuid` is strictly
  older than the supplied cutoff.
- Eligible groups are oldest first and bounded to 1–250 groups per call.
- All rows in selected groups are locked and deleted together.
- Health snapshots are independently selected by `created_at`, oldest first,
  with their own 1–250 bound. Deletes repeat the cutoff defensively.
- Both histories are processed in one transaction. Failed queries, incomplete
  group locks, invalid delete counts and unexpected exceptions roll back and
  expose only fixed safe errors.
- Results contain only cutoff and deletion counts.

Health snapshots cannot be correlated to diagnostic runs under schema 0.1.0.
This ticket preserves that truth: it does not infer associations. ADR-0004 records
the decision and reserves provenance linkage for a reviewed future migration.

## Automated and real-database evidence

Focused tests cover strict group eligibility using `MAX(created_at)`, independent
bounds, group-row locking, full-run deletion, health-only and diagnostic-only
batches, rollback paths, count validation and safe invalid-cutoff rejection.

The Git-ignored `build/clean-install-20260917/verify-diagnostic-retention.php`
ran against the guarded `scalyn_clean_20260917` database and `qa_` prefix. It
seeded two fully expired runs, one mixed old/cutoff run and one newer run, plus
two expired, one cutoff and one newer health snapshot.

With limit one, two calls removed the expired runs as groups of two and one rows,
and independently removed one old score per call. A third call returned zero for
both histories. The mixed run retained both rows; the newer run and cutoff/newer
health snapshots remained. Named synthetic fixtures were removed in `finally`.
The working site and customer data were untouched.

## Final validation

- Focused retention, repository and container regression: 89 tests, 164 assertions.
- Complete PHPUnit suite: 692 tests, 1,415 assertions.
- WordPress Coding Standards: passed for the complete configured source set.
- PHP syntax: passed for all 106 PHP files under `includes`, `admin` and `tests`.
- Git whitespace/error check: passed.
- Isolated MariaDB verification: passed all guards, seed, cleanup, resumption,
  cutoff-retention and database-error assertions.
- The database helper remains excluded by the repository's `/build/` ignore rule.

## Limitations

- No scheduler or settings orchestrator invokes this repository yet.
- Local verification used MariaDB 10.4.32; supported MySQL 8 / MariaDB 10.6
  verification remains a release gate.
- Score-to-run provenance is still absent and is not solved by retention.
- Diagnostic UUID reuse would violate the run-group contract.

Bernie retains architecture, merge and release authority. No commit, push, PR,
merge or release action was performed.
