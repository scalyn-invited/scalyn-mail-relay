# Scalyn Mail Relay

Scalyn Mail Relay is a WordPress Email Operations Platform focused on delivery, diagnostics, monitoring, remediation and agency management.

> SMTP is the transport layer. Confidence, visibility and diagnostics are the product.

## Requirements
- WordPress 6.5+
- PHP 8.2+
- OpenSSL PHP extension for protected credential storage.
- Automatic local keys require verifiable private-file ownership/permissions and
  atomic hard-link publication on the WordPress content filesystem. Unsupported
  or unwritable storage fails closed; a separately managed external key remains
  an advanced alternative, not a required normal-install step.
- MySQL 8+ or MariaDB 10.6+
- Single-site WordPress. **Multisite is not supported in this candidate.**

The WordPress and PHP minimums are enforced at activation. CI runs checks on
PHP 8.2 and 8.3; it is not a live WordPress/database compatibility matrix.

## 0.1.1 support scope

The supported first-release workflow is single-site Custom SMTP, ordinary
WordPress mail, metadata Email Logs and terminal timelines, Dashboard visibility,
and manual diagnostics. Advanced routing and recovery are optional and disabled
by default; ordinary SMTP sending does not require them.

API providers, webhooks, routing/recovery, agency and AI surfaces remain in the package
unchanged. They are outside this SMTP-first acceptance scope, not removed or
newly certified by the 0.1.1 package. Multisite is unsupported. External provider
acceptance never proves inbox delivery. Visual/mobile/keyboard accessibility,
every SMTP/TLS failure mode and every supported-version combination have not
been exhaustively certified. A production-version ZIP is not a release approval;
publication and deployment require Jay's separate authorization.

## Current implementation status

SMTP sending, manual DNS/SMTP diagnostics, persisted health scoring, and
terminal-event logging, administrative audit history and hourly bounded retention are implemented.
Opt-in scheduled diagnostic execution is available in Data Controls; monitoring
freshness/status is displayed on Dashboard and Diagnostics. Backend execution status
records UTC start/finish times and fixed failure stages, separately for scheduled
runs. Complete diagnostic runs and their
computed health snapshots now publish atomically (requires InnoDB tables).
Version `0.1.1` retains QA4/QA5 fixes: selected Custom SMTP handles ordinary `wp_mail()`
synchronously without enabling advanced routing or recovery. Earlier mail
short-circuits are preserved; SMTP failure never silently falls back to PHP mail.
Other API-provider routing behavior is unchanged. Use only one mail-control plugin.

Fresh SMTP and provider credential writes use protected storage even before a
named-configuration catalogue exists. On supported Linux filesystems an authorized
settings save initializes a private site key automatically; no manual server-key
or cPanel step is required. A page view, mail send or background task never
initializes a key. Storage failures block the write rather than store plaintext.

The separate key is under `wp-content/scalyn-mail-relay-private/`, outside the
plugin directory. Back up this directory together with the database. Database-only
backups cannot recover encrypted credentials. Private permissions and executable
PHP guards do not protect against an attacker controlling WordPress/PHP/files.
A PHP server misconfigured to serve PHP source is not protected by those guards.
Missing/corrupt existing keys are never automatically replaced. An explicitly
managed `SCALYN_MAIL_RELAY_ENCRYPTION_KEY` remains an advanced option: old external
keys must be retained for old `v1` envelopes; local `v2` envelopes retain their
local-key dependency even after an external override is configured. Neither key
source is a transparent rotation/migration mechanism.

Existing legacy plaintext slots remain readable without being rewritten on GET;
the next authorized SMTP save/import must protect them or fail unchanged.
The separate key artifact is retained even during explicit database-data deletion
on uninstall so backup recovery remains possible; retire it manually only after
checking retained backups and encrypted records. See
[ADR 0042](docs/adr/0042-install-and-use-smtp-and-local-credential-key.md),
[uninstall policy](docs/UNINSTALL-POLICY.md), [Project Status](docs/PROJECT_STATUS.md)
and the [QA4 verification record](docs/qa/smtp-and-credential-protection-qa4.md).
The production-version package retains the reviewed QA5 runtime, with version
metadata updated. Earlier installed acceptance retains its original artifact
identity; it does not establish every installed byte or future inbox delivery.
Scheduling uses WordPress cron without a required cPanel setup; low traffic or
host restrictions can delay work and must not be presented as a fixed cadence.
Custom SMTP is a transport option, not a scheduler fallback. Provider acceptance is
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
