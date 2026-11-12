# Milestone 9 ticket 2 QA — branding and client summaries

Owner: Bernie. Branch: `feature/m9-t2-client-branding`, stacked on ticket 1
commit `06cee73`. Existing uncommitted Milestone 8 changes are preserved.

## Scope

- Agency branding/support form with repository authorization and nonce boundary.
- Fixed identity projection, raster attachment validation and scoped color CSS.
- Client-safe dashboard, Summary Reports entry and protected summary CSV.
- Strict stale/unknown/unavailable semantics and value-free audit events.
- Default retention and opt-in uninstall cleanup.

## Automated evidence

Focused tests cover invalid color/HTML/type/URL/email/logo input; authorization,
nonce and failed persistence; audit privacy; page scoping/escaping; conservative
freshness, counts and fixed CSV projection; formula neutralization; client versus
administrator/agency view selection. Validation totals are recorded after the
final check run below. These are local checks, not remote CI.

Executed validation:

- Full combined-worktree PHPUnit: 1,624 tests / 6,222 assertions passed.
- Focused branding/client/managed-access/uninstall suite: 91 tests / 303 assertions passed.
- PHP syntax: 317 repository PHP files passed; full WPCS passed.
- JavaScript syntax and 10/10 interaction tests passed.
- Composer strict validation and `git diff --check` passed.
- Both real WordPress CLI smoke checks passed: managed capability filtering and
  the client dashboard/read model, using a synthetic actor. No account/option
  writes or mail sends were performed by those harnesses. The client harness
  initially lacked WordPress's admin template helpers; loading the same helpers
  used by actual admin requests resolved that test-harness error.

The native Media Library picker updates only an attachment ID and validates
raster types in JavaScript and again on the server. It does not save until the
protected form is submitted and does not grant WordPress media permissions.

## Manual acceptance still required

Browser automation cannot initialize because its Windows sandbox helper is
missing. Under the computer-use skill's failure recovery rule, live visual QA
and file-manager deployment remain blocked. No staging upload or mail send has
occurred for this ticket. PHP view assertions are not visual QA.

When browser access is restored:

1. In Agency Settings save valid branding. Confirm the identity/support strip
   on each plugin page, no changes to other WordPress screens, sensible wrapping
   on mobile, keyboard focus visibility and readable colors.
2. Check absent/deleted logos and reset defaults. Confirm invalid colors/links
   produce safe feedback and leave previous settings unchanged.
3. Assign Client Viewer to a separate existing non-admin account. Confirm only
   the simplified dashboard and summary report are accessible, without technical
   evidence, recipients, configuration controls or operational actions.
4. Download CSV and compare counts/times against the displayed report. Verify
   stale and unknown states; an unavailable read must not show zero.
5. Attempt direct access to settings, technical exports and diagnostic actions
   as the client; confirm denial. Confirm administrator override still works.

Tickets 3–7, including multiple configurations, routing and recovery, are pending.

## Subsequent visual retry

Browser access was restored on 2026-10-12. Local administrator visual checks of
Agency Settings, Diagnostics and Dashboard passed the sampled desktop/mobile
layout and overflow checks; the logo picker opened/closed without saving.
Client-role and saved-branding acceptance remain pending. The previous blocker
above describes the earlier run, not the current browser state. See the
[visual retry record](2026-10-12-m9-visual-retry.md) for scope and findings.
