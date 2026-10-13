# ADR-0026 Deliverability Score — implementation QA

Owner: Bernie. Branch: `feature/m8-deliverability-score`.
The existing uncommitted failure-category implementation was preserved. Schema
0.11.0 follows its 0.10.0 migration; do not publish this slice without that
preceding migration. No commit, push, PR or merge was requested for this turn.

## Implemented

- Separate Deliverability Score on Dashboard and Diagnostics; Email Health is unchanged.
- Accepted 35/15/10/40/0/0 weights; pass/warn/fail points 100/50/0.
- All three coverage gates, exact 2%/5% thresholds, opt-in bounce minimums,
  unavailable/stale exclusion and retained 30-day history scoped to revision/provider.
- Minimal, replayable assessment traces and fixed-enum header verdict/alignment
  storage. Header-quality/reputation scoring remains unavailable; no raw headers
  or recipient/message data enter the new evidence table.
- Protected explicit refresh, interactive diagnostics refresh, fail-closed reads,
  bounded retention and administrator-opt-in uninstall deletion.

## Automated evidence

- Focused policy/repository/service/UI tests cover gates, thresholds, stale/future
  evidence, corrupted trace rejection, configuration scope, privacy and nonce/capability checks.
- Full PHPUnit regression: 1,543 tests / 5,968 assertions passed.
- `php tests/manual/deliverability-smoke.php`: passed with real WordPress/MariaDB
  dbDelta, idempotent schema creation, current-revision/provider counts, replay,
  minimal header verdict persistence and retention. Uses uniquely prefixed
  disposable plugin tables, removes them afterward, sends no mail and does not
  alter provider settings or installed schema/version options.
- PHP lint: 301 repository files passed. Full WPCS passed; Composer strict
  validation passed; JavaScript syntax and 7/7 admin-interface tests passed;
  `git diff --check` passed. These are local results, not a remote CI claim.

## Live visual QA — pending, not passed

The browser launcher failed before opening a surface (`sandbox setup helper
unavailable`). The computer-use skill file was also absent at its advertised
path. Escalated CLI checks worked; markup tests are not live visual acceptance.
No staging/production settings, mail sends or provider accounts were changed.

1. Open the local WordPress admin as a settings administrator so the normal
   verified migration path can install schema 0.11.0. Do not change providers
   just to generate a score. Keep the normal backup/deployment process for staging.
2. Dashboard and Diagnostics must show a separate **Deliverability Score
   (evidence-based estimate)** card. Existing Email Health remains a configuration
   score. Native WordPress navigation remains unchanged.
3. With fewer than 20 current-revision submissions, click **Refresh Deliverability
   Score**. Expect **Not assessed**, explicit missing evidence, the component
   weights, coverage, freshness, exclusions and ranked recommendations.
4. Run Diagnostics. With two or more fresh eligible authentication checks, at
   least 20 retained Accepted/Failed submissions and at least 60% weight coverage,
   both pages show the same persisted score. Do not send bulk mail just for QA;
   controlled fixtures/unit tests cover the numerical sample boundaries.
5. Change a sending configuration through the wizard. Old diagnostics/history
   must not produce a score for the new revision. New diagnostics alone do not
   bypass the required submission sample.
6. Check short retention, stale evidence, failed/unavailable checks and API-provider
   reverse-DNS exclusion. None may become a pass or failure merely because missing.
7. With delivery collection off/paused or insufficient callbacks, verify the
   submission-only explanation. Missing callbacks never count as bounces. When
   eligible, hard-bounce denominator is observed recipients, not all tracked recipients.
8. Analyze headers from a message you sent to your own inbox. Raw pasted text is
   cleared and never echoed. The notice explains minimal verdict retention and
   that it does not affect scoring. No inbox-placement claim should appear.
9. Test keyboard access, disclosures, button/nonce feedback, card spacing and
   narrow-screen overflow. Accept the visual result only after reviewing it live.

## Rollback and lifecycle

The new table is additive; old health/log/diagnostic data is not rewritten.
Code rollback should leave this table retained and must not lower the installed
database version or remove the earlier 0.10.0 migration. No destructive downgrade
script is provided. Existing scheduled cleanup and explicit uninstall policy own
the new history. No new cron hook, dependency or external reputation API is added.
