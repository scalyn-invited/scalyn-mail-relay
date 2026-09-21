# Scalyn Mail Relay

Scalyn Mail Relay is a WordPress Email Operations Platform focused on delivery, diagnostics, monitoring, remediation and agency management.

> SMTP is the transport layer. Confidence, visibility and diagnostics are the product.

## Requirements
- WordPress 6.5+
- PHP 8.2+
- MySQL 8+ or MariaDB 10.6+
- Single-site WordPress. **Multisite is not supported in 0.1.0.**

The WordPress and PHP minimums are enforced at activation. CI runs checks on
PHP 8.2 and 8.3; it is not a live WordPress/database compatibility matrix.

## Current implementation status

SMTP sending, manual DNS/SMTP diagnostics, persisted health scoring, and
terminal-event logging and hourly bounded retention are implemented. Scheduled monitoring,
alerts, API providers, and agency management remain roadmap work. See
[Project Status](docs/PROJECT_STATUS.md) and the
[Milestone 1 evidence](docs/qa/2026-09-21-milestone-1-completion.md).
The local baseline is verified, not release-certified. Provider acceptance is
not confirmed inbox delivery, and a high health score does not prove full
authentication coverage or deliverability.

## Known limitations in 0.1.0

Recorded with rationale in
[ADR-0002](docs/adr/0002-mvp-release-hardening-accepted-risks.md).

- **Retention depends on WP-Cron.** Mail Relay → Data Controls configures 1–3650
  days (default 30) and shows cleanup status. Hourly ticks remove up to 100 mail
  aggregates, 100 complete diagnostic runs and 100 health snapshots. Large
  backlogs drain over later ticks. Low-traffic sites need server-triggered WP-Cron.
  Orphan timelines, alerts and audit history are outside this policy.
- **Uninstall retains data by default.** Data Controls requires separate explicit
  confirmation to enable permanent deletion. Deactivation always retains data.
  See [UNINSTALL-POLICY.md](docs/UNINSTALL-POLICY.md) and
  [Milestone 2 evidence](docs/qa/2026-09-21-milestone-2-completion.md).
- **Multisite is unsupported.** Uninstall only removes data for the site that
  runs it.

## Development setup
```bash
composer install
composer check
```

`composer check` runs PHP linting, WordPress Coding Standards and PHPUnit. All
three must be clean before a PR.

## Releasing

Follow [docs/RELEASE-CHECKLIST.md](docs/RELEASE-CHECKLIST.md), which covers the
supported baseline, fresh install, upgrade, both uninstall modes, security and
privacy review, provider/diagnostic regression, and the packaging procedure with
its verification steps.

## Branch model
- `main` — releases only
- `develop` — integration branch
- `feature/<ticket>-<slug>` — feature branches
- `fix/<ticket>-<slug>` — non-production fixes
- `hotfix/<slug>` — urgent production fixes

Never commit directly to `main` or `develop`.

## Module ownership

Bernie is the sole project owner and developer across Core, Mail, Providers,
Database, Logging, Diagnostics, REST, Admin, assets, and future modules. Former
team members have no continuing assignments or required review responsibilities.
Module boundaries remain architectural boundaries; cross-module work is allowed
within a focused task. See `AGENTS.md` for current engineering guidance.

## First vertical slice
1. Configure provider.
2. Verify provider connection.
3. Send test email.
4. Record normalized mail log and timeline.
5. Display result in the dashboard.
6. Run SPF/DKIM/DMARC/MX diagnostics.
7. Generate explainable health score.

See `docs/ENGINEERING-HANDBOOK.md` and the v5.0 Engineering Handbook for architecture and release gates.
