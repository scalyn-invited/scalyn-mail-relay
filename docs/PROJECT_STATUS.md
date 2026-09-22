# Project Status

## Current verified status — 2026-09-21

Update — 2026-09-22: all Milestone 4 implementation tickets are complete locally.
Dashboard/Diagnostics now show monitoring failures, overdue schedules and separate
evidence freshness. Real isolated WP-Cron verification passed. See
[Milestone 4 completion](qa/2026-09-22-milestone-4-completion.md).
Next is Milestone 5 (alerts), not started. The notes below retain earlier context.

Milestone 3 is merged in 8b12a65 (PR #45). Milestone 4 tickets 1–4 are implemented
locally on feature/m4-monitoring-orchestration: shared runs, opt-in cadence,
overlap protection, cooperative bounds and atomic diagnostic/health publication.
See [foundation verification](qa/2026-09-21-monitoring-foundation.md) and
[publication verification](qa/2026-09-21-atomic-diagnostic-publication.md).
Ticket 4 adds [durable execution status](qa/2026-09-21-diagnostic-execution-status.md).
Next is ticket 5: stale, overdue and failed monitoring UI. The following
Milestone 3 paragraph records its earlier pre-merge validation context.

Milestone 2 is merged in `513d02e` (PR #44). All Milestone 3 implementation tickets
are implemented locally on `feature/m3-audit-foundation`: audit capture,
actor/source attribution, protected paginated history and bounded expiry.
See [Milestone 3 verification](qa/2026-09-21-milestone-3-completion.md).
Next is Milestone 4, scheduled health monitoring. Older
baseline descriptions below are retained as historical verification context.

The local baseline is established, **not approved for release**. Reviewed source
is based on `origin/develop` merge `513d02e`, with local uncommitted Milestone 3
changes. Remote CI has not run for these uncommitted changes.

Implemented: SMTP configuration/connection/test sending; terminal Accepted/Failed
logs and timelines; SPF, MX, DKIM, DMARC and SMTP/TLS checks; credential-free
context and isolated runner; capability-protected REST execution; grouped
diagnostic persistence; HealthScorer and HealthScoreRepository; populated
Diagnostics/Dashboard views; retained-data and opt-in destructive uninstall.
Run Diagnostics is enabled. Claims below that these were missing describe the
historical August handoff, not current code.

September 17 local verification passed clean activation, SMTP acceptance with
user-confirmed Inbox receipt, diagnostic persistence and score recalculation,
UUID-correlated mail history, and isolated retain/delete/reactivation checks.
PHPUnit passed 673 tests / 1347 assertions, with PHP lint and WPCS passing.
Historical warning/CRLF failures below no longer describe this baseline.

Important gaps remain: 100/100 can coexist with unknown DKIM; SPF record presence
does not prove outbound-IP authorization; snapshots lack historical score input
and run linkage; concurrent/partial diagnostic runs need hardening; timelines
are terminal-event only and wizard source attribution is blank. Further
scheduled monitoring, alerts, reporting, API providers and
agency features remain roadmap work. Schema placeholders are not features.

The local MariaDB 10.4.32 is below the required 10.6. Supported compatibility,
packaged-candidate installation, live security/failure-path/accessibility checks,
and Bernie's release review remain open. CI targets PHP 8.2/8.3 but is not a
live WordPress/database matrix. Historical September 8 evidence describes a
different environment, not fresh candidate certification.

See [Milestone 1 completion](qa/2026-09-21-milestone-1-completion.md) for the
baseline evidence and release gates. Milestone 2 tickets 1–2 now define and
implement bounded transactional mail-log/timeline cleanup.
Ticket 3 verifies partial-failure rollback and cross-process resumption. Ticket 4
adds complete-run diagnostic cleanup and explicitly independent health-snapshot
cleanup. Tickets 5–8 add hourly scheduling, database lock protection, cleanup
status, validated Data Controls, explicit uninstall confirmation and shared
lifecycle hooks. See [Milestone 2 completion evidence](qa/2026-09-21-milestone-2-completion.md).
Milestone 2 is merged and Milestone 3 is implemented locally.
Final review and release approval remain outstanding.
Follow [the checklist](IMPLEMENTATION-CHECKLIST.md).
No release is authorized here.

## Current Ownership

As confirmed on 2026-09-16, Bernie is the sole project owner and developer for
all modules, implementation, architecture, QA, review, and release decisions.
Former team members are no longer assigned to the project. Historical handoff
and module-owner references do not create ongoing approval dependencies.

## Historical Handoff Snapshot — August 28

- **Handoff date:** 2026-08-28
- **Repository:** `scalyn-invited/scalyn-mail-relay`
- **Verified `develop` HEAD:** `84e8cdda6e91891b83c4b9dd738318ddce736ed6`
- **Branch roles:** `develop` is the integration branch; `main` is release-only.
- **Current phase:** pre-release MVP development. The SMTP-to-observability vertical slice is implemented; actionable diagnostics, scoring, and release hardening remain.

This snapshot describes merged `origin/develop` at the handoff commit above. Re-verify the repository and history before using it as current status later.

## Historical Completed Work at Handoff

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

## Historical First Vertical Slice

The implemented mail vertical slice is:

`Configure SMTP → Verify Connection → Send Test Email → Mail Log → Timeline → Dashboard Result`

Automated unit coverage exists across the wizard, SMTP provider, dispatcher, logging repositories/subscriber, log pages, and dashboard. This is an implemented and unit-tested foundation, not a claim of production release certification. Manual clean-site WordPress QA and release hardening remain.

Lifecycle terminology is **Generated**, **Prepared**, **Connected**, **Authenticated**, **Sent**, **Accepted**, **Failed**, and **Retried**. SMTP success is recorded as `Accepted`: the provider acknowledged the message, but inbox delivery is not guaranteed. `Delivered` requires reliable out-of-band provider/webhook evidence.

## Historical Diagnostics Foundation — Superseded

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

## Historical Post-Handoff MVP Update — September 8

The planned 0.1.0 MVP product capabilities are implemented on `origin/develop`
at `ed87103`. Work completed since the handoff snapshot includes:

- SPF, MX, DKIM, DMARC, and SMTP/TLS diagnostic checks with normalized,
  credential-safe results.
- Credential-safe diagnostic context construction, check registration, isolated
  execution, grouped persistence, and the capability-protected diagnostics REST
  endpoint.
- Deterministic health-score calculation, persistence, and Admin read paths.
- Diagnostics results and Dashboard integration, including secure run actions,
  evidence/remediation presentation, empty/error states, and current score data.
- Deterministic transport-failure classification with stable categories and
  remediation guidance.
- Release hardening for capabilities, inactive cron hooks, line endings,
  packaging boundaries, and provider/diagnostic regressions.

This update considered the original 0.1.0 feature scope implemented. It is not
release approval; the current verification gaps and owner decisions are listed
in the September 21 completion record above.

### Live environment validation

- Activate on a clean WordPress installation and verify all six tables, version
  options, seven Administrator capabilities, Admin pages, and the complete setup
  wizard without PHP notices or warnings. Foundation table creation remains the
  principal automated-test gap.
- Exercise both uninstall modes against a real database and confirm retained or
  deleted data, options, capabilities, and cron hooks match the documented
  policy. Upgrade testing is not applicable to this first release.
- Complete live security and privacy checks for capabilities, nonces, REST
  authorization, error redaction, diagnostic evidence, logs, rendered HTML, and
  `debug.log`.
- Run the provider and diagnostics regression against real SMTP and DNS targets,
  including safe failure paths, correlated mail history, persisted health
  scores, and rendered remediation.
- Confirm supported WordPress, PHP, and MySQL/MariaDB behavior and complete
  accessibility/manual UI QA.

### Packaging and release

- Build the production ZIP with non-development Composer dependencies, verify
  its inclusion/exclusion boundary and secret-file hygiene, and install it on a
  clean site.
- Obtain the required review, merge `develop` to release-only `main`, record
  rollback notes, create the release tag, and publish the GitHub release with
  the verified ZIP.
- Re-run every automated and manual gate in `docs/RELEASE-CHECKLIST.md` against
  the exact release candidate before tagging.

### Explicit post-MVP deferrals

- Automated retention for mail logs, timeline events, diagnostic results, and
  health scores remains the first post-MVP feature; no cleanup cron is scheduled
  in 0.1.0.
- A destructive-uninstall Admin UI, multisite support, and broader reporting
  remain outside the 0.1.0 MVP, as recorded in ADR-0002.

## Later / Out-of-Scope Roadmap

The following are not core current-MVP dependencies and should remain separate until the MVP is stable:

- Alerts and notification workflows.
- Agency and white-label management.
- Additional API mail providers.
- Centralized/SaaS monitoring.
- AI-assisted diagnostics or remediation.

Foundation schema/placeholders for some later capabilities do not mean those features are implemented.

## Historical Handoff Quality Baseline — Superseded

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
