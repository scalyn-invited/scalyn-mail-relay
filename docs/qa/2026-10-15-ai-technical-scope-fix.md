# Technical email chat correction — 2026-10-15

## Cause and changes

The local scope gate rejected the reported `what to be fixed?` before inference. It also lacked health-score and several technical-email terms. Rejected user questions disappeared from subsequent context, and intentionally omitted refusals incorrectly triggered the history-limit notice. Valid replies always displayed every evidence section first, regardless of the question.

ADR 0040 records the owner's clarified technical-only scope: setup, diagnostics, delivery, authentication, security and troubleshooting, not drafting. The gate now accepts contextual diagnostic/setup questions; corrected technical questions can re-enter bounded history without replaying refused assistant replies. Actual window/size truncation is tracked separately. The shared vendor instructions request question-specific explanations and safe steps. Evidence includes fixed setup and check-remediation guidance, never raw diagnostic/provider text. Observations remain server-generated and use relevant verified source sections. UI puts explanation and steps before observed evidence.

## Validation layers

- Local automated: final full PHPUnit 1,801 tests / 6,997 assertions and all 43 JavaScript tests passed. Full configured WPCS passed, followed by a clean final changed-file WPCS run. Syntax checks passed for 375 PHP and 14 JavaScript files; final scope edits were exercised by PHPUnit and WPCS. Diff checks passed.
- Live integration: owner approved two requests using a new synthetic chat and current redacted plugin summaries. Both reached the selected OpenAI adapter and returned `ai_quota`. No successful generated answer was obtained, no automatic retry occurred, no existing conversation was sent. The test chat was deleted after the tests. Available API quota/billing remains an external gate; do not claim live success.
- Browser: refreshed local page showed the technical-only scope wording and ready chat form. Subsequent preview verification was interrupted by `Debugger unattached`; no completed visual acceptance of the revised reply order is claimed. The disposable preview fixture was removed.
- Hosted CI: not run for these uncommitted changes; prior foundation CI does not validate this fix. No production deployment or merge.

Prior stored replies are preserved, not rewritten. Reload the page to load updated assets. Do not paste an API key into chat; enter credentials privately in Settings. Key presence/model-list verification does not prove inference quota.
