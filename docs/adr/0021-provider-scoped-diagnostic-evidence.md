# ADR 0021: Current diagnostic evidence belongs to a configuration revision

Status: Implemented for Bernie's review following approval of provider-scoped diagnostics.

## Decision

Current health views (Dashboard, Diagnostics, wizard Health Check and Completion) must use a shared `CurrentDiagnostics` read model. A result is current only when its opaque configuration revision, provider identifier and sending domain match the selected configuration. Scores must share that run's UUID; never fall back to an older score when the new run has no score.

`SettingsRepository` rotates a random UUID when the selected provider changes (including switching back), its saved configuration changes, or the DKIM selector changes. The revision is persisted in the same option write as the configuration. Verification timestamps, test-email acknowledgements, retention and monitoring policy do not rotate it. Changing an inactive provider does not invalidate the active provider's evidence. Failed writes retain the previous revision. Older installations initialize a revision before their first scoped run.

The revision is not a hash of credentials. Credentials, ciphertext and credential-derived fingerprints never enter diagnostic context or result storage. Replacing an API credential rotates the active revision even when the replacement contains the same token, because encryption is randomized.

The context builder chooses the active provider's From domain, with the existing site-domain fallback. API providers receive no saved SMTP endpoint. SMTP-category checks run only for SMTP. API transport assessment is explicitly Not assessed here; wizard API verification remains a separate operation and is not converted into a diagnostic health pass. Recent operational score inputs and troubleshooting failures are filtered to the current provider; their retained historical time window is not a per-configuration send test.

## Storage and concurrency

Versioned, idempotent dbDelta migration 0.4.0 adds nullable `configuration_id`, `provider_id`, and `sending_domain` columns to `scalyn_diagnostics`, plus the `configuration_created` index. Existing rows remain unattributed historical evidence, not backfilled with guesses. The health table is unchanged: `score_uuid` already correlates to a diagnostic run.

Capture attribution before executing checks and persist it with every result in the existing atomic publication transaction. A run finishing after a provider/configuration change remains retained under its original revision. Current queries cannot select it. The REST adapter reports a safe 409 for a detected configuration change rather than returning the old score as current success. Failed/unknown checks remain isolated and are not promoted to passes.

No history is deleted. Existing history/reporting APIs retain their explicitly historical/site-wide semantics. Historical exports are not proof of the currently selected provider's health. No new credentials, permissions, transport requests, email sending or dependencies are introduced by the scoping mechanism.

## Rollback and lifecycle

Use an additive code rollback; do not drop the new columns or delete history. Older code ignores the columns but also restores the old misleading unscoped display, so prefer rolling forward. Retention and opt-in uninstall continue to operate on the same tables and run UUIDs. The normal admin upgrade verifies columns and index before advancing the database version.

## Acceptance and validation

- Switching provider, switching back, changing sender/SMTP details/active API credentials/DKIM selector invalidates current evidence.
- Inactive credentials, policy settings, verification and no-op saves preserve the current revision.
- Legacy rows, another domain/provider/revision, oversized runs and uncorrelated scores cannot enter current views.
- Postmark/SendGrid contexts never borrow SMTP settings; API runs omit SMTP checks.
- Runs completed after a switch are retained as history and cannot return a current score.
- Existing REST permissions, output escaping, atomic publication and history retention remain intact.

See `tests/unit/DiagnosticScopeTest.php`, `DiagnosticScopeMigrationTest.php`, `DiagnosticPublicationTest.php`, and `tests/integration/DiagnosticsEndpointTest.php`. Live QA must also check wizard step 6's empty state and a fresh run for the active sender domain; do not switch production sending routes solely to demonstrate invalidation.

### Local verification, 2026-10-01

- PHPUnit: 1,173 tests / 3,868 assertions passed, including configuration-change race and non-applicable SMTP recommendation coverage. JavaScript: 7 tests passed.
- Live WordPress: Diagnostics and wizard step 6 initially excluded the legacy run. Current attribution was Postmark / `myvirtual.services`, rather than the old SMTP sender domain.
- A single wizard health check completed at site time `2026-10-01 02:18:10`: four DNS checks, correlated score 63/100, DMARC pass, DKIM failure for the configured `default` selector, provider/transport not evaluated. SMTP checks and SMTP remediation were absent. No provider selection/credential changes or test emails were performed.
- Switching providers, switching back and changing credentials were tested with synthetic fixtures, not by changing the live sending route. Owner acceptance remains pending.
