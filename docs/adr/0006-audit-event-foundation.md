# ADR-0006: Audit event contract and capture

Status: Implemented for Bernie's review. Date: 2026-09-21.

Attribution, history reads and retention deferrals below are superseded by
[ADR-0007](0007-audit-attribution-history-retention.md).

## Scope

Milestone 3 tickets 1–2 use the existing `scalyn_audit_logs` schema (0.1.0),
without migrations or dependencies. `AuditEvent`, `AuditRepository` and
`AuditRecorder` live in the Audit module and are shared lazy container services
where appropriate. Bernie owns architecture, implementation and final review.

## Contract and capture

`AuditEvent` is immutable. Unknown actions, outcomes, field names and non-UUID
correlation identifiers are rejected. No free-form metadata parameter exists.
Stored metadata has version 1, a fixed outcome and changed field names only.
Settings values, credential hashes/masks, addresses, domains, subjects, bodies,
provider responses, diagnostic evidence, IP addresses and user agents are excluded.

| Action | Outcomes | Correlation |
|---|---|---|
| settings_changed | changed | Field names only |
| retention_changed | changed | Field name only |
| uninstall_policy_changed | changed | Field name only |
| provider_verification | started, verified, failed | Attempt UUID |
| test_email | started, accepted, failed | Message UUID |
| diagnostic_run | started, completed, failed | Diagnostic run UUID |

SettingsRepository emits events only after a successful persisted save, and only
for changed allowlisted configuration fields. No-op saves, validation failures
and failed writes emit no change event. Automatic provider verification timestamps
are not configuration changes. Retention and uninstall-policy changes have separate
action names. Blank preserved passwords do not count as changes.

The capability/nonce-protected wizard emits verification and test-send events
at the operation boundary. Invalid test recipients are rejected before dispatch
and are not recorded as sends. Connection exceptions are normalized to a fixed
failed result. Diagnostics emits started before execution and completed only after
result/score persistence returns, or failed on an exception. Completed means the
operation completed, not that every check passed. A killed process or an unexpected
test-dispatch interruption can leave only a started event; completion is never
inferred. SMTP acceptance is not delivery or inbox placement.

`HookNames::AUDIT_EVENT` carries only AuditEvent. The subscriber appends via the
repository and isolates persistence exceptions using a fixed log warning. Audit
failure does not undo successful settings, diagnostic or transport operations.
There is no transactional outbox, retry queue or exactly-once guarantee; repeated
operations produce separate records, and failed audit writes can leave gaps.

## Deliberate boundaries

The repository exposes append only; no update/delete or unbounded read API.
Actor and execution-source attribution belongs to ticket 3: user_id remains 0
(unattributed), never presented as proof of a system action. Audit UI/bounded reads
and retention remain later tickets. Existing retention excludes audit history;
uninstall deletion already owns this table, and default uninstall preserves it.
The privacy restrictions are required at the foundation even though ticket 4
will provide the broader audit privacy verification.

Authorization remains at existing entry points; internal event emission is not
an external REST action and grants no new permissions. Extensions are trusted PHP
code and can emit events, so this is operational history, not tamper-proof evidence.

## Rollback

Revert the audit wiring/classes to stop recording; no schema rollback is needed.
Existing rows remain under retained uninstall behavior. No UI, cron or external
service is introduced. Capability changes, actor policy, audit retention and
exports require their own reviewed tickets.
