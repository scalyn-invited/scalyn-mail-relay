# Milestone 7 ticket 6 — Correlation and private timelines

Owner: Bernie. Depends on ticket 5's normalized outcomes. Owner review pending.

The same generated UUID now follows a WordPress attempt from preparation through
the dispatcher, SendGrid `custom_args.scalyn_message_uuid`, mail log and timeline.
Unsupported WordPress shapes fail before the network and emit a correlated
Failed/config event with fixed guidance. The failure envelope contains no
customer addresses, subject, body, headers, embeds or attachment paths. Its
attachment count is zero because the unsupported input was not safely prepared.

Normal WordPress attempts use `wordpress` / `wp_mail` source labels. Wizard test
attempts now use `admin` / `wizard_test`; their audit correlation remains the same
message UUID. Sandbox connection probes remain separate verification audits and
never create accepted mail records.

Existing repositories and retention remain authoritative. Timeline records are
append-only. Accepted HTTP 202 attempts get Accepted/mail_sent; explicit
rejections get Failed/mail_failed; uncertain outcomes get
Prepared/mail_outcome_unconfirmed without sent_at or failed_at. There are no
invented Connected/Authenticated/Delivered events for API traffic.

Only existing allowlisted provider/code/category/retry fields enter event_data.
Arbitrary context/metadata, HTTP bodies, authorization headers and provider
message IDs are not retained. The UUID is sufficient for this milestone's
outbound correlation; authenticated webhook correlation and any extra bounded
provider identifier retention belong to Milestone 8. UUID knowledge alone is
not authentication or evidence of delivery.

Lifecycle observer exceptions cannot change the provider's observed result or
trigger another request. Fixed diagnostic text is logged, never exception text.
A throwing third-party hook can still prevent later observers from running;
this boundary protects the send outcome, not a guarantee of durable logging.
Existing repository persistence-error isolation remains in place.

## Verification and rollback

Integration-style tests exercise the real bridge, adapter, dispatcher and both
repositories with mocked HTTP/database boundaries, covering 202, 401, 429, 500
and absent responses. They assert equal UUIDs, source/status/event/timestamp
semantics and absence of credentials, sender/recipient/BCC, subject and body.
Preparation failure and observer-exception tests assert zero or exactly one
network attempt as appropriate. Earlier repository privacy/retention tests remain.

No schema, migration, capability, dependency or retention change. Revert this
ticket to roll back behavior; existing rows remain compatible. No customer
message bodies are introduced into persistent storage.
