# SendGrid adapter — Milestone 7 ticket 3

The `SendGridProvider` implements `ProviderInterface` for SendGrid's v3 Mail Send
API. It was a standalone backend adapter in ticket 3. Ticket 4 subsequently
connected registration, encrypted settings, setup wizard and normal WordPress
sending; see [ticket 4 workflow](SENDGRID-WORKFLOW.md). Live verification
remains a later ticket. No real message was sent while building this adapter.

## Supported request contract

- One request per `MailMessage`, using a fixed global HTTPS endpoint and WordPress
  safe HTTP transport. TLS verification and unsafe-URL protection are enabled;
  redirects are disabled, timeout is 15 seconds and response reading is capped
  at 1,024 bytes. Ticket 5 recognizes an exact credits-restriction signal before
  discarding the body; raw response content is never retained.
- The supplied configuration must contain the decrypted `api_key`, configured
  `from_email` and optional `from_name`. Local validation makes no HTTP request.
  A message's sender address must match the configured address; the message's
  display name takes precedence when present.
- To recipients come from `MailMessage::to`; CC, BCC and one Reply-To address
  come from parsed header strings. Plain addresses and unquoted display names
  are supported. Quoted display names containing commas and more complex RFC
  address syntax are rejected before HTTP. There is no implicit recipient drop.
- The adapter supports one nonempty `text/plain` or `text/html` body. It does not
  synthesize a multipart alternative. Header forwarding is restricted to
  `X-Priority`, `X-Mailer` and `List-Unsubscribe`. Structural overrides, repeated
  Reply-To, unknown headers and CR/LF injection fail before sending. Normal
  WordPress header mapping needs explicit review in ticket 4.
- The only custom argument is `scalyn_message_uuid`; arbitrary message context
  is not sent. Open/click tracking is explicitly disabled. No suppression bypass
  or provider scheduling is requested.
- Local absolute attachment paths only. Filename must use a safe ASCII subset;
  inline/CID attachments are unsupported. At most 10 files, 4 MiB total file
  bytes, 1 MiB message body, 100 recipients and 8 MiB encoded request body.
  These conservative adapter limits are below the provider's published maximums
  and protect synchronous WordPress/PHP requests from large allocations.

## Observed outcomes

`test_connection()` posts a synthetic sandbox request. HTTP 200 means SendGrid
validated that request's shape; no real email or delivery claim follows. A normal
send succeeds only on HTTP 202 and yields `Accepted` through `SendResult`.
The adapter never retains provider response bodies or raw exceptions. Ticket 5
parses bounded JSON only to recognize an exact allowlisted account restriction.
HTTP authorization failures, rate limits and other responses return fixed safe
guidance. Network errors and missing responses are described as unconfirmed.

The current `SendResult` has a boolean success field and downstream failure
consumers. Ticket 4 added the minimal safe unconfirmed-outcome path required
before registration; [ticket 5](SENDGRID-OUTCOMES.md) completes the broader certainty rules. An
ambiguous network result is never presented as a proven rejection or
automatically retried. Provider message identifiers,
logging and timeline correlation remain tickets 5–6. Event webhooks remain
Milestone 8. SMTP skipped CC/BCC/Reply-To at ticket 3; ticket 7 now preserves
those roles and rejects unsupported input before sending. See the
[current parity and live verification notes](MILESTONE-7-VERIFICATION.md).

## Verification and dependencies

Unit tests use a stubbed HTTP boundary; no account or external network is needed.
They exercise the sandbox probe, accepted and rejected statuses, protected
request arguments, message fields, recipient roles, attachment encoding, unsafe
input and private outcomes. Local validation on 2026-09-30 (PHP 8.2.12):
the focused provider suite passed 8 tests and 101 assertions; the full suite
passed 1,019 tests and 2,500 assertions; all 177 tracked/new PHP files passed
syntax checks; full WPCS and `git diff --check` passed. No live SendGrid account
was contacted and remote CI was not run. No database, schema, Admin, capability, hook, service-container or
shared provider interface changes occur in ticket 3.

Based on official [Mail Send API](https://www.twilio.com/docs/sendgrid/api-reference/mail-send/mail-send),
[Mail Send limits](https://www.twilio.com/docs/sendgrid/api-reference/mail-send)
and [sandbox mode](https://www.twilio.com/docs/sendgrid/for-developers/sending-email/sandbox-mode)
documentation, reviewed 2026-09-30. Recheck account-specific limits before live
verification.
