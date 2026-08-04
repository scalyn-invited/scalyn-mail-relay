# Scalyn Mail Relay

Scalyn Mail Relay is a WordPress Email Operations Platform focused on delivery, diagnostics, monitoring, remediation and agency management.

> SMTP is the transport layer. Confidence, visibility and diagnostics are the product.

## Requirements
- WordPress 6.5+
- PHP 8.2+
- MySQL 8+ or MariaDB 10.6+

## Development setup
```bash
composer install
composer check
```

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
