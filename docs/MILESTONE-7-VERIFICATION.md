# Milestone 7 — Remaining-ticket verification

Owner: Bernie. Date: 2026-09-30. Baseline: merged PR #56, `origin/develop`
`8e23836`. Implementation is organized as dependent local ticket branches:

- `feature/m7-t5-sendgrid-outcomes`: safe rejection/rate-limit/uncertainty rules.
- `feature/m7-t6-mail-correlation`: correlated private logs and observer isolation.
- `feature/m7-t7-provider-verification`: shared contracts and format-gap fixes.

No branch was merged, pushed or opened as a PR by this implementation request.
Review/integrate in that order; later branches contain the preceding commits.
Bernie's implementation, SMTP behavior and ADR-0018 review remain required.

## Ticket 7 implementation

`ProviderContractTest` runs the same assertions against the real SMTP and
SendGrid adapters with fake transport boundaries. It verifies network-free
configuration validation, non-sending connection probes, UTF-8 plain/HTML,
display names, CC/BCC/Reply-To, a local attachment, safe Accepted outcomes,
pre-network rejection and uncertainty without retry. Existing provider-specific
tests retain status matrices, API limits, encryption, switching and privacy checks.

Parity testing closed actual gaps rather than accepting silently altered mail:

- SMTP now uses PHPMailer's CC/BCC/Reply-To APIs. Plain addresses and unquoted
  display names are supported; multiple/repeated Reply-To and injection fail.
- Unsupported structural SMTP headers, content types and missing/nonlocal
  attachments fail before a network connection. SMTP still accepts safe custom
  headers; SendGrid keeps its narrower documented allowlist and size limits.
- SendGrid detects duplicate allowlisted headers case-insensitively and supports
  omission of the optional sender name.
- SMTP explicitly connects before `send()` to distinguish known pre-submission
  failures from uncertain send exceptions. It reuses the established connection
  and closes it on exit. Any exception after sending starts is conservatively
  unconfirmed, including partial-recipient failures; exception text alone cannot
  prove non-acceptance. No automatic retry/failover was added.

Neither adapter claims arbitrary MIME, inline/CID images, multiple body parts or
all RFC address syntax. Ordinary WordPress interception remains SendGrid-only,
as in ticket 4; SMTP's existing direct-dispatch/wizard workflow remains the SMTP
verification path. No silent transport fallback or provider switching occurs.

## Local and live evidence

- Full local PHPUnit passed: **1,050 tests / 3,098 assertions** on PHP 8.2.12.
  All **181** non-vendor PHP files passed syntax checks. Full WPCS, strict
  Composer validation and `git diff --check` passed. Fixed persistence/observer
  warnings from intentional failure fixtures are expected; no test failed.
- `php tests/manual/provider-mime-check.php <WordPress-root>` checks the real
  WordPress-bundled PHPMailer without booting WordPress, reading credentials or
  making network requests. Local plain/HTML MIME, attachment, recipient roles
  and absence of BCC in visible MIME all passed. This is preparation evidence,
  not an SMTP acceptance test.
- Live SMTP `test_connection()` with saved settings: succeeded on 2026-09-30.
  This checks connection/authentication only, not message acceptance or receipt.
- Live SendGrid sandbox with saved settings: failed with HTTP 401 and the exact
  recognized credits-exceeded error. The new fixed guidance correctly points to
  Email API credits, plan and billing. No provider body or secret was displayed.
- No actual email was sent during this work. Active provider, credentials,
  account plan/billing and DNS were not changed. WP-Cron was disabled for the
  WordPress-bootstrap probes. Earlier inbox confirmations are historical evidence,
  not proof that this revised milestone passes current live QA.
- Remote CI has not run on these local commits. Prior PR #56's CI does not
  certify these changes.

## Outstanding completion gate

1. Resolve the SendGrid Email API allowance restriction with the account owner;
   do not repeatedly replace a valid key or infer authentication failure from 401.
2. Re-run the sandbox probe and record validation separately from actual sending.
3. With the owner's approved controlled inbox, send one plain message and one
   HTML message with a harmless local attachment through each transport. Use
   fresh UUIDs and the normal dispatcher/wizard workflow; do not resend an
   uncertain attempt before checking provider activity.
4. Confirm sender, subject/content encoding, attachment readability, recipient
   roles and the corresponding private log/timeline. Record Accepted separately
   from the recipient's Inbox/Spam/absence report. Inbox receipt does not make
   future messages guaranteed deliveries.
5. Review the tightened SMTP header behavior with installed integrations and
   repeat wizard/WordPress browser QA. Complete Bernie review and remote CI
   before release. Ticket 7 and the milestone completion gate stay unchecked
   until this evidence exists.

## Migration, security and rollback

No database, dependency, capability, REST, credential storage or retention change.
No message content is persisted. Failed preparation uses a content-free envelope;
logs keep fixed safe messages and approved fields. Webhook authentication and
delivery evidence remain Milestone 8; automatic recovery remains Milestone 9.

Behavior compatibility: callers that relied on silently dropped SMTP headers
now receive failure; use the message's structured fields or supported recipient
headers. Roll back the ticket commits in reverse order if needed; no data
migration is required. Reverting only ticket 7 restores the old SMTP header
limitations. Review active provider selection before reverting earlier SendGrid
registration/workflow changes. Preserve retained evidence when rolling back.

The unrelated local release-readiness PDF remains untouched and uncommitted.
