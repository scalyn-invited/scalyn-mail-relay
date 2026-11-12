# Admin page tabs — local QA

Date: 2026-10-13

## Scope

Presentation-only tab navigation on Agency Settings, Settings, Diagnostics, and Alerts & Recovery, reusing the Providers tab behavior. Work remains on `fix/m9-provider-tabs`; unrelated local Milestone 8/9 and branding work is preserved.

- Agency Settings: Managed Configuration, Access Profiles, Branding & Support.
- Settings: DKIM, Privacy & Retention, Monitoring, Uninstall, Cleanup Status.
- Diagnostics: Configuration Checks, Deliverability Score, Failures & Guidance, Header Analysis.
- Alerts & Recovery: Overview, Notifications, Email Recovery, Incident History.

Hash links, browser Back/Forward, keyboard navigation, and legacy section anchors select the appropriate panel. Without JavaScript, all sections remain available. Tab navigation does not save settings or select a sending provider.

Existing form actions, nonces, capabilities, managed-access restrictions, and field names are preserved. Privacy, retention, monitoring, and uninstall remain one atomic settings form; hidden fields stay enabled and drafts survive tab navigation. Validation reveals the panel containing an invalid field. Shared-form submission retains the selected tab hash.

## Automated validation

- PHP lint: 348 files passed.
- PHPUnit: 1,701 tests, 6,606 assertions passed.
- WPCS: passed.
- JavaScript syntax check: passed.
- JavaScript tests: 20 passed.
- Git diff whitespace checks: passed.

Focused tests cover panel placement, preserved protected forms, progressive enhancement, legacy links, history, keyboard navigation, shared-form drafts, and invalid-field panel selection. These results describe the combined local working tree, not an isolated release artifact.

## Local WordPress visual and interaction QA

Logged-in localhost desktop and 390 × 844 mobile browser checks passed for all four pages. Tabs and cards fit the mobile viewport. Desktop checks covered tab changes, direct and legacy links, keyboard Home navigation, and browser Back. Settings draft retention was checked without submitting: an unsaved retention edit survived navigation to Monitoring, all shared fields remained enabled, and the shared save control was hidden on Cleanup Status. Reload discarded the temporary draft.

No settings were submitted, access profiles granted, credentials changed, connections tested, or mail sent. Existing unrelated WordPress update notices were left unchanged.

Screenshots:

- `2026-10-13-agency-tabs-desktop.jpg`
- `2026-10-13-agency-tabs-mobile.jpg`
- `2026-10-13-settings-tabs-desktop.jpg`
- `2026-10-13-settings-tabs-mobile.jpg`
- `2026-10-13-diagnostics-tabs-desktop.jpg`
- `2026-10-13-diagnostics-tabs-mobile.jpg`
- `2026-10-13-alerts-tabs-desktop.jpg`
- `2026-10-13-alerts-tabs-mobile.jpg`

## Separate acceptance gates

- Staging: not deployed or tested in this change.
- Remote CI: not run; no PR requested in this turn.
- Owner acceptance: pending Bernie's review.
- Existing misleading Diagnostics overview wording remains a separate backlog item; this change only updates navigation-related guidance links.

No schema, persistence, transport, scoring, retention, or uninstall behavior changed. Rollback consists of reverting the tab presentation changes; no database rollback is required.
