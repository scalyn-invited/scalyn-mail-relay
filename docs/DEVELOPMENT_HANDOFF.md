# Lead Handoff

## Transfer

- **Outgoing:** Kim — Lead Developer / Solution Architect
- **Incoming:** Bernie — Sole Project Owner and Developer
- **Effective handoff date:** 2026-08-28
- **Sole ownership confirmed:** 2026-09-16

Kim led the completed foundation and prepared the original handoff. Bernie now owns all implementation, architecture, integration, QA, review, and release decisions. Kim, Saturn, Yaj, and Mikko have no continuing assignments or required approvals.

## Historical Handoff Baseline

Current continuation status (2026-09-21): diagnostics, scoring, REST execution,
and populated Admin views are implemented. Milestone 1 local verification is
recorded in [the completion report](qa/2026-09-21-milestone-1-completion.md).
Use [PROJECT_STATUS.md](PROJECT_STATUS.md) and the implementation checklist for
current work; the historical sequence below is not a list of missing features.

This section records the original handoff, not current feature completeness. Consult current code, tests, and `PROJECT_STATUS.md` for subsequent implementation.

The verified stable integration baseline is `origin/develop` at `84e8cdda6e91891b83c4b9dd738318ddce736ed6`.

It includes the completed mail vertical slice:

`Configure SMTP → Verify Connection → Send Test Email → Mail Log → Timeline → Dashboard Result`

It also includes the Diagnostics contracts, context/result types, isolated runner, persistence repository/schema, Admin UI empty-state foundation, and shared-container wiring. Concrete checks, run/read endpoints, failure classification, scoring behavior, and live Diagnostics/Dashboard integration are not part of this baseline.

## Current Responsibilities

### Bernie — Sole Project Owner and Developer

- All Core, Mail, Providers, Database, Logging, Diagnostics, REST, Admin, assets, and future modules.
- Architecture, implementation, security/privacy, testing, manual QA, documentation, and integration.
- Pull-request review, merge decisions, and release decisions.
- Preserve modular contracts and repository boundaries across all work.

## How Development Continues

- Fetch and branch from the latest `develop`; never develop directly on `develop` or `main`.
- Use one focused ticket → one branch → one pull request, spanning modules when required by the outcome.
- Target feature and fix pull requests to `develop`; reserve `main` for releases.
- Define affected contracts before cross-module changes; no former team approval is required.
- Keep changes small, add focused tests, run the repository checks, and provide manual QA evidence proportionate to risk.
- Bernie reviews changes and decides when work is ready to merge. Existing repository protection rules still apply.

## Ongoing Architectural Dependencies

- UI integration depends on approved backend execution/read contracts and stable read models.
- Failure classification depends on concrete, normalized transport and diagnostic outputs.
- Health scoring depends on stable diagnostic evidence and an explainable scoring policy.
- Dashboard summaries depend on trustworthy diagnostics/scoring reads and explicit stale/error states.
- Reporting depends on stabilized diagnostics and scoring contracts.
- Retention and release hardening span migrations, scheduled tasks, data lifecycle, security, compatibility, and packaging, and should be completed as MVP capabilities stabilize.

## Historical MVP Continuation Order

This was the original handoff sequence. Several steps have since been implemented; verify current code before creating work from this list. Bernie owns every remaining step.

1. Agree on normalized diagnostic IDs/results, secure context construction, run grouping, read models, and REST contracts.
2. Implement DNS checks and SMTP/TLS checks in parallel behind those contracts, with fixtures and safe error behavior.
3. Add orchestration, persistence, bounded reads, permissions, and execution/read endpoints.
4. Connect the Diagnostics Admin UI, then add Dashboard summaries using approved read models.
5. Build failure classification/remediation from stable outputs; implement explainable scoring after evidence semantics settle.
6. Add retention/history controls and MVP reporting on the stabilized data model.
7. Complete security, migration, compatibility, accessibility, clean-site, upgrade/uninstall, packaging, and release QA.

Alerts, agency/white-label features, additional API providers, SaaS monitoring, and AI diagnostics remain later roadmap work rather than prerequisites for this MVP sequence.

## Architecture and Security Checklist for the New Lead

Bernie reviews changes involving:

- Shared interfaces, service-container registrations, hooks, or cross-module read models.
- Database schemas, version gates, retention, scheduled jobs, or uninstall behavior.
- REST routes, response contracts, authentication, authorization, or rate/timeout behavior.
- Capabilities, nonces, validation, escaping, redaction, credential handling, or privacy boundaries.
- Mail lifecycle terms, particularly any claim involving acceptance or delivery.
- Provider registration, transport independence, PHPMailer behavior, or normalized failures.
- New runtime/development dependencies, compatibility baselines, or packaging requirements.

Record durable decisions in `docs/adr/`. Verify that privileged operations use capability and nonce/REST permission checks; diagnostic and mail data exclude secrets and full message bodies by default; database access stays in repositories; queries are prepared and bounded; and output is escaped at the rendering boundary.

## Historical Handoff Notes

The following notes describe the original handoff baseline and are retained for context; they are not current status claims.

- `develop` is ahead of `main` and is the authoritative integration baseline for continued MVP work.
- SMTP acceptance is represented as `Accepted`, never as guaranteed delivery.
- The Diagnostics page currently renders foundation states but deliberately has no enabled execution URL.
- The diagnostics and health-score tables are foundations; table presence is not feature completion.
- Current unit tests pass, with one known `$wpdb` warning in dispatcher tests. WPCS also exposes repository-wide CRLF line endings. These are quality-baseline items, not changes made by this handoff.
- Existing older documentation contains historical ownership and aspirational feature descriptions. Use `AGENTS.md`, `docs/PROJECT_STATUS.md`, current code/tests, and accepted ADRs as the operational baseline, and reconcile older documents in a separately scoped documentation update.
