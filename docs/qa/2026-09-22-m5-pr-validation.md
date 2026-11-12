# Milestone 5 and follow-up PR validation

Owner: Bernie. Branch: `feature/m5-alerts-recovery`.
Base: `origin/develop` at `fec3d4f`, fetched again before publication.

## Scope and dependencies

This PR bundles the requested Milestone 5 work with the subsequent Settings/DKIM
and diagnostic-clarity requests. It depends on merged Milestone 4 orchestration.
No new package dependency or provider interface change is introduced.

- Alert incidents, transactional outbox, bounded webhook retries and recovery,
  scheduled evaluation, retention, audit events and Admin history.
- Data Controls becomes Settings while preserving the existing URL; DKIM
  selector configuration has its own capability/nonce-protected form.
- Scheduled monitoring status is displayed on Dashboard only.
- Dashboard/Diagnostics explicitly distinguish configuration scoring from
  message authentication and recipient delivery, including historical snapshots.
- Record-check wording and test-send/log guidance explain later rejection.

## Validation

Final local PHPUnit run: **873 tests, 2,001 assertions passed** on PHP 8.2.12.
Composer strict validation, full WPCS, PHP syntax (152 files) and diff checks
passed before publication; remote CI independently checks PHP 8.2 and 8.3.
Expected failure-injection logs are exercised deliberately by passing tests.

The isolated WordPress helpers passed again before publication, covering database migration/transactions,
alert transitions and retry behavior, independent Settings forms, and rendering
of verification limitations on Dashboard and Diagnostics. They do not send
external email or webhook notifications. See the original
[M5 evidence](2026-09-22-milestone-5-completion.md) for detailed checks and
the limited static browser review performed during implementation.

## Security, migration and rollback

See [ADR-0012](../adr/0012-alerts-and-webhook-outbox.md) for the additive schema
0.2.0 migration, lifecycle contracts and fixed incident policy. Webhook secrets
remain server constants; payloads exclude addresses, bodies and raw diagnostic
data. Privileged settings use capabilities/nonces and escaped output. No new
message-body storage or external DNS changes are introduced.

Before rollback, disable webhook delivery and deactivate this version to clear
its scheduled hooks. Retain the additive outbox table and evidence; do not lower
the stored schema version or delete tables manually. Default uninstall retains
data; destructive uninstall still requires explicit administrator opt-in.

Production receiver verification, supported-database matrix validation, full
authenticated browser/accessibility QA and Bernie's review remain release gates.
The Gmail sender-authentication failure is not repaired by changing diagnostic
wording; provider/DNS remediation and receiver evidence are still required.
The unrelated local release-readiness PDF is excluded from this PR.
