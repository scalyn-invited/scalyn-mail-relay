# Milestone 7 ticket 5 — Safe transport outcomes

Owner: Bernie. Implementation review remains required. No schema, dependency,
credential-storage or lifecycle-term change. Uses ADR-0018's existing uncertainty
flag and hook; no retry worker or automatic failover is introduced.

| Observation | Result | Operator action |
| --- | --- | --- |
| SendGrid real-send HTTP 202 | Accepted, never Delivered | Await independent delivery evidence |
| Sandbox HTTP 200 | Request format validated; no email sent | A deliberate real send is still required |
| HTTP 4xx except 408 | Failed / provider-rejection | Review fixed guidance before manually retrying |
| HTTP 401 with one exact `Maximum credits exceeded` error | Failed; account allowance guidance | Check Email API plan, credits and billing |
| Other HTTP 401/403 | Failed; cause not established | Check key permissions and account restrictions |
| HTTP 429 | Failed; rate limit guidance | Wait for provider reset; no automatic resend |
| HTTP 408, 5xx, missing/malformed status, unexpected 1xx/2xx/3xx | Acceptance unconfirmed / Prepared | Inspect provider activity before resending |
| Local validation failure | Failed / config | Correct supported configuration or message input |

The HTTP response remains capped at 1 KiB. JSON is bounded to depth 8. Only a
single exact documented credits error on HTTP 401 is recognized; extra errors,
malformed/truncated JSON, unknown wording and appended text do not identify a
specific account problem. Raw bodies, transport exceptions and response headers
are never returned, persisted or shown. Safe wording is static. No provider
message ID or Retry-After value is retained in this ticket.

SMTP send exceptions also need caution: PHPMailer may throw after DATA, after
partial recipient acceptance, or after losing a response. Without phase and
per-recipient evidence, timeout/connectivity/rejection/unknown send exceptions
are conservatively unconfirmed, not evidence that every recipient failed.
Ticket 7 establishes an explicit pre-DATA SMTP connection boundary: connection,
authentication, TLS and preparation failures before `send()` remain definite
failures. After `send()` starts, every exception is conservatively unconfirmed,
including partial-recipient rejection; exception keywords alone cannot establish
whether submission happened. The connection is closed on all exit paths.
A false send return cannot become
Accepted. Connection-only probes keep their existing non-send semantics.
All send results in these adapters are non-retryable for automation. Existing
SMTP exception categories are retained, while remediation gives uncertainty
priority over category-specific retry advice. HTTP codes never use SMTP-code
classification.

## Verification and rollback

Tests cover the HTTP outcome matrix, exact/malformed/private credit responses,
one-request-only behavior, sandbox separation, SMTP uncertainty and safe
remediation. Full check evidence is consolidated in the milestone verification
notes after tickets 6–7. Live SendGrid success is not inferred from these mocks;
the previously observed account-credit restriction remains an external concern.

Rollback the ticket code after reviewing any pending manual resend decisions.
No migration is required. Existing Prepared uncertainty records remain valid.

References reviewed 2026-09-30:
[Mail Send responses](https://www.twilio.com/docs/sendgrid/api-reference/mail-send/mail-send),
[API errors](https://www.twilio.com/docs/sendgrid/api-reference/how-to-use-the-sendgrid-v3-api/errors),
[sandbox semantics](https://www.twilio.com/docs/sendgrid/for-developers/sending-email/sandbox-mode),
[credits exceeded](https://support.sendgrid.com/hc/en-us/articles/35466138799899-Understanding-the-Maximum-Credits-Exceeded-error).
