# ADR-0016: SendGrid as the first API provider

Date: 2026-09-27. Owner: Bernie, sole project owner and developer.
Status: Provider selection approved by Bernie; capability and implementation
policy below recorded for review. Ticket 4 has registered the adapter for normal
WordPress sending; live provider verification remains outstanding.

## Context and decision

Milestone 7 ticket 1 selects SendGrid (`sendgrid`) from the architecture's Phase 2
providers. Use its v3 Mail Send API behind the existing `ProviderInterface`.
API-key authentication provides a bounded first integration; signed event
webhooks provide a path to Milestone 8's delivery evidence. This is an integration
priority, not a claim of superior inbox placement or a promise about pricing.

Keep the modular monolith, provider registry and shared dispatcher. Prefer the
WordPress HTTP API with a fixed HTTPS endpoint, TLS verification, no redirects,
bounded timeout and bounded response size; no SendGrid SDK is required for this
scope. Initial endpoint: `https://api.sendgrid.com/v3/mail/send`. EU regional
accounts/endpoints are deferred and must not silently fall back to this endpoint.

Implement the capability boundaries and acceptance criteria in
[the SendGrid scope](../SENDGRID-PROVIDER-SCOPE.md). Selection did not register
or activate the provider in ticket 1. Ticket 4 registers it and adds an
explicitly selected WordPress mail bridge; SMTP remains unchanged.

## Alternatives and consequences

- Microsoft 365 and Google Workspace remain planned; their authentication and
  account-consent workflows deserve separate implementation work.
- Postmark, Mailgun, Brevo and SMTP2GO remain Phase 2 expansion candidates.
  SendGrid's signed event facility fits our initial evidence pipeline; no other
  provider is rejected by this choice.
- Sending requires an operator-owned account, appropriately scoped API key,
  verified sender and production domain authentication. Account restrictions,
  quotas, reputation and DNS still affect results.
- Sending email necessarily shares recipient addresses and message content with
  SendGrid. This does not authorize persisting those payloads in plugin logs.
- API acceptance is only `Accepted`. Milestone 8 must independently authenticate
  delivery events; even confirmed delivery is not confirmed inbox placement.

## Contracts and sequencing

No shared interface, schema, dependency or lifecycle term changes in ticket 1.
`validate_config()` remains network-free. `test_connection()` must not send a
real message; its result must clearly state what was checked. Sandbox validation
is the planned probe, not proof of sending eligibility or delivery.

The existing `MailMessage` carries one body/content type, header strings and
local attachment paths. The existing `SendResult` has boolean acceptance and
limited error fields, not a complete certainty model. Tickets 3–5 must explicitly
review header parsing and ambiguous-outcome representation before changing shared
contracts. Ticket 4's bounded unconfirmed-outcome extension is recorded in
[ADR-0018](0018-unconfirmed-provider-acceptance.md). Do not add an `Unknown`
mail lifecycle state or pretend a timeout proves recipient rejection. No
automatic resend/failover in Milestone 7.

Configuration/secret protection is ticket 2, adapter construction ticket 3,
workflow integration ticket 4, outcomes ticket 5, correlation ticket 6 and shared
tests/live verification ticket 7. Webhooks are Milestone 8; routing and automated
transport recovery remain Milestone 9.

## Security, migration and rollback

Later configuration work must use least-privilege Mail Send authorization,
capability/nonce checks, protected secret storage and explicit replace/remove
semantics. Never echo a key, even partially, or persist raw provider errors.
Secret-storage design and any required dependency/contract changes require
Bernie's review in ticket 2; this ADR does not claim existing encrypted storage.

This ticket changes documentation only. There is no database migration, new
scheduled hook, retained payload, UI change or credential collection. Rollback
is reverting these documents/checklist changes; no operational cleanup is needed.

## Sources

Official documentation reviewed 2026-09-27; recheck limits during implementation:

- [Mail Send API](https://www.twilio.com/docs/sendgrid/api-reference/mail-send/mail-send)
- [API limits](https://www.twilio.com/docs/sendgrid/api-reference/mail-send)
- [Sandbox validation](https://www.twilio.com/docs/sendgrid/for-developers/sending-email/sandbox-mode)
- [Signed event webhooks](https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook-security-features)
