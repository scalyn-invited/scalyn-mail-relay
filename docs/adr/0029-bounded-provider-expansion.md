# ADR 0029: Bounded HTTPS provider expansion

Status: implementation decision for owner review (SMTP2GO and Brevo requested 2026-10-06).

Bernie owns implementation and integration. Add SMTP2GO and Brevo behind the existing
ProviderInterface; no interface, service-container, schema, REST or lifecycle
contract changes. Register explicitly, not through arbitrary configurable URLs.

New JSON adapters share bounded message preparation and a fixed-host HTTP
boundary. Existing SMTP, SendGrid and Postmark transports are unchanged.
Settings use provider-bound authenticated encryption and rotate the current
configuration revision when the active provider's configuration changes.
The seven-step wizard remains the configuration entry point. Admin receives
public sender fields and key presence only. Audit records field names only.

SMTP2GO uses POST /email/send with fastaccept=false. Acceptance requires an
identifier, a matching successful-recipient count, zero failures and no error.
Partial/malformed acknowledgements, redirects, timeouts and server failures are
unconfirmed, never retried automatically. HTTP 4xx other than 408 are rejection.
Verification uses POST /stats/email_cycle with an empty JSON object: it verifies
read access only, not send permission, sender authorization, quota or delivery.
Keys need /email/send and /stats/email_cycle permissions.

Brevo uses POST /smtp/email and requires HTTP 201 with a bounded messageId.
Verification uses GET /account, validating account-response structure but never
retaining account details or returned automation credentials. It verifies API
account access only, not transactional activation, sender authorization, allowance
or delivery. Use a Brevo API key, not an SMTP key; account IP restrictions apply.

Limits: 50 recipients including Cc/Bcc, 1 MiB body, 10 attachments totaling 4 MiB,
8 MiB JSON, 16 KiB response, 15-second request, no redirects, TLS verification.
Attachments must be readable local files; arbitrary remote URLs and inline
embeds remain unsupported. No raw response, credential or message body is
persisted by these adapters. Provider account tracking settings remain external;
the plugin neither enables nor imports open/click tracking.

Delivery/bounce webhooks and automatic failover were outside this transport addition.
ADR 0030 adds authenticated webhook receivers in a separate follow-up; automatic
failover remains excluded. The following describes the transport-only baseline:
Provider cards explicitly show evidence unavailable. Accepted is not Delivered.
Future providers require a focused adapter, settings/UI wiring, provider-specific
contract tests and live QA; they must not inherit assumed delivery capabilities.

Rollback: select an existing provider before reverting code. No migration is
needed; new encrypted options can remain inert or be removed through the wizard
before rollback. Never downgrade while the removed provider is selected.

References verified 2026-10-06:
- https://developers.smtp2go.com/reference/send-standard-email
- https://developers.smtp2go.com/docs/send-an-email
- https://developers.smtp2go.com/reference/email-cycle
- https://developers.brevo.com/reference/send-transac-email
- https://developers.brevo.com/reference/get-account
