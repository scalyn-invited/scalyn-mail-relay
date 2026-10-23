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
terminal-event logging, administrative audit history and hourly bounded retention are implemented.
Opt-in scheduled diagnostic execution is available in Data Controls; monitoring
freshness/status is displayed on Dashboard and Diagnostics. Backend execution status
records UTC start/finish times and fixed failure stages, separately for scheduled
runs. Complete diagnostic runs and their
computed health snapshots now publish atomically (requires InnoDB tables).
The `0.1.1-qa.2` candidate also includes alerting/recovery, API providers,
reporting, AI and agency screens; their availability in source does not establish
end-to-end acceptance on the staging site. See [Project Status](docs/PROJECT_STATUS.md)
and the [alert-engine QA record](docs/qa/alert-engine-repair.md). This is a QA
candidate, not a production release: staging migration, scheduled evaluation,
recipient safety and affected live flows remain open. Provider acceptance is
not confirmed inbox delivery, and a high health score does not prove full
authentication coverage or deliverability.

## Historical 0.1.0 limitations

Recorded with rationale in
[ADR-0002](docs/adr/0002-mvp-release-hardening-accepted-risks.md).

- **Scheduled diagnostics are opt-in.** Data Controls offers disabled (default),
  hourly, twice daily and daily. One run per tick, no overlap/backfill, at most
  20 checks and a 20-second cooperative check budget. Active network calls cannot
  be interrupted. WP-Cron requires traffic or a server trigger. Independent
  alerting remains planned; see [monitoring operations](docs/MONITORING-OPERATIONS.md).

- **Retention depends on WP-Cron.** Mail Relay → Data Controls configures 1–3650
  days (default 30) and shows cleanup status. Hourly ticks remove up to 100 mail
  aggregates, 100 complete diagnostic runs, 100 health snapshots and 100 audit rows. Large
  backlogs drain over later ticks. Low-traffic sites need server-triggered WP-Cron.
  Orphan timelines and alerts are outside this policy. Audit records expire
  individually, so parts of a correlated operation can expire at different times.
- **Audit history is not a tamper-proof ledger.** Mail Relay → Audit History
  requires settings-management permission. It records user IDs, execution context,
  fixed outcomes, UUIDs and changed field names, not values or message content.
  Recording failures or interruptions can leave incomplete trails.
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

## Current project governance

Jay directs current integration and release decisions for this repository through
the `scalyn-invited` owner account; Bernie is no longer in the project review
path. Older milestone records preserve their historical ownership context, not
current approval requirements. Module boundaries remain architectural boundaries;
cross-module work is allowed within a focused task. See `AGENTS.md` for current
engineering guidance.

## First vertical slice
1. Configure provider.
2. Verify provider connection.
3. Send test email.
4. Record normalized mail log and timeline.
5. Display result in the dashboard.
6. Run SPF/DKIM/DMARC/MX diagnostics.
7. Generate explainable health score.

See `docs/ENGINEERING-HANDBOOK.md` and the v5.0 Engineering Handbook for architecture and release gates.
