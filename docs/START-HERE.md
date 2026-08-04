# Start Here

## Sprint 0
- Protect `main` and `develop`.
- Require PR review and passing CI.
- Replace placeholder CODEOWNERS entries with actual GitHub handles.
- Run `composer install` and `composer check`.
- Install the plugin on a clean WordPress 6.5+ test site.
- Confirm activation creates the foundation tables and administrator capabilities.

## First implementation sequence
1. Provider registry + reference SMTP provider.
2. Test connection and normalized `ConnectionResult`.
3. Test email using `MailMessage` and `SendResult`.
4. Logging repository and timeline event writer.
5. Mail logs REST read endpoint.
6. Dashboard integration.
7. DNS diagnostic checks.
8. Health scoring.

Do not build advanced reports, SaaS integration or AI diagnostics before this vertical slice is stable.
