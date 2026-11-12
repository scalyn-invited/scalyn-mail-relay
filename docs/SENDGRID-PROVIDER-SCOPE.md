# SendGrid provider scope — Milestone 7 ticket 1

Owner: Bernie. Decision: [ADR-0016](adr/0016-sendgrid-first-api-provider.md).
This is the implementation target, not a list of features already shipped.

## Supported message target

| Area | Initial adapter scope |
| --- | --- |
| Authentication | API key restricted to Mail Send; no OAuth or account-management access required. |
| Sender | One verified From address with optional display name; production domain authentication configured by the operator. No DNS mutation. |
| Body | One UTF-8 plain-text or HTML body using the current message contract. No automatic multipart alternative generation. |
| Recipients | To plus correctly parsed CC/BCC header recipients; preserve recipient roles and never expose BCC through logging. Validate before HTTP; never silently drop recipients. |
| Reply-To | One address with optional display name parsed from headers; unsupported multiple-address forms fail clearly rather than silently changing behavior. |
| Attachments | Readable local files from existing message paths, bounded before reading/encoding. No URL downloads or stream wrappers. Missing/unreadable/oversized attachments fail before sending. |
| Other headers | Explicitly allowlisted non-structural headers only; reject CR/LF injection and transport overrides. Document unsupported meaningful headers before release. |
| Correlation | Carry the plugin message UUID as opaque provider custom metadata; do not forward arbitrary message context or customer data. Validate/bound any provider identifier before retention. |
| Tracking | Do not advertise tracking capability in Milestone 7. Disable optional open/click tracking in requests; do not bypass provider suppressions. |

Inline/CID images, arbitrary MIME, templates, bulk campaigns, provider-scheduled
sends, regional EU routing and multiple active configurations are deferred.
Reject unsupported message formats explicitly; do not silently convert them.
The initial advertised flags are `html` and `attachments`, once tested.

SendGrid documents a maximum of 1,000 recipients across To/CC/BCC and a message
size below 30 MB including attachments. These are ceilings, not promised plugin
capacity. Ticket 3 must set conservative tested byte/count budgets accounting
for base64/JSON overhead and PHP memory before allocating payloads. Do not split
oversized sends into multiple requests implicitly. Account quotas and rate
limits vary; do not encode a universal requests-per-second promise.

## Verification and outcomes

- Local configuration validation makes no network calls.
- A protected connection check uses a synthetic sandbox request, never customer
  content. Sandbox HTTP 200 means request validation only, not an accepted email;
  it must not set the accepted-test flag or emit mail-send lifecycle events.
- An explicitly requested real test send uses the ordinary send path. Normal
  Mail Send HTTP 202 maps to `Accepted`, never `Delivered`.
- Map configuration/authentication/request errors to fixed, safe guidance. Never
  copy provider response bodies, authorization headers or exceptions into logs,
  reports, diagnostic evidence or the UI.
- Rate limiting must be recognizable without automatic resend. A timeout,
  connection loss after transmission or uncertain server result must say that
  acceptance is unconfirmed. Ticket 5 must review the boolean result contract
  and downstream reporting so uncertainty is not described as proven rejection.
- Do not manufacture SMTP Connected/Authenticated observations for an HTTP
  request. Emit only lifecycle evidence the integration actually observes.

## Current repository gaps and affected modules

Baseline: merged `origin/develop` commit `3f1b59b` (PR #55).

- `ProviderInterface`, `ProviderRegistry`, `MailDispatcher`: retain the provider
  boundary and lazy registration; Admin must not make transport calls directly.
- `SettingsRepository`: provider configuration currently routes SMTP only;
  protect new credentials without changing unrelated SMTP settings. Verification
  and accepted-test flags must be invalidated when the active configuration changes.
- `MailMessage` / SMTP: CC, BCC and Reply-To are currently structural headers
  skipped by SMTP. Define parser behavior and tests before claiming equivalent
  support. Shared fixes, if needed, require explicit scope/contract review; do not
  hide the existing SMTP limitation behind a new provider capability claim.
- `SendResult` / failure classification / logging: review certainty, response
  identifiers and normalized safe outcomes together in tickets 5–6.
- Admin/REST/audit: reuse established capabilities and nonce/permission checks;
  credential changes and verification need safe attribution, never secret values.

Milestone 8 must establish authenticated event correlation, replay protection,
duplicate handling and per-recipient evidence. A message UUID or provider ID alone
does not prove a webhook is authentic or that every recipient received a message.
No webhook receiver is introduced by this ticket.

## Acceptance and verification plan

Ticket 1 is satisfied by the recorded provider choice, capabilities, explicit
limitations, contract gaps and implementation/test sequence. Runtime readiness
requires all remaining Milestone 7 tickets, including:

- Credential save/replace/remove tests, unauthorized actions and secret-leak tests.
- Payload tests for sender/display names, Unicode content, plain/HTML, recipient
  roles, Reply-To, attachments, invalid headers and bounded input handling.
- Mock HTTP tests for sandbox/202, auth errors, invalid requests, rate limits,
  server errors, malformed responses and ambiguous network outcomes.
- Tests that switching providers invalidates verification and prevents stale
  accepted-test status; shared SMTP/API regression tests with honest capability
  coverage and correlation/privacy assertions.
- Controlled live sending to an owner-approved inbox using owner-provided
  credentials outside chat/source control. Record provider acceptance separately
  from recipient confirmation. No live sends or account changes in ticket 1.

Ticket 1 validation: current contracts, nearby unit tests and CI workflow reviewed;
documentation checked against official sources linked in ADR-0016. Automated
baseline results are recorded below after execution. No UI/database manual QA is
required for this documentation-only change. Bernie's detailed scope review and
merge/release approval remain outstanding.

Local baseline verification (PHP 8.2.12, 2026-09-27):

- PHP syntax: 171 tracked PHP files passed.
- WPCS: passed using `phpcs.xml.dist`.
- PHPUnit: 980 tests, 2,331 assertions passed. Intentional failure-path fixtures
  emitted persistence warnings; no test failures occurred.
- `git diff --check`: passed. No production code or tests changed in this ticket.
- Remote CI was not run; no commit, push or pull request was requested.
