# Provider health delivery aggregation - 2026-10-06

Owner: Bernie. Branch: feature/m8-provider-health-delivery.
Baseline: origin/develop abcb81c (provider PRs #70, #71 and #72 merged).

## Scope

Current-configuration/source delivery coverage and observed hard-bounce warnings
for Postmark, SMTP2GO and Brevo. Repository -> source lifecycle service ->
provider health read model -> active provider card. No schema change, mail
submission, credential changes or collection enablement. No Deliverability Score.

Numeric policy stays at strictly over 5%, minimum 20. To avoid treating missing
callbacks as successful outcomes, the denominator includes observed tracked
recipient attempts only; partial coverage cannot establish Healthy. This
conservative interpretation and its UI explanation need Bernie's review.
Disabled/unsupported collection explicitly leaves a limited connection/submission
assessment, not a delivery claim.

## Automated evidence

- PHPUnit: 1,510 tests / 5,661 assertions passed.
- Full WPCS: passed.
- PHP syntax: 287 files passed. Git diff whitespace check: passed.
- JavaScript regression: 7 tests passed.
- Temporary-table SQL smoke: passed with the actual local WordPress database
  adapter and MariaDB, using SHORTINIT (no plugin boot). Covers revision,
  provider/source isolation, duplicate and mixed events, missing, expired and
  future callbacks. All temporary tables removed; no existing tables/settings
  changed. Command: php tests/manual/provider-delivery-health-smoke.php.
- Unit/render tests cover >5% boundary, sample minimum, partial coverage,
  disabled/paused/unsupported/storage-error states, capability and revision
  gates, independent critical precedence, escaping and inactive-card isolation.

## Manual QA

Bernie reported visual QA passed on 2026-10-06 and requested PR publication.
This is owner-reported visual acceptance, not evidence of live webhook threshold
or retry testing. The checklist below remains the reproducible QA procedure.

1. With an approved staging source collecting for the active provider, open
   Mail Relay > Providers. Only the active card may show its aggregate.
2. With fewer than 20 observed recipient attempts, expect no bounce percentage
   and an insufficient-evidence explanation, not Healthy from missing callbacks.
3. In controlled fixtures, 1 hard bounce / 20 observed = 5% (no bounce warning);
   2 / 20 = 10% (Warning). Missing callbacks stay unknown and duplicate callbacks
   do not increase totals. Do not intentionally bounce real customer addresses.
4. Change configuration or source through the wizard: old evidence must not
   reappear as current. Disabled collection says unassessed; paused collection
   must not reuse the former source.
5. Check narrow/mobile and desktop layout, keyboard navigation and visible
   explanations/window text.
6. Complete the separate SMTP2GO/Brevo live delivery/bounce/retry webhook QA.
   User-confirmed sending QA is not webhook QA.

## Remaining / rollback

Visual QA passed (owner-reported); PR review and live webhook QA remain pending.
Category-based failures and numerical
Deliverability Score remain separate work. SendGrid live QA remains deferred.
Revert the code/UI slice to roll back; no data migration or deletion is needed.
Acceptance and recipient-server delivery never guarantee inbox placement.
