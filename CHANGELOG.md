# Changelog

All notable changes to Scalyn Mail Relay will be documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-04

First MVP release: configure a mail provider, send through it, see what happened,
and diagnose it when it fails.

### Added

**Core**
- Modular monolith bootstrap: service container, singleton `Plugin`, PSR-4
  autoloading with a source-tree fallback, and a `scalyn_mail_relay_booted`
  extension point for module-owned registration.
- Seven granular capabilities (`scalyn_mail_relay_*`), granted to Administrator
  on activation.
- Versioned schema migrations via `dbDelta()` behind a DB version gate.
- Settings repository with typed accessors, defaults and sanitization.

**Mail transport**
- SMTP provider built on the WordPress-bundled PHPMailer, behind
  `ProviderInterface`.
- Mail dispatcher with normalized `SendResult`, and a failure classifier that
  maps transport errors to stable categories with remediation suggestions.
- Connection test and test-send, neither of which persists or echoes credentials.

**Logging**
- Mail log and append-only timeline, correlated by `message_uuid`.
- Email Logs admin screen with pagination, filtering and a detail view.

**Diagnostics**
- SPF, MX, DKIM and DMARC DNS checks.
- SMTP reachability, STARTTLS and certificate diagnostics. The probe never
  authenticates, and reports "unknown" rather than "fail" when a transient
  failure leaves no real evidence.
- Explainable health score derived from diagnostic results and recent mail
  history.
- `POST /wp-json/scalyn-mail-relay/v1/diagnostics/run`, gated on
  `RUN_DIAGNOSTICS`.

**Admin**
- Dashboard, Diagnostics, Email Logs and Providers screens.
- Five-step setup wizard: provider → SMTP settings → connection test → test
  email → verification. Every state-changing step is capability- and
  nonce-checked.
- Shared UI components: status badge, diagnostic result card, evidence display,
  empty state, step indicator.

### Security
- Every admin screen and REST route is capability-gated; every state-changing
  admin POST verifies a nonce.
- All database access is via prepared statements.
- Credentials, tokens and full email bodies are never persisted to log or
  diagnostic tables, never returned in REST responses, and never rendered in the
  admin. Diagnostic error responses carry no exception text.

### Known limitations

Recorded in
[ADR-0002](docs/adr/0002-mvp-release-hardening-accepted-risks.md):

- **Log retention is not enforced.** `log_retention_days` is stored but no purge
  job runs; tables grow without bound. First post-MVP ticket.
- **Destructive uninstall has no UI.** The opt-in flag is programmatic-only; see
  [UNINSTALL-POLICY.md](docs/UNINSTALL-POLICY.md).
- **Multisite is unsupported.** Uninstall operates on a single site's prefix.

### Release hardening
- Uninstall now revokes every granted capability from every role in delete mode.
  Previously `scalyn_mail_relay_*` capabilities persisted on the Administrator
  role after the plugin and all of its data were removed.
- No recurring cron event is scheduled. `scalyn_mail_relay_cleanup_logs` and
  `scalyn_mail_relay_run_daily_diagnostics` were being scheduled daily with no
  listener registered for either. Activation now also clears events left behind
  by an earlier install.
- Added `.gitattributes`: `eol=lf` normalization, and `export-ignore` rules that
  keep tests, docs and development tooling out of a release archive.
- Documented the packaging procedure and its verification steps.
