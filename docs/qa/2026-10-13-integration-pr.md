# Pending Milestone 8-9 integration and admin UI

Owner requested publication of all pending implementation on 2026-10-13. The current branch includes seven checkpoint commits for Milestone 9 and shared integration edits required by preserved Milestone 8 work. This combined PR snapshot includes those dependencies; individual checkpoint commits are not independently validated release artifacts.

## Scope

- Provider-health failure classification and revision-keyed evidence.
- ADR-0026 deliverability assessment, persisted minimal header verdicts and lifecycle integration.
- Milestone 9 managed access, branding/client summaries, named immutable configurations, explicit routing, logical attempts and opt-in encrypted recovery.
- Admin tabs, provider catalogue/drawer, contextual help/highlights, branding/menu refinements, log filters/page sizes, audit page sizes and report provider selection.
- CI now checks every admin JavaScript file and all JavaScript tests.

## Validation and boundaries

Local automated regression: PHPUnit 1,716 tests / 6,719 assertions; JavaScript 30 tests; full configured WPCS passed. PHP syntax and diff checks are recorded in the publication run. Prior local browser and disposable WordPress evidence remains in the dated QA notes; not every backend or role scenario was repeated during publication.

Local screenshots are intentionally excluded from this public repository publication because they can contain operational or recipient information. Screenshot references in dated notes refer to local evidence, not guaranteed repository assets.

Remote CI status must be checked on the published head. No merge, staging deployment, production deployment or new mail/provider test is authorized by this publication. Client-role, saved-branding, populated recovery workflows and controlled HTTPS provider acceptance remain outstanding where identified in the milestone handoff. Existing owner visual acceptance is not equivalent to these backend integration gates.

Initial hosted CI identified a timezone-dependent test fixture: the WordPress date stub uses the PHP default timezone, while a deliverability query test assumed the local machine's UTC+2 clock. The test now explicitly selects UTC and restores the previous timezone in a finally block, preserving exact boundary assertions and preventing host-specific results. No production scoring behavior changed.

## Compatibility, security and rollback

The combined schema sequence includes provider failure categories, deliverability assessments, submission attempts and recovery ownership/queue storage (through database version 0.13.0). Follow the accepted ADRs and existing idempotent migration lifecycle. Historical evidence retains revision meaning; unknown/stale data is not silently healthy. Recovery remains opt-in and encrypted; privileged actions retain capability and nonce protection. Provider acceptance is never represented as inbox delivery.

Back up database and plugin before deployment. A code rollback does not reverse migrations or recover deleted retention data. Preserve retained evidence and settings; review queued recovery work before downgrading. Optional uninstall deletion remains administrator opt-in. No former-team approval dependency applies; Bernie owns review and integration.
