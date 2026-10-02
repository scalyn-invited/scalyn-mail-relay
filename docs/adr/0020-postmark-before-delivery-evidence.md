# ADR-0020: Postmark transactional provider before Milestone 8

Date: 2026-10-01. Owner: Bernie. Status: provider addition approved in conversation;
implementation and the bounded credential-context extension await owner review.

## Decision

Add Postmark beside SMTP and SendGrid as Milestone 7B. SendGrid registration and
live QA remain blocked; adding Postmark does not certify SendGrid or replace it.
Keep ProviderInterface, SendResult, schema, dispatcher and retention contracts.
Register through the existing provider hook; do not add a new container service.

Reuse the server-managed encryption key. CredentialCipher gains an optional
allowlisted provider context; the default preserves SendGrid's existing AES-GCM
authenticated data byte-for-byte. Postmark uses its own authenticated context,
preventing ciphertext copied between provider settings from decrypting. Existing
SendGrid envelopes need no migration. Admin gets only sender fields/key presence;
plaintext is resolved only for transport. Changes invalidate active-provider
verification. Nonce, capability, keep/replace/remove and audit safeguards apply.

## Transport and verification

Use fixed HTTPS GET /server to verify a Server API token and require DeliveryType
Live. Discard the raw response (which can contain ApiTokens); only safe evidence
leaves the adapter. Recheck before each send because historical verification does
not establish the server type for the current token. This adds one API round trip
per send (15-second timeout each); failure before POST is a known non-submission.
No verification call sends mail or proves sender authorization/remaining allowance.

POST /email uses only the outbound transactional stream. A Live Server is distinct
from a paid plan; free-plan real sending is subject to Postmark approval/allowance.
Sandbox servers and POSTMARK_API_TEST are deliberately unsupported in the active
WordPress route: simulated delivery must never look like actual site mail.

Require HTTP 200, integer ErrorCode 0 and a validated UUID MessageID to acknowledge
Accepted. Store the provider ID through existing result/log correlation and send
the Scalyn UUID as Metadata. Unexpected/malformed responses, timeout, redirect or
server error after POST are unconfirmed, never automatically retried. Explicit
4xx other than 408 are rejections. Safe fixed messages replace raw provider text.

Support UTF-8 plain text or HTML, To/Cc/Bcc, one Reply-To, allowlisted headers and
bounded local attachments. Disable open/link tracking. No templates, batch API,
custom/broadcast streams, inline embeds, failover or delivery webhook ingestion.
Delivery evidence remains Milestone 8; failover remains Milestone 9.

## Rollback

Explicitly select and verify an available alternate provider before reverting;
never silently route around an unavailable selected transport. No schema rollback
is needed. Stored Postmark options/ciphertext may remain; legacy code ignores them.
Keep the encryption key backed up separately. Removing a local token does not
revoke it at Postmark. Existing log retention and optional metadata consent apply.

## References

- [Email API](https://postmarkapp.com/developer/api/email-api)
- [Server API](https://postmarkapp.com/developer/api/server-api)
- [API outcomes](https://postmarkapp.com/developer/api/overview)
- [Sandbox semantics](https://postmarkapp.com/developer/user-guide/sandbox-mode/server-sandbox-mode)
