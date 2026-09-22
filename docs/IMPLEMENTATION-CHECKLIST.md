# Scalyn Mail Relay Implementation Checklist

This checklist turns the platform roadmap into ten sequential milestones for a
solo developer. Complete monitoring and client reporting on the existing SMTP
foundation before expanding providers and delivery evidence.

Owner: Bernie, sole project owner and developer across all modules, architecture,
testing, review, integration, and release decisions.

Created: 2026-09-16.

## Working rules

- Keep one active milestone and one focused ticket at a time.
- Use one branch and pull request per ticket; span modules when the outcome requires it while preserving contracts and repository boundaries.
- Follow [AGENTS.md](../AGENTS.md). Former team members have no required assignments or approvals.
- Verify current code, tests, and merged `origin/develop` history before implementation. This checklist records planned work, not proof that a feature is absent or complete.
- Check items only after recording implementation and validation evidence. All items begin unchecked, including baseline verification of existing features.
- Record durable architecture decisions in [docs/adr](adr/), and follow the [release checklist](RELEASE-CHECKLIST.md) for releases.

## 1. Verify the release baseline

Outcome: a reproducible starting point with known limitations.

- [x] Run automated checks and record results against the reviewed commit. See [baseline verification record](qa/2026-09-16-baseline-verification.md).
- [x] Verify clean installation, table creation, capabilities, and Admin pages. [Local clean-install evidence](qa/2026-09-17-clean-install-verification.md): fresh activation and server-rendered screens passed; supported-database compatibility and clean-site browser QA remain release checks.
- [x] Verify SMTP setup, connection testing, and test-email sending. [SMTP verification evidence](qa/2026-09-17-smtp-verification.md): existing configuration connected, one test was accepted, and Bernie confirmed Inbox receipt.
- [x] Verify diagnostics, persisted health scoring, and correlated logs/timelines. [Verification and gaps](qa/2026-09-17-diagnostics-verification.md): local baseline passed; score provenance, coverage clarity, and intermediate lifecycle evidence remain follow-ups.
- [x] Verify retained-data and explicitly enabled destructive uninstall behavior. [Isolated uninstall evidence](qa/2026-09-17-uninstall-verification.md): retain, explicit deletion, unrelated-state preservation, and reactivation passed.
- [x] Reconcile outdated status documentation with verified functionality. Current status leads with verified features; historical handoff claims are explicitly labelled.
- [x] Completion gate: record baseline evidence, known limitations, and unresolved release blockers. See [Milestone 1 completion record](qa/2026-09-21-milestone-1-completion.md); evidence collection is complete, but Bernie's review and release approval remain outstanding.

## 2. Retention and data controls

Outcome: operational data has an enforced, understandable lifecycle.

- [x] Define expiry boundaries and repository-owned deletion contracts. See [ADR-0003](adr/0003-mail-retention-boundaries.md).
- [x] Implement bounded cleanup for mail logs and their related timeline events. `MailRetentionRepository` deletes oldest-first aggregates transactionally in batches of at most 250; scheduling remains a later ticket.
- [x] Test partial failures, interrupted cleanup, safe resumption, and recent-record preservation. See [recovery verification](qa/2026-09-21-mail-retention-recovery.md).
- [x] Add cleanup for diagnostic results and health snapshots with consistent grouping/correlation behavior. See [ADR-0004](adr/0004-diagnostic-retention-boundaries.md) and [verification evidence](qa/2026-09-21-diagnostic-retention.md).
- [x] Add scheduling, overlap protection, and cleanup status. Hourly `RetentionService`, database connection lock and allowlisted status; see [ADR-0005](adr/0005-retention-execution-and-controls.md).
- [x] Add validated retention settings and explain their effects in the Admin UI. Data Controls accepts 1–3650 whole days and explains cutoff, grouping, backlog and WP-Cron effects.
- [x] Add explicit administrator confirmation for destructive uninstall configuration. Capability/nonce-protected form plus a separate confirmation; only stored boolean true enables deletion.
- [x] Keep activation, deactivation, and uninstall hook handling consistent. Shared `ScheduledHooks`; activation schedules retention, deactivation and both uninstall modes stop work while respecting data policy.
- [x] Completion gate: expired data is removed safely in bounded batches, recent data remains, and interrupted cleanup can resume. See [Milestone 2 verification](qa/2026-09-21-milestone-2-completion.md). Bernie's review and release compatibility/browser checks remain release gates.

