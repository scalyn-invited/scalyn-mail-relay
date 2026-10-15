# AI chat failure handling repair

The previous implementation discarded actionable API failure categories twice: the REST handler replaced adapter errors with one generic response, and JavaScript discarded non-success response bodies. Model-list access was not evidence that paid inference would succeed.

The repair maps only fixed API status/code/type values to authored administrator messages for quota, rate, key/permission, model, network and service failures. Raw provider prose, credentials and unexpected exceptions remain private. No automatic retry, model switch, token-limit increase, settings change or billing action is performed. Failed questions remain in the composer.

GPT-5 and GPT-5 mini now use minimal reasoning and low verbosity within the existing 1,200-token cap; GPT-4.1 models do not receive unsupported reasoning options. Incomplete token-budget responses are rejected with explicit guidance, not persisted as answers. Official reference: https://developers.openai.com/cookbook/examples/gpt-5/gpt-5_new_params_and_tools

Mock regressions cover both API error code and type, safe REST propagation, frontend fixed-message rendering, unknown identifiers, question preservation, no retry, per-model parameters and incomplete output. Local verification passed: 1,781 PHPUnit tests / 6,896 assertions; 37 JavaScript tests; full configured WPCS; changed PHP/JavaScript syntax; diff checks. No hosted CI has been run for this uncommitted repair. After the administrator dismissed the stalled Chrome rename prompt, the browser showed Ready, an enabled composer and loaded conversation controls. The existing draft question was restored without submission. No additional remote request was made; a successful generated reply remains unverified.

CLI-only `tests/manual/ai-chat-preflight.php` defaults to no remote request. Its live flag must only be used after explicit administrator consent for transmitted data; one request is charged to the existing minute/day counters. It prints only credential-free readiness, allowlisted outcome categories and token counts, never chat text or remote error prose. No histories are loaded or changed.

No schema or retention changes. Revert the repair to restore prior behavior; no database rollback is needed. A successful generated reply remains an external live-acceptance gate. Owner review and any hosted CI for a subsequently published change remain separate.
