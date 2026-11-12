# Providers tabs — local implementation and QA

## Scope

Branch: `fix/m9-provider-tabs`. Presentation-only navigation on Providers; existing uncommitted Milestone 8/9 and branding work is preserved. No schema, provider contract, credential, permission or lifecycle changes.

- Providers contains the active sending route, provider cards, status explanations and switching guidance.
- Email Routing contains the existing protected routing form.
- Saved Configurations contains the existing import, create, rename, select and archive workflows.
- Links use `#providers`, `#email-routing` and `#saved-configurations`. Missing, unknown or inaccessible hashes show Providers. Clicking a tab updates browser history without submitting forms; Back/Forward and reload restore the selected section.
- Existing capabilities still determine whether routing/configuration sections are rendered. JavaScript adds tab semantics, roving keyboard focus and panel visibility. Without JavaScript, all authorized sections remain available.
- Routing submissions retain `#email-routing`; configuration actions returning to Providers retain `#saved-configurations`. Successful provider selection still follows the existing wizard redirect.
- Current branding and native WordPress navigation are inherited unchanged; no brand settings were saved during QA.

## Automated results

Local combined worktree, not an isolated deployable checkpoint:

- PHP syntax: 347 files passed.
- WPCS: passed using `phpcs.xml.dist`.
- PHPUnit: 1,698 tests / 6,583 assertions passed (includes two new Providers tests).
- JavaScript: 18 tests passed (includes seven new tab-navigation tests); new script syntax passed.
- `git diff --check`: passed.

New tests cover section placement, capability-based omission, no-script content availability, read-only page rendering, routing action hash, initial/direct/unknown hashes, history navigation, keyboard arrows/Home/End/Space, modified clicks and retained form objects.

## Live local browser QA

Verified at the authenticated localhost Providers page:

- Providers, Email Routing and Saved Configurations show only the selected panel.
- Active sending route remains first under Providers; observed default remains Brevo.
- Back/Forward restores routing/configuration tabs; reloading a configuration hash restores that tab.
- An unsaved routing checkbox remains checked after switching away and Back; reload discards the draft. No Save button was submitted.
- Home keyboard navigation returns to Providers.
- Unknown hash falls back to Providers.
- Desktop and 390 × 844 mobile inspection passed. All three tabs fit the mobile width; routing fields remain within the page. Temporary viewport override reset.
- No provider selection, configuration import/edit/archive, mail send, connection test, branding save or routing save was performed.

Evidence: `2026-10-12-provider-tabs-desktop.jpg`, `2026-10-12-provider-tabs-mobile.jpg`.

## Remaining acceptance boundaries

No staging deployment or remote CI run in this ticket. Owner acceptance remains pending. Save/redirect behavior is covered by rendering checks and code review, not a live settings mutation. Existing workflow tests remain green; this QA does not claim fresh populated-configuration or client-role owner acceptance, nor completion of all Milestone 9 release requirements.
