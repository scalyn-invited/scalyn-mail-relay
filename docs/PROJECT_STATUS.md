# Project Status

## Handoff Snapshot

- **Handoff date:** 2026-08-28
- **Repository:** `scalyn-invited/scalyn-mail-relay`
- **Verified `develop` HEAD:** `84e8cdda6e91891b83c4b9dd738318ddce736ed6`
- **Branch roles:** `develop` is the integration branch; `main` is release-only.
- **Current phase:** pre-release MVP development. The SMTP-to-observability vertical slice is implemented; actionable diagnostics, scoring, and release hardening remain.

This snapshot describes merged `origin/develop` at the handoff commit above. Re-verify the repository and history before using it as current status later.

## Completed Work

- Core integration foundation and modular service wiring (`f9b834b`, `e7d402f`).
- Dashboard and Setup Wizard framework (`fb2d484`).
- Built-in SMTP provider transport (`7b98093`).
- Live SMTP connection verification, configuration, and test-email workflow (`d3122f3`).
- Mail-log and append-only timeline persistence (`1c9dc7c`).
- Email Logs list and message timeline Admin UI (`d86174c`).
- Logging repositories registered as shared services (`759615b`).
- Latest mail result integrated into the Dashboard (`e841dd3`).
- Accepted-status disclaimer corrected to appear with accepted timelines (`6ee1f26`).
- Diagnostics execution and persistence foundation (`0d89b50`).
- Diagnostics Admin UI foundation and state/accessibility coverage (`acf33ca`).
- Diagnostics runner and repository registered in the shared container (`84e8cdd`).

## First Vertical Slice

The implemented mail vertical slice is:

`Configure SMTP → Verify Connection → Send Test Email → Mail Log → Timeline → Dashboard Result`

Automated unit coverage exists across the wizard, SMTP provider, dispatcher, logging repositories/subscriber, log pages, and dashboard. This is an implemented and unit-tested foundation, not a claim of production release certification. Manual clean-site WordPress QA and release hardening remain.

Lifecycle terminology is **Generated**, **Prepared**, **Connected**, **Authenticated**, **Sent**, **Accepted**, **Failed**, and **Retried**. SMTP success is recorded as `Accepted`: the provider acknowledged the message, but inbox delivery is not guaranteed. `Delivered` requires reliable out-of-band provider/webhook evidence.

## Diagnostics Foundation

The following exists on `develop`:

- `DiagnosticCheckInterface` for check identity, category, and execution.
- A minimal, read-only `DiagnosticContext` contract that prohibits credentials and secrets.
- Immutable `DiagnosticResult` values for status, severity, message, evidence, impact, remediation, optional score contribution, and raw data.
- `DiagnosticRunner`, which executes supplied checks in order and isolates thrown failures as normalized error results.
- `DiagnosticRepository`, with bounded recent reads and per-run lookup/persistence keyed by a shared diagnostic UUID.
- The `scalyn_diagnostics` table. Evidence, impact, and raw values are stored as structured JSON in `raw_result`; message and recommended action have dedicated columns.
- A foundation `scalyn_health_scores` table, but no scoring service or repository.
- Diagnostics Admin page/components covering capability checks, configuration states, empty results, cards, and accessibility. The Run Diagnostics action is intentionally disabled pending backend integration.
- Shared-container registration for `DiagnosticRunner` and `DiagnosticRepository`.

No concrete diagnostic check implementations are present. SPF, DKIM, DMARC, MX, and SMTP/TLS checks; credential-safe context construction; run orchestration/persistence; execution/read endpoints; REST integration; failure classification; health-score calculation; and populated UI/dashboard integration are not implemented.

## Remaining MVP Work

### DNS diagnostics

- Implement normalized SPF, DKIM, DMARC, and MX checks with safe, explainable evidence and remediation.
- Define domain/selector inputs, timeouts, unknown/error behavior, and test fixtures.

### SMTP/TLS diagnostics