## 3. Audit trail

Outcome: important administrative actions are traceable.

- [x] Define an allowlisted audit-event contract and repository. See [ADR-0006](adr/0006-audit-event-foundation.md).
- [x] Record configuration changes, provider verification, test sends, diagnostic runs, and retention changes. See [tickets 1–2 verification](qa/2026-09-21-audit-foundation.md).
- [x] Identify actors and distinguish manual actions from scheduled operations. Runtime-captured IDs and execution context; legacy attribution remains unknown.
- [x] Exclude credentials, tokens, message bodies, and sensitive request payloads. Allowlisted writes and read projections; no IP, user agent, recipient or setting values.
- [x] Add a capability-protected, paginated audit view. Mail Relay → Audit History, 50 records per cursor page.
- [x] Add audit retention and tests for expiry behavior. Existing Data Controls policy, transactional batches of 100; see [ADR-0007](adr/0007-audit-attribution-history-retention.md).
- [x] Completion gate: important administrative actions can be traced without exposing sensitive data. See [Milestone 3 verification](qa/2026-09-21-milestone-3-completion.md). Bernie's review and release QA remain outstanding.

## 4. Scheduled health monitoring

Outcome: diagnostics run unattended and monitoring freshness is visible.

- [x] Extract shared orchestration for manual REST requests and scheduled runs. Shared DiagnosticRunService and own-UUID scoring reads.
- [x] Add configurable scheduling, overlap protection, and bounded execution. Opt-in Data Controls cadence, shared lock, check-count and cooperative time limits; see [ADR-0008](adr/0008-shared-diagnostic-orchestration-and-scheduling.md) and [tickets 1–2 evidence](qa/2026-09-21-monitoring-foundation.md). Owner review remains outstanding.
- [x] Preserve isolated check failures, grouped results, and consistent health snapshots. InnoDB atomic publication, shared UUID/time and rollback/retry; see [ticket 3 verification](qa/2026-09-21-atomic-diagnostic-publication.md) and [ADR-0009](adr/0009-atomic-diagnostic-publication.md). Owner review remains outstanding.
- [x] Record run timestamps and safe execution-failure information. Bounded status repository, UTC timestamps, fixed failure stages, separate scheduled freshness and conservative unconfirmed completion; see [ticket 4 evidence](qa/2026-09-21-diagnostic-execution-status.md) and [ADR-0010](adr/0010-diagnostic-execution-status.md).
- [x] Show stale results, overdue runs, and failed monitoring in the UI. Dashboard/Diagnostics panels separate schedule, execution and retained-evidence freshness.
- [x] Verify low-traffic WP-Cron behavior and document operational scheduling requirements. Real isolated cron entry-point test; see [operations guide](MONITORING-OPERATIONS.md).
- [x] Completion gate: unattended runs persist consistent results and overdue or failed monitoring is clearly reported. See [Milestone 4 evidence](qa/2026-09-22-milestone-4-completion.md). Owner review and release QA remain outstanding.

## 5. Alerts and recovery notifications

Outcome: actionable incidents surface without repeated notification noise.

- [x] Define incident rules for repeated send failures, monitoring failures, and health degradation.
- [x] Implement alert persistence, deduplication, cooldowns, and resolution.
- [x] Add an independent webhook notification channel with protected configuration.
- [x] Track notification attempts and handle channel failures safely.
- [x] Add recovery notifications and an alert history/view.
- [x] Record relevant alert actions in the audit trail.
- [x] Completion gate: an incident creates an actionable alert, repeated observations are deduplicated, and recovery is recorded. See [Milestone 5 evidence](qa/2026-09-22-milestone-5-completion.md) and [operator guide](ALERTS-AND-RECOVERY.md). Bernie's review and production receiver verification remain outstanding; no receiver or OS scheduler was provisioned.

## 6. Operational dashboard and reporting

Outcome: client issues can be investigated and explained with stored evidence.

- [ ] Add repository queries/read models for date ranges, provider filters, activity totals, recent failures, and score trends.
- [ ] Present accepted and failed counts with accurate lifecycle labels and evidence freshness.
- [ ] Add prioritised recommendations grounded in diagnostic results.
- [ ] Define report snapshots with reporting periods, timestamps, findings, and evidence references.
- [ ] Implement permission-protected CSV and JSON exports with privacy controls and CSV formula-injection protection.
- [ ] Add PDF reports using the same report data after the data contract is stable.
- [ ] Record exports in the audit trail and expire generated files where applicable.
- [ ] Completion gate: report figures match the selected records, findings are traceable, and exports respect permissions.

