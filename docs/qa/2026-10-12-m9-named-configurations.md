# Milestone 9 ticket 3 — named configuration QA

Date: 2026-10-12. Owner: Bernie. Branch: `feature/m9-t3-named-configurations`.
Local dependency: tickets 1–2 (`06cee73`, `5d35fb6`); develop fetched and verified
at `71a12bd`. Existing uncommitted Milestone 8 work is preserved, not included
in a ticket-3 commit or PR. No commit, push or PR was created in this turn.

## Automated and database evidence

- Full combined-worktree PHPUnit: **1,650 tests, 6,329 assertions passed**.
- New configuration tests cover import idempotence, unchanged active revision,
  same-provider isolation, create-without-selection, rename, archive rules,
  immutable revisions, selector scope, SMTP/API encryption, key-rotation recovery,
  missing-key legacy sending, managed locks, public markup, stale form/action
  snapshots, late progress and concurrent legacy-import writes.
- Webhook regression covers Postmark, SMTP2GO and Brevo: another named revision
  pauses new-send tracking, retains original-source historical ingress and can
  restore the unchanged original binding on explicit reselection. Existing
  receiver tests cover authenticated source/provider/message membership and
  uncorrelated/ambiguous callbacks. No real provider webhook was submitted here.
- PHP syntax: 324 source/test files passed; WPCS and diff checks passed.
- Both JavaScript test files: **10/10 passed**; syntax checks for both scripts passed.
- Composer strict validation passed. No dependency, SQL schema or DB-version change.
- `tests/manual/named-configurations-smoke.php` passed against real local
  WordPress/database APIs using a disposable options table and process-local
  cache. It verifies import, selection, revision restoration, encrypted storage,
  archiving and stale-writer rejection. The disposable table was removed and
  original cache/user/table bindings restored. Actual settings were unchanged;
  no mail or provider API calls. The harness refuses external object caches.

Intentional fault-injection tests emit fixed audit/log failure messages; these
are not live-site failures or proof of provider behavior. Combined-worktree
results do not establish that unrelated pending Milestone 8 work is integrated.

## Live local visual evidence

Used the existing administrator browser session, without submitting settings:

- Providers: saved-configuration import/add controls rendered on desktop and
  390×844 mobile; disclosure opens and labels/actions are readable.
- Wizard step 2: same controls, seven-step navigation and native WordPress
  navigation retained. Corrected the gap before the legacy chooser to 24px.
- No horizontal document/card overflow: desktop client width 1144px and mobile
  client width 375px matched scroll widths. Mobile card overflow count was zero.
- Sample keyboard Tab reaches the new configuration-name field with visible
  focus. This is not a complete accessibility audit.
- Viewport override reset; existing tab left on Providers. Actual provider
  remains Brevo; import, selection, saves, sends and diagnostics were not run.
- Third-party update notices were left untouched.

Screenshots: [Providers desktop](2026-10-12-m9-configurations-desktop.jpg),
[wizard mobile](2026-10-12-m9-configurations-mobile.jpg).

## Remaining acceptance / limitations

- Bernie should perform populated browser workflow QA: import, add a second
  configuration for the same provider, select/configure/verify, rename, switch
  back and compare evidence; check a stale tab and archive a non-default.
- Do not select an unconfigured default while relying on live site mail.
- New configurations require server encryption. A marked legacy SMTP revision
  is attribution-only, not a replayable recovery snapshot.
- One webhook source per provider remains the existing limitation. A new source
  uses explicit disable/remove/recreate; sources are never silently rebound.
- No staging upload, controlled live send/webhook, PR or remote CI verification.
- Prior client-role and saved-branding acceptance remains pending. The unrelated
  misleading Diagnostics assessment copy is unchanged for its separate fix.
- Rollback after encrypted import requires the matching settings/database backup,
  not a code-only downgrade. See ADR 0033 for lock recovery and retention rules.