- Implement connectivity, authentication, encryption/certificate, and relevant SMTP capability checks without exposing credentials.
- Normalize transport failures into stable categories that other modules can consume.

### Failure analysis and remediation

- Add deterministic failure classification based on concrete mail and diagnostic outputs.
- Provide evidence-backed impact and remediation guidance; distinguish unavailable evidence from passing checks.

### Health scoring

- Define a deterministic, explainable scoring policy and implement calculation/persistence/read services around the foundation table.
- Trace every contribution to stable diagnostic evidence and handle partial runs explicitly.

### Diagnostics execution and read integration

- Build a credential-safe context factory and run orchestration that groups and persists check results.
- Add capability-protected, validated, bounded execution/read contracts and REST endpoints.

### Dashboard and Diagnostics integration

- Connect approved backend read models/endpoints to the Diagnostics page and enable run actions securely.
- Surface current diagnostic and scoring summaries on the Dashboard with clear stale/empty/error states.

### Retention and hardening

- Implement and verify retention jobs for logs, timelines, diagnostic history, and scores.
- Harden permissions, nonces, REST permission callbacks, redaction, rate/timeout behavior, migrations, and uninstall consistency.

### Reporting and release hardening

- Add MVP reporting only after diagnostic and scoring contracts stabilize.
- Complete clean-site activation/upgrade/uninstall QA, supported WordPress/PHP/database compatibility testing, accessibility/manual UI QA, packaging, rollback notes, and release checks.

## Later / Out-of-Scope Roadmap

The following are not core current-MVP dependencies and should remain separate until the MVP is stable:

- Alerts and notification workflows.
- Agency and white-label management.
- Additional API mail providers.
- Centralized/SaaS monitoring.
- AI-assisted diagnostics or remediation.

Foundation schema/placeholders for some later capabilities do not mean those features are implemented.

## Quality Baseline

- Requirements: WordPress 6.5+, PHP 8.2+, MySQL 8+ or MariaDB 10.6+.
- CI runs Composer validation, PHP syntax checks, WordPress Coding Standards, and PHPUnit on PHP 8.2 and 8.3 for pushes to `develop` and pull requests to `develop` or `main`.
- Local handoff validation on PHP 8.3.14: 69 tracked PHP files passed syntax checks; PHPUnit passed **370 tests and 681 assertions**.
- PHPUnit currently reports one warning, triggered by 11 `MailDispatcherTest` cases where the logging subscriber reaches `MailLogRepository` without a test `$wpdb` global.
- WPCS currently fails because tracked production PHP files use CRLF while the standard expects LF. This repository-wide line-ending issue was observed but not changed during the documentation handoff.
- The standard Composer scripts require a `php` executable on `PATH`; the handoff environment required the explicit WAMP PHP binary.
- `git diff --check` was clean before documentation was written.
- Integration-test, QA, API, reports, alerts, REST, and security directories contain foundation documentation/placeholders rather than complete implementations where noted above.

## Known Architecture Decisions

- The plugin is a WordPress modular monolith with explicit module boundaries (`docs/adr/0001-modular-monolith.md`).
- Provider behavior is independent behind `ProviderInterface`; the built-in SMTP provider is registered through Core.
- The lazy shared container provides singleton-like service instances and is the integration boundary for runtime services.
- UUIDs correlate a mail log with its append-only timeline and group rows belonging to a diagnostic run.
- `Accepted` is not `Delivered`; delivery must not be inferred from SMTP or API acceptance.
- Diagnostic evidence and impact use structured persistence inside `raw_result` under the current frozen schema.
- Admin output is escaped at rendering boundaries, privileged pages/actions require capabilities, and inputs are sanitized/validated at their boundary.
- Full email bodies are not stored in mail logs by default; persisted operational data is limited to normalized metadata and allowlisted timeline fields.
- Data is retained on uninstall by default. Destructive uninstall cleanup requires explicit administrator opt-in.
