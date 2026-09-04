# Scalyn Mail Relay

Scalyn Mail Relay is a WordPress Email Operations Platform focused on delivery, diagnostics, monitoring, remediation and agency management.

> SMTP is the transport layer. Confidence, visibility and diagnostics are the product.

## Requirements
- WordPress 6.5+
- PHP 8.2+
- MySQL 8+ or MariaDB 10.6+
- Single-site WordPress. **Multisite is not supported in 0.1.0.**

The WordPress and PHP minimums are enforced at activation and are verified in CI
against PHP 8.2 and 8.3.

## Known limitations in 0.1.0

Recorded with rationale in
[ADR-0002](docs/adr/0002-mvp-release-hardening-accepted-risks.md).

- **Log retention is not enforced.** The `log_retention_days` setting is stored
  but no purge job runs, so mail logs, timeline events, diagnostic results and
  health scores accumulate without bound. On a high-volume site, prune these
  tables manually until retention ships.
- **Destructive uninstall has no admin UI.** Data is retained on uninstall by
  default; opting into deletion requires setting the option programmatically.
  See [UNINSTALL-POLICY.md](docs/UNINSTALL-POLICY.md).
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
- `includes/Core` — Lead Developer / Solution Architect
- `includes/Mail`, `includes/Providers` — Mail Transport Engineer
- `includes/Database`, `includes/Logging`, `includes/Diagnostics`, `includes/Rest` — Backend Platform Engineer
- `admin`, `assets` — WordPress UI Engineer

## First vertical slice
1. Configure provider.
2. Verify provider connection.
3. Send test email.
4. Record normalized mail log and timeline.
5. Display result in the dashboard.
6. Run SPF/DKIM/DMARC/MX diagnostics.
7. Generate explainable health score.

See `docs/ENGINEERING-HANDBOOK.md` and the v5.0 Engineering Handbook for architecture and release gates.
