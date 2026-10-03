# Scalyn Mail Relay Implementation Checklist

This checklist turns the platform roadmap into ten main milestones and two
scheduled provider expansion stages for a solo developer. Complete monitoring
and client reporting on the existing SMTP foundation before expanding providers
and delivery evidence. Execute the expansion stages sequentially alongside the
main roadmap as scheduled below; retain the existing milestone numbering.

Owner: Bernie, sole project owner and developer across all modules, architecture,
testing, review, integration, and release decisions.

Created: 2026-09-16.

Provider expansion schedule updated: 2026-09-27.

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

- [x] Add repository queries/read models for date ranges, provider filters, activity totals, recent failures, and score trends. See [ticket 1 contract and validation](REPORTING-QUERIES.md); bounded site-local periods, provider-filtered mail reads and site-wide daily score trends. Bernie's review remains outstanding.
- [x] Present accepted and failed counts with accurate lifecycle labels and evidence freshness. Dashboard seven-day retained activity summary, safe unavailable state, explicit site-time cutoff and separate health freshness; see [ticket 2 verification](qa/2026-09-23-dashboard-metrics.md). Bernie's review remains outstanding.
- [x] Add prioritised recommendations grounded in diagnostic results. Diagnostics now shows deterministic, evidence-referenced guidance with refresh-first handling for stale/ambiguous results; see [ticket 3 rules and validation](DIAGNOSTIC-RECOMMENDATIONS.md). Bernie's review remains outstanding.
- [x] Define report snapshots with reporting periods, timestamps, findings, and evidence references. Versioned immutable in-memory capture with consistent read-only database view, bounded private projections and explicit limitations; see [ticket 4 contract and validation](REPORT-SNAPSHOTS.md). Bernie's contract review remains outstanding; exports are separate tickets.
- [x] Implement permission-protected CSV and JSON exports with privacy controls and CSV formula-injection protection. Reports page uses the existing export capability, POST nonce, validated filters and direct downloads; evidence identifiers are opt-in. See [ticket 5 behavior and validation](REPORT-EXPORTS.md). Bernie's review remains outstanding.
- [x] Add PDF reports using the same report data after the data contract is stable. Shared privacy-filtered snapshot, protected download, paginated layout and hardened Dompdf renderer; see [ticket 6 behavior, packaging and verification](PDF-REPORTS.md). Bernie's dependency/architecture review remains outstanding.
- [x] Record exports in the audit trail and expire generated files where applicable. CSV/JSON/PDF attempts record safe format/privacy metadata, actor and correlated preparation/failure outcomes; existing audit retention applies. Reports stream without persisted final files. See [ticket 7 behavior and evidence](EXPORT-AUDITING.md).
- [x] Completion gate: report figures match the selected records, findings are traceable, and exports respect permissions. Evidence is linked in [Milestone 6 completion notes](EXPORT-AUDITING.md); Bernie's review and release browser QA remain outstanding.

## 7. First API provider

Outcome: one API provider works through the same operational workflow as SMTP.

- [x] Select SendGrid as the first Phase 2 API provider, as approved by Bernie. Supported-message targets, limitations, security boundaries and contract gaps are recorded in [ADR-0016](adr/0016-sendgrid-first-api-provider.md) and [ticket 1 scope](SENDGRID-PROVIDER-SCOPE.md). Detailed scope review remains outstanding.
- [x] Implement SendGrid configuration, validation, encrypted credential storage and explicit keep/replace/remove actions. Separate server encryption key approved by Bernie; no adapter registration or SMTP change. See [ADR-0017](adr/0017-sendgrid-credential-protection.md) and [configuration/verification notes](SENDGRID-CONFIGURATION.md). Bernie reported browser QA complete on 2026-09-30; owner implementation review remains pending.
- [x] Build the SendGrid adapter behind `ProviderInterface`; no shared-contract changes were needed for this isolated adapter. See [ticket 3 scope and verification](SENDGRID-ADAPTER.md). Registration and normal WordPress sending remain ticket 4.
- [x] Connect configuration, sandbox verification, deliberate test sending, and normal WordPress sending through the SendGrid adapter. See [ticket 4 workflow and limitations](SENDGRID-WORKFLOW.md) and [ADR-0018](adr/0018-unconfirmed-provider-acceptance.md). Local automated checks passed; Bernie architecture review, browser QA and controlled live verification remain.
- [x] Normalize acceptance, failure, rate-limit, and ambiguous-outcome handling. See [ticket 5 outcomes](SENDGRID-OUTCOMES.md); explicit HTTP rejection, safe credits/rate-limit guidance and conservative SMTP/API uncertainty. No automatic retry; Bernie review remains.
- [x] Preserve UUID correlation, safe logs, and timeline events. See [ticket 6 correlation](SENDGRID-CORRELATION.md); early preparation failures, source attribution and observer isolation are covered by integration-style privacy tests. Bernie review remains.
- [ ] Run equivalent provider-contract tests and live verification for SMTP and the API adapter. Automated contract/MIME checks are implemented; see [ticket 7 evidence and remaining live QA](MILESTONE-7-VERIFICATION.md).
  - [x] Shared SMTP/SendGrid tests for supported formats, attachment handling, safe outcomes and no automatic retry; real WordPress PHPMailer MIME check.
  - [x] Live non-sending SMTP connection probe succeeds.
  - [ ] Live SendGrid sandbox succeeds; currently blocked by HTTP 401, Email API credits exhausted (2026-09-30).
  - [ ] Controlled plain/HTML-with-attachment sends and recipient confirmation for both transports; no real emails sent in this implementation pass.
