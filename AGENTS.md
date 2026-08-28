# Repository Engineering Instructions

## Product Principle

Scalyn Mail Relay is a WordPress email operations platform. SMTP is a transport layer; the product provides reliable sending, visibility, diagnostics, and actionable remediation. Never represent provider acceptance as confirmed inbox delivery.

## Source of Truth

Before coding, inspect the current repository, relevant tests, and merged `origin/develop` history. Use this order when sources disagree:

1. Current code and migrations on the target branch.
2. Automated tests and CI configuration.
3. Accepted architecture decisions in `docs/adr/`.
4. This file and current status/handoff documents.
5. Other repository documentation.

Do not rely on tickets, prompts, branch names, or historical descriptions without verifying them against the repository.

## Architecture and Ownership

The plugin is a WordPress modular monolith with explicit contracts and a shared, lazy service container. Keep provider-specific behavior behind `ProviderInterface`; keep persistence behind repositories; keep Admin code dependent on approved services/read models rather than direct table or transport access.

- **Bernie — Lead Developer / WordPress UI Engineer:** project and integration decisions, `admin/Pages/`, `admin/views/`, `admin/Components/`, `assets/`, pull-request coordination/review, and merge decisions.
- **Saturn — Mail Transport Engineer:** `includes/Mail/` and `includes/Providers/`; SMTP/API transport, PHPMailer/provider behavior, authentication/connectivity, SMTP/TLS diagnostics, and normalized transport failures.
- **Yaj — Backend Platform Engineer:** `includes/Database/`, `includes/Logging/`, `includes/Diagnostics/`, and `includes/Rest/`; repositories, migrations, logs/timeline, DNS diagnostics, scoring, REST, retention/history.
- **Kim — Outgoing Lead Developer / Solution Architect:** previous owner of the completed foundation and this handoff; no continuing assignment.

One developer should normally own one ticket, one branch, one primary module, and one pull request. Do not casually edit another owner's module. Agree on contracts first for cross-module work, keep changes narrowly scoped, and request review from every affected owner. Bernie decides integration and architecture questions after handoff.

## Development Workflow

- Start from the latest `develop`; never commit directly to `main` or `develop`.
- Use `feature/<ticket>-<slug>`, `fix/<ticket>-<slug>`, or `hotfix/<slug>` as appropriate.
- Target normal pull requests to `develop`; `main` is release-only.
- Keep commits focused and use imperative Conventional Commit subjects, such as `feat(diagnostics): add SPF check` or `fix(admin): escape diagnostic evidence`.
- Do not merge, rewrite shared history, force-push, or change repository settings without explicit authorization.
- Pull requests must explain scope, ownership, dependencies, security/privacy effects, schema or contract changes, test evidence, manual QA, and rollback/migration considerations.

## Mail Lifecycle

Use only these established lifecycle terms: **Generated**, **Prepared**, **Connected**, **Authenticated**, **Sent**, **Accepted**, **Failed**, and **Retried**. `Accepted` means the configured provider acknowledged the message. Use `Delivered` only when reliable out-of-band provider/webhook evidence confirms delivery; never infer it from SMTP success.

## Security and Privacy

- Never commit or expose passwords, API keys, OAuth tokens, authorization headers, customer-sensitive data, or local credentials.
- Never put secrets in logs, exception messages, diagnostic context/evidence/raw data, REST responses, exports, reports, or UI markup—even masked secrets can disclose too much.
- Do not store full message bodies by default. Persist only the minimum allowlisted metadata required for operations; minimize recipient and message content exposure.
- Enforce WordPress capabilities and nonce or REST permission checks for every privileged action. Sanitize input, validate identifiers and domains, use prepared queries/repositories, and escape at the final rendering boundary.
- Keep diagnostic errors safe and actionable without leaking SQL, internal exceptions, credentials, or provider responses containing secrets.

## Database and Lifecycle Rules

- Schema changes belong in versioned, idempotent migrations using the existing `dbDelta()` lifecycle; bump the database version deliberately.
- Never edit an applied schema casually, query owned tables from Admin views, or bypass repositories.
- Preserve UUID correlation for message timelines and diagnostic runs, bounded reads, append-only timeline semantics, and explicit retention behavior.
- Activation, deactivation, scheduled hooks, uninstall retention, and optional deletion must remain consistent. Destructive uninstall behavior requires explicit administrator opt-in.

## Diagnostics Principles

Checks implement `DiagnosticCheckInterface`, consume a credential-free `DiagnosticContext`, and return normalized `DiagnosticResult` values. Checks must be isolated so one failure does not abort a run. Persist explainable status, severity, evidence, impact, remediation, and score inputs only when supported by real observations. Scoring must be deterministic and traceable to persisted evidence; unknown or unavailable data must not be presented as a pass.

## Testing and AI-Assisted Engineering

For every change, inspect nearby contracts and tests first. Add or update focused tests, then run the repository checks (`composer check`, or the equivalent PHP lint, WPCS, and PHPUnit commands in the local environment) plus `git diff --check`. Perform proportionate WordPress/manual QA for UI, database, cron, provider, and REST changes. Do not weaken tests or standards to make a change pass.

Human and AI coding agents must stay within the requested scope and primary module, preserve unrelated work, avoid speculative architecture, never invent repository state, and report assumptions and validation accurately. Production code changes require implementation evidence, not documentation claims.

## Definition of Ready

Work is ready when its user outcome and acceptance criteria are clear; owner, module, dependencies, and affected contracts are identified; security/privacy and migration implications are understood; and required test/QA evidence is defined.

## Definition of Done

Work is done when implementation and tests satisfy the acceptance criteria; lint, WPCS, PHPUnit, and diff checks have been run with results reported; security/privacy, accessibility, migration, retention, and lifecycle effects are addressed; documentation is updated where behavior changed; and the pull request has appropriate owner and lead review.

## Architecture Changes

Changes to shared interfaces, the service container, database schema, REST contracts, security or capability models, lifecycle terminology, provider architecture, or dependencies require deliberate review by Bernie and affected module owners. Record durable architectural decisions in `docs/adr/`; do not redesign the architecture incidentally inside a feature.
