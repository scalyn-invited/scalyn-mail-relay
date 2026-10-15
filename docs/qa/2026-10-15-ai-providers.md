# Model-first Gemini and Claude verification

Implementation branch: `feature/m10-multi-provider-ai`, dependent on the unmerged Milestone 10 foundation. Earlier local chat-error repairs were preserved. No PR publication, merge or deployment has been performed for this expansion.

## Behavior

The starter catalogue includes existing OpenAI choices, Gemini 3.5 Flash-Lite/3.8 Flash and Claude Haiku 5.5/Sonnet 5.5. Catalogue visibility does not imply account access or free inference. Keys, verified models and consent are independent per provider; invalid setup remains disabled server-side. A provider change cannot reuse a nonempty chat, and assistant messages record provider/model provenance. Legacy OpenAI options normalize without writes on reads or ciphertext changes; explicit saves persist the version-2 profile structure. MCP, mail settings, retention and database schema are unchanged.

Official API inspection identified different Claude 5.5 thinking controls: Haiku uses disabled; Sonnet requires between_tools, not disabled. Both use low effort within the existing 1,200-token cap, without tools or automatic retries. Gemini uses minimal thinking and structured output. Mock payload tests cover these differences; live acceptance remains separate.

## Local evidence

- Focused AI regression: 82 tests / 245 assertions passed, including the protected form ignoring inactive-provider key fields, read-only legacy normalization, encryption contexts, independent consent, replacement invalidation, account-visible model intersection, vendor transport/header/schema constraints, safe failure mapping and cross-provider chat refusal.
- JavaScript: 43 tests passed, including model-panel visibility, all enable prerequisites, draft preservation, replacement/removal, fixed provider labels and no network activity from settings changes.
- Disposable SQL: 12 checks passed; temporary fixture tables removed; zero AI API calls and zero mail sent.
- Chrome connection initially failed; after the user reconnected it, rendered desktop Gemini and mobile Claude settings were inspected. Only the selected fieldset was visible/enabled. Unconfigured chat and model verification stayed disabled. Keyboard Tab moved from Model to the Claude key field. At a 390 px viewport, page width was 375 px. The override was reset and the isolated QA tab closed.
- No keys, consent or settings were entered/saved during visual QA; original OpenAI configuration was left intact. Local screenshots are excluded from public publication: `2026-10-15-ai-gemini-settings-desktop.png` and `2026-10-15-ai-claude-settings-mobile.png`.

Full local regression passed: 1,798 PHPUnit tests / 6,977 assertions; 43 JavaScript tests; full configured WPCS; syntax checks for 374 PHP and 14 JavaScript files; git diff checks. Hosted CI has not run for this local expansion. No live Gemini/Claude keys or API requests were used. Successful live replies, real-model adversarial evaluations and owner acceptance remain outstanding. Enter credentials privately in WordPress, never in chat; explicit data-sharing permission is required before live tests.

## Rollback

Disable AI before reverting this feature. Preserve the encrypted profiles option and history. Single-provider old code cannot interpret version-2 profile settings; restore the backed-up earlier option if intentionally downgrading. No database rollback or deletion is required.