- [ ] Completion gate: both transports handle their supported formats and attachments, expose safe outcomes, and pass the required verification. Automated evidence passes; live SendGrid verification, controlled sends, owner review and release QA remain outstanding.

## 7B. Additional API provider: Postmark

Schedule: before Milestone 8, approved by Bernie after SendGrid registration
rejections. SendGrid's outstanding live verification remains outstanding.
See [implementation and QA guide](POSTMARK-PROVIDER.md) and [ADR-0020](adr/0020-postmark-before-delivery-evidence.md).

- [x] Ticket 1: define Postmark transactional scope, Live-only verification, limits and safe outcome policy. Owner review remains pending.
- [x] Ticket 2: implement encrypted Server API token storage and inline wizard configuration, with keep/replace/remove actions and verification invalidation.
- [x] Ticket 3: implement and register the API adapter; connect non-sending verification, wizard test sending and normal WordPress mail.
- [x] Ticket 4: normalize outcomes, retain message UUID correlation and provider MessageID, and preserve private logs/audit behavior without retries.
- [x] Provider-switch follow-up: scope current diagnostic evidence to the active provider/configuration and sender domain; clear wizard step 6 after changes, retain history, omit SMTP checks/guidance for APIs, and correlate the displayed score to the same run. See [ADR-0021](adr/0021-provider-scoped-diagnostic-evidence.md). Owner review remains pending.
- [ ] Ticket 5: complete automated and controlled live QA, desktop/mobile review and owner acceptance.
  - [x] Automated adapter, credential, shared-provider and WordPress-bridge tests.
  - [x] Live Server verification and recipient receipt confirmation: owner QA on 2026-10-01 confirmed CF7 plain text, plain text with attachment, and HTML with attachment. See POSTMARK-PROVIDER.md. These receipts do not establish automated delivery tracking.
  - [ ] Final owner review and release QA.
- [ ] Completion gate: all required live evidence and review are recorded. Webhooks remain Milestone 8; automatic failover remains Milestone 9.

## 8. Delivery evidence and Deliverability Centre

Outcome: users can distinguish acceptance, confirmed delivery, and observed inbox placement.

Use an accessible, live-verified API provider from Milestone 7 or 7B to establish
the initial delivery/bounce integration, then repeat for the other providers.
Provider-specific authentication must be researched separately; do not assume
SendGrid webhook signatures apply to Postmark.

- [ ] Define provider-message correlation and out-of-band event contracts.
- [ ] Implement webhook authentication, signature verification, replay protection, and duplicate-event handling.
- [ ] Preserve delivery/bounce evidence alongside original send-attempt history.
- [ ] Extend authentication diagnostics with deeper SPF evaluation, DKIM selector configuration, and DMARC alignment analysis.
- [ ] Add reverse-DNS analysis only where the sending infrastructure is known.
- [x] Introduce controlled message/header analysis and mailbox testing with explicit privacy boundaries. Administrator-controlled test message to their own mailbox, with transient analysis of pasted headers (receiver verdicts and message-level alignment, domains only, nothing stored). See [ADR-0025](adr/0025-transient-header-analysis.md).
- [ ] Define deliverability assessment coverage, freshness, and scoring policy before displaying a numerical score.
- [ ] Ticket 8: implement Provider Health Assessment (owner: Bernie; after the preceding evidence and assessment-policy tickets).
  - [ ] Define provider-specific assessment rules for SMTP and SendGrid, including evidence coverage, freshness windows, severity thresholds and configuration-change invalidation. Record any shared-contract or persistence decisions in an ADR before implementation.
  - [ ] Attribute connection-check outcomes, recent send failures and authenticated delivery/bounce evidence to the correct provider and configuration. Do not reuse the site-wide health score as a provider score or infer the latest attempt from a last-success timestamp.
  - [ ] Present separate Connection status and Provider health on provider cards. Show timestamped connection evidence and explainable Healthy / Warning / Critical / Unknown health states, with supporting findings and recommended actions. Do not label historical success as a continuously live connection.
  - [ ] Keep missing, stale, unsupported or insufficient evidence explicit; unavailable checks must not count as passes. Preserve SendGrid sandbox-verification limitations and document SMTP's unavailable out-of-band evidence.
  - [ ] Use approved services/read models and repositories; enforce capabilities, credential-free evidence and existing retention/privacy boundaries. Multiple saved configurations, routing and automatic recovery remain Milestone 9.
  - [ ] Test provider/configuration attribution, stale and missing evidence, mixed outcomes, configuration changes, permissions, privacy and status explanations. Complete controlled SMTP/SendGrid verification and desktop/mobile card QA; record limitations and Bernie's review.