## 7. First API provider

Outcome: one API provider works through the same operational workflow as SMTP.

- [ ] Select the first provider and define its supported message capabilities.
- [ ] Implement provider-specific configuration, validation, and credential protection/replacement/removal.
- [ ] Build the adapter behind `ProviderInterface`; review any required shared-contract changes deliberately.
- [ ] Connect configuration, verification, test sending, and normal WordPress sending.
- [ ] Normalize acceptance, failure, rate-limit, and ambiguous-outcome handling.
- [ ] Preserve UUID correlation, safe logs, and timeline events.
- [ ] Run equivalent provider-contract tests and live verification for SMTP and the API adapter.
- [ ] Completion gate: both transports handle their supported formats and attachments, expose safe outcomes, and pass the required verification.

## 8. Delivery evidence and Deliverability Centre

Outcome: users can distinguish acceptance, confirmed delivery, and observed inbox placement.

- [ ] Define provider-message correlation and out-of-band event contracts.
- [ ] Implement webhook authentication, signature verification, replay protection, and duplicate-event handling.
- [ ] Preserve delivery/bounce evidence alongside original send-attempt history.
- [ ] Extend authentication diagnostics with deeper SPF evaluation, DKIM selector configuration, and DMARC alignment analysis.
- [ ] Add reverse-DNS analysis only where the sending infrastructure is known.
- [ ] Introduce controlled message/header analysis and mailbox testing with explicit privacy boundaries.
- [ ] Define deliverability assessment coverage, freshness, and scoring policy before displaying a numerical score.
- [ ] Completion gate: every delivery or placement claim has supporting evidence; provider acceptance remains `Accepted`, and delivery confirmation alone does not imply inbox placement.

## 9. Agency controls and transport recovery

Outcome: managed clients have appropriate access and recovery behavior is predictable.

- [ ] Implement managed settings and client permissions, enforced in backend actions and UI.
- [ ] Add client views, branding, and support information.
- [ ] Add multiple provider configurations and reliable source attribution.
- [ ] Define and implement explicit routing rules as a separate ticket.
- [ ] Define attempt-level correlation, bounded retries, and failover policy.
- [ ] Handle ambiguous send outcomes without blindly resending messages.
- [ ] Before implementing resend, choose source regeneration or explicitly authorised, short-lived payload storage; metadata-only logs cannot reconstruct messages.
- [ ] Test routing decisions, permission boundaries, and duplicate-send prevention scenarios.
- [ ] Completion gate: clients cannot alter locked settings, routing is predictable, and recovery safely handles ambiguous outcomes.

## 10. Multisite, cloud monitoring, and AI

Outcome: each expansion ships as a separately validated project.

- [ ] Define multisite network/site ownership of settings and capabilities.
- [ ] Implement existing-site migrations, new-site provisioning, scheduling, retention, and uninstall behavior.
- [ ] Verify the multisite lifecycle before declaring support.
- [ ] Define opt-in cloud registration, authenticated synchronization, tenant isolation, and a versioned minimal-data contract.
- [ ] Implement offline synchronization behavior and data-sharing controls.
- [ ] Add AI explanations grounded in structured, redacted diagnostic evidence.
- [ ] Keep deterministic checks authoritative and require human approval for consequential configuration changes.
- [ ] Completion gate: multisite, cloud, and AI each meet their own contract, privacy, testing, and release criteria.

## Validation for each implementation ticket

- [ ] Record the user outcome, affected modules/contracts, and acceptance criteria.
- [ ] Add or update relevant tests and run PHP lint, WPCS, PHPUnit, and `git diff --check` as required by `AGENTS.md`.
- [ ] Complete proportionate manual WordPress QA and document remaining limitations.
- [ ] Review security/privacy, accessibility, migrations, retention, and lifecycle effects where applicable.
- [ ] Update behavior documentation and record implementation/test evidence in the pull request.
- [ ] Bernie reviews the change and makes the merge/release decision under existing repository rules.

## First implementation ticket after baseline verification

- [x] **Enforce retention for mail logs and their timeline events.** Repository cleanup, diagnostic/health cleanup, hourly execution and Data Controls are implemented; see the Milestone 2 completion evidence above.
