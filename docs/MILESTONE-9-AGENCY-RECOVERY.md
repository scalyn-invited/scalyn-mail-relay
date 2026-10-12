# Milestone 9 implementation handoff

Owner: Bernie. Approved plan: 2026-10-12. New providers are excluded.

## Sequence and current state

1. Managed settings and access profiles: implemented locally on
   `feature/m9-t1-managed-access`; owner/live visual acceptance pending. ADR 0031.
2. Branding and simplified client dashboard/reports: pending.
3. Named configurations, revisions and evidence attribution: pending.
4. Explicit source/type attribution and ordered routing: pending.
5. Logical-message/per-attempt correlation and recovery safety evidence: pending.
6. Opt-in encrypted retry queue and conservative failover: pending.
7. Recovery UI, lifecycle, retention and integrated acceptance QA: pending.

Use one focused ticket/branch/PR per change; no automatic merges. Existing
Milestone 8 failure-category and ADR-0026 work is preserved and uncommitted.
Its schema ordering and acceptance gates must survive integration.
`origin/develop` was verified at `71a12bd` on 2026-10-12.

## Approved recovery boundaries

All administrators override managed locks. Routing matches explicit source/type
(WordPress password reset, CF7, WooCommerce, registered integrations), not message
subjects or address-domain conditions. Unknown sources use an explicit default.

Recovery is off by default. Opt-in encrypted message/attachment storage expires
within 24 hours, separate from metadata-only logs. At most three submissions
across up to three ordered configurations, minimum 1/5-minute delayed retries,
respecting longer provider delays. Permanent, accepted, partial and unconfirmed
outcomes stop. Worker crashes after submission might have begun require review.

Durable queue ownership may return true from `wp_mail()` only with recovery
enabled. It is not Accepted. No cross-system exactly-once guarantee is made.
Routes/revisions are pinned; configuration changes do not silently reroute queued
mail. Missing or stale evidence never becomes healthy.

## QA and staging

Run PHP lint/WPCS/PHPUnit, Composer validation, JS and diff checks per ticket.
Keep isolated fixtures, real WordPress/database checks, visual QA, remote CI,
provider evidence and owner acceptance distinct.

Bernie approved `https://jarmerskitchen.newwebsite.live/` for controlled tests and
plugin-only uploads, and confirmed database/plugin backups are ready. Confirm
restorability before deployment, preserve credentials and unrelated files, and
ask privately for missing access or approved recipients. No customer mail or
volume testing. Rollback must first stop recovery workers; never replay uncertain
attempts.

Browser automation currently fails before opening a surface because its Windows
sandbox helper is missing. Live visual QA and file-manager deployment are blocked;
local implementation/CLI checks can continue. No staging upload has occurred.
