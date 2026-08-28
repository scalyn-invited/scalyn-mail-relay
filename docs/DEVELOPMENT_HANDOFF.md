# Lead Handoff

## Transfer

- **Outgoing:** Kim — Lead Developer / Solution Architect
- **Incoming:** Bernie — Lead Developer / WordPress UI Engineer
- **Effective handoff date:** 2026-08-28

Kim led the completed foundation and prepared this handoff. Bernie owns project continuation, integration, review coordination, architecture decisions, and merge decisions after the handoff.

## Stable Baseline

The verified stable integration baseline is `origin/develop` at `84e8cdda6e91891b83c4b9dd738318ddce736ed6`.

It includes the completed mail vertical slice:

`Configure SMTP → Verify Connection → Send Test Email → Mail Log → Timeline → Dashboard Result`

It also includes the Diagnostics contracts, context/result types, isolated runner, persistence repository/schema, Admin UI empty-state foundation, and shared-container wiring. Concrete checks, run/read endpoints, failure classification, scoring behavior, and live Diagnostics/Dashboard integration are not part of this baseline.

## Team Responsibilities

### Bernie — Lead Developer / WordPress UI Engineer

- Project lead and integration/architecture decisions.
- `admin/Pages/`, `admin/views/`, `admin/Components/`, and `assets/`.
- Pull-request coordination/review and merge decisions.
- Accessible Admin UI integration through approved backend contracts/read models.

### Saturn — Mail Transport Engineer

- `includes/Mail/` and `includes/Providers/`.
- SMTP/API transports, PHPMailer/provider logic, connectivity/authentication, SMTP/TLS diagnostics, and normalized provider/transport failures.

### Yaj — Backend Platform Engineer

- `includes/Database/`, `includes/Logging/`, `includes/Diagnostics/`, and `includes/Rest/`.
- Repositories, migrations, mail history, DNS diagnostics, scoring, backend REST, retention, and historical read models.

## How Development Continues

- Fetch and branch from the latest `develop`; never develop directly on `develop` or `main`.
- Use one developer → one ticket → one branch → one primary module → one pull request.
- Target feature and fix pull requests to `develop`; reserve `main` for releases.
- Do not casually edit another developer's module. Define shared contracts first and involve all affected owners in cross-module changes.
- Keep changes small, add focused tests, run the repository checks, and provide manual QA evidence proportionate to risk.
- Bernie coordinates review and decides when approved work is ready to merge.

## Remaining Dependencies

- UI integration depends on approved backend execution/read contracts and stable read models.
- Failure classification depends on concrete, normalized transport and diagnostic outputs.
- Health scoring depends on stable diagnostic evidence and an explainable scoring policy.
- Dashboard summaries depend on trustworthy diagnostics/scoring reads and explicit stale/error states.
- Reporting depends on stabilized diagnostics and scoring contracts.
- Retention and release hardening span migrations, scheduled tasks, data lifecycle, security, compatibility, and packaging, and should be completed as MVP capabilities stabilize.

## Recommended Continuation Order

This is dependency-aware guidance, not a mandatory sequence. Owners may work in parallel when contracts and dependencies permit.

1. Agree on normalized diagnostic IDs/results, secure context construction, run grouping, read models, and REST contracts.
2. Implement DNS checks and SMTP/TLS checks in parallel behind those contracts, with fixtures and safe error behavior.
3. Add orchestration, persistence, bounded reads, permissions, and execution/read endpoints.
4. Connect the Diagnostics Admin UI, then add Dashboard summaries using approved read models.
5. Build failure classification/remediation from stable outputs; implement explainable scoring after evidence semantics settle.
6. Add retention/history controls and MVP reporting on the stabilized data model.
7. Complete security, migration, compatibility, accessibility, clean-site, upgrade/uninstall, packaging, and release QA.

Alerts, agency/white-label features, additional API providers, SaaS monitoring, and AI diagnostics remain later roadmap work rather than prerequisites for this MVP sequence.

## Architecture and Security Checklist for the New Lead

Give deliberate lead and affected-owner review to changes involving:

- Shared interfaces, service-container registrations, hooks, or cross-module read models.
- Database schemas, version gates, retention, scheduled jobs, or uninstall behavior.
- REST routes, response contracts, authentication, authorization, or rate/timeout behavior.
- Capabilities, nonces, validation, escaping, redaction, credential handling, or privacy boundaries.
- Mail lifecycle terms, particularly any claim involving acceptance or delivery.
- Provider registration, transport independence, PHPMailer behavior, or normalized failures.
- New runtime/development dependencies, compatibility baselines, or packaging requirements.

Record durable decisions in `docs/adr/`. Verify that privileged operations use capability and nonce/REST permission checks; diagnostic and mail data exclude secrets and full message bodies by default; database access stays in repositories; queries are prepared and bounded; and output is escaped at the rendering boundary.

## Immediate Handoff Notes

- `develop` is ahead of `main` and is the authoritative integration baseline for continued MVP work.
- SMTP acceptance is represented as `Accepted`, never as guaranteed delivery.
- The Diagnostics page currently renders foundation states but deliberately has no enabled execution URL.
- The diagnostics and health-score tables are foundations; table presence is not feature completion.
- Current unit tests pass, with one known `$wpdb` warning in dispatcher tests. WPCS also exposes repository-wide CRLF line endings. These are quality-baseline items, not changes made by this handoff.
- Existing older documentation contains historical ownership and aspirational feature descriptions. Use `AGENTS.md`, `docs/PROJECT_STATUS.md`, current code/tests, and accepted ADRs as the operational baseline, and reconcile older documents in a separately scoped documentation update.