- [ ] Completion gate: every delivery or placement claim has supporting evidence; provider acceptance remains `Accepted`, and delivery confirmation alone does not imply inbox placement. Provider-health states are provider-specific, traceable and freshness-aware; neither a healthy status nor a successful connection check guarantees inbox delivery.

## Provider expansion A — Remaining Phase 2 providers

Schedule: after Milestone 8 and before Milestone 9. Complete the first provider's
transport and delivery-evidence workflow before starting this stage. Bernie
prioritises the remaining providers by managed-client needs; provider order is
not yet decided. Implement one provider at a time, using the ticket sequence below.

Outcome: all Phase 2 providers listed in the Technical Architecture are available
as individually selectable transports with documented capabilities and evidence
coverage. The provider completed in Milestones 7–8 counts toward this list and
does not need a duplicate implementation.

- [ ] Record the remaining Phase 2 provider order and create a separate ticket set for each provider.
- [ ] Microsoft 365 — transport, evidence coverage and verification complete.
- [ ] Google Workspace — transport, evidence coverage and verification complete.
- [ ] SendGrid — transport, evidence coverage and verification complete.
- [ ] Mailgun — transport, evidence coverage and verification complete.
- [ ] Brevo — transport, evidence coverage and verification complete.
- [ ] Postmark — transport and controlled live receipt QA completed in Milestone 7B; Milestone 8 automated delivery evidence remains pending.
- [ ] SMTP2GO — transport, evidence coverage and verification complete.
- [ ] Completion gate: each provider has linked implementation and live verification evidence, documented limitations and safe outcomes. Unsupported delivery evidence remains explicitly unavailable.

Multiple saved configurations, message routing and automatic failover are
scheduled in Milestone 9. They are not prerequisites for selecting one of these
providers as the active transport.

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

## Provider expansion B — Phase 3 providers

Schedule: after Milestone 9 and before Milestone 10. Reuse the verified provider,
delivery-evidence and configuration contracts. Bernie sets the order according
to managed-client demand, with one provider active in development at a time.

- [ ] Record Phase 3 provider priority and create a separate ticket set for each provider.
- [ ] Amazon SES — transport, evidence coverage and verification complete.
- [ ] SparkPost — transport, evidence coverage and verification complete.
- [ ] Mailjet — transport, evidence coverage and verification complete.
- [ ] Zoho Mail — transport, evidence coverage and verification complete.
- [ ] Verify each adapter with Milestone 9's configuration attribution, routing and recovery controls, including provider-specific limitations and duplicate-send prevention.
- [ ] Completion gate: all Phase 3 adapters meet the shared provider acceptance criteria and have linked implementation, security and live verification evidence.

## Ticket sequence for each additional provider

Repeat this sequence for each provider in expansion A and B. Use one focused
branch and pull request per ticket. A provider checkbox above is a completion
summary; check it only after its ticket set and verification are complete.

- [ ] Ticket 1: define supported authentication, message formats, recipients, attachments, service limits and available delivery/bounce evidence; record any shared-contract decisions.
- [ ] Ticket 2: implement configuration, validation, credential protection and replacement/removal; include OAuth refresh and revocation where applicable.
- [ ] Ticket 3: implement the adapter behind `ProviderInterface` and connect verification, test sending and normal WordPress sending.
- [ ] Ticket 4: normalize acceptance, failure, rate limits and ambiguous outcomes; preserve UUID correlation, safe logs, timelines and audit attribution.
- [ ] Ticket 5: integrate supported delivery/bounce evidence through Milestone 8's contracts, with authentication, replay protection and deduplication; explicitly document unavailable evidence.
- [ ] Ticket 6: run shared provider-contract tests, security/failure scenarios and controlled live verification; check routing/recovery compatibility where Milestone 9 applies, and record release evidence and limitations.

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
