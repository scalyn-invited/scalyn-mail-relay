# ADR-0022: Provider correlation and out-of-band delivery evidence

Date: 2026-10-02. Owner/reviewer: Bernie.
Status: Approved by Bernie on 2026-10-02 after alignment refinements and the
instruction to start implementation. Bernie subsequently approved including ticket
3 persistence to resolve ticket 2's dependency. Schema versions 0.5.0/0.6.0 and internal
repositories are implemented; no receiver, transport collection or lifecycle
rewrite is enabled.

## Current implementation evidence

Baseline: merged develop `f1f6ff6` (PR #61).

- Each MailMessage UUID identifies one dispatch attempt. Postmark sends it in
  Metadata.scalyn_message_uuid; SendGrid sends it in custom_args.scalyn_message_uuid.
- SendResult can carry a provider_message_id. Postmark returns a validated UUID.
  This does not mean it is persisted: MailEventSubscriber deliberately excludes
  provider_message_id from timeline metadata; MailLogRepository does not store it.
- SMTP has no authenticated out-of-band delivery source. A generated SMTP
  Message-ID is not provider evidence.
- Prepared can represent unconfirmed acceptance. Accepted is provider acceptance,
  not recipient-server delivery. Existing status and historical reports stay intact.
- Diagnostic configuration revision is not a stable provider-account identity.
  DKIM selector changes rotate it; delivery callbacks must survive such changes.

## Decision proposed

Start with Postmark Delivery and Bounce events. Keep provider-specific decoding
and authentication separate from transport ProviderInterface. Do not add a
webhook method to every SMTP/API provider or reuse outbound alert webhook code.

Inbound stages are authenticate -> bound/validate -> normalize -> correlate ->
atomically deduplicate and append -> acknowledge. Admin views consume a read model,
not raw payloads or direct table access. No stage sends or retries an email.

### Identity and correlation

Introduce an opaque local integration UUID for an explicitly configured webhook
source (provider account/server and stream). Bind authentication to that source.
Never infer source identity from a payload's provider label, current active
provider or a submitted URL. Postmark ServerID and stream must match the configured
source. Changing provider account/server requires a new integration UUID;
credential rotation for the same verified source need not change it.

When delivery-evidence collection is enabled for the source, before submission
persist an attempt association: message UUID, provider,
integration UUID if configured, configuration revision at dispatch, creation UTC,
and expected recipient count. After acknowledgement, append the validated provider
message identifier. Never backfill missing historical configuration/source identity
from today's settings. This persistence work belongs to ticket 3 and requires a
versioned migration; the receiver must not expose successful ingestion before it
can durably record evidence.

For a verified source, prefer exact internal UUID plus matching provider/source.
When a provider message ID is known it must also match. If metadata is absent,
allow exact provider/source/message-ID lookup only when it resolves uniquely.
Never fuzzy-match SendGrid identifiers, strip suffixes without a documented
provider rule, match by subject/address/time, or trust the metadata UUID alone.

An event can beat the send acknowledgement or follow a timeout. A pre-submission
association permits correlation in those cases, without rewriting Prepared into
Accepted. A contradictory ID, unknown attempt, ambiguous match or expired attempt
must not alter a message or its timeline. Source changes leave old associations
historical; delayed events cannot become health evidence for a new configuration.

### Recipient privacy and coverage

Events are recipient-specific. A delivery for one recipient must never mark every
recipient delivered. Store no callback recipient addresses, subject, body,
attachment data, raw headers, free-text response or arbitrary metadata by default.

Proposed matching token: HMAC-SHA256 over a versioned tuple of integration UUID,
attempt UUID and parsed recipient address using a dedicated random server-held
key, distinct from provider credentials. Preserve local-part case and lowercase
the domain; do not strip plus tags or dots. Use the identical normalization at
dispatch and receipt. Case-only mismatches remain uncorrelated rather than guessed.
When delivery-evidence collection is explicitly enabled, persist tokens for the
deduplicated To/Cc/Bcc set before sending, independently of optional address
capture. Treat tokens as pseudonymous personal data subject to
retention, not anonymous data. Never expose tokens in normal UI or exports.

Key version is retained with the attempt; old key versions must remain available
for retained attempts. Missing/rotated-away keys make coverage unavailable, not
delivered. Ticket 3 must explicitly implement and test provisioning, versioning,
retention and deletion before enabling this optional collection. No existing
installation starts collecting recipient tokens through ticket 1.

### Enablement and privacy notice

Propose a per-source setting labelled "Collect delivery and bounce evidence",
off by default for new installations and upgrades. Selecting a sending provider,
saving its API token, verifying a connection or enabling recipient-address logging
must not enable this setting. Configuration belongs in the provider's wizard
configuration step, with its status and a configuration link on the Providers page.
Do not require delivery tracking to complete ordinary mail setup.

Before explicit enablement, show this notice with the effective retention period:
"Stores provider message identifiers, delivery/bounce events and keyed recipient
tokens to match results to individual recipients. Tokens are pseudonymous data,
not anonymous data. Recipient addresses are processed transiently for matching;
this feature does not retain raw addresses or message bodies. Records follow your
mail-log retention setting (currently {N} days). Provider delivery confirmation
does not prove inbox placement."

Require the existing mail-management capability, a valid nonce and an unchecked
acknowledgement on initial enablement. Record a safe configuration audit event
(source identifier and enabled/disabled action only). No secret or token enters
audit metadata. Enabling requires ready storage, matching-key availability and
configured source authentication; otherwise retain the disabled state and explain
the missing prerequisite. Do not introduce another manual server-key requirement
silently: ticket 3 must specify secure provisioning and recovery for review.

Disabling stops new association/token collection and callback evidence writes for
that source immediately. Retained evidence remains read-only history until normal
cleanup; explain that disabling is not immediate deletion and does not remove the
webhook from the provider account. Management guidance must include removing or
pausing that provider webhook. Authenticated callbacks to a disabled source receive
a fixed acknowledgement without storing their contents; unauthenticated calls
remain rejected. Re-enabling never backfills untracked attempts. Existing retained
associations remain usable if their source and key version are still valid.

Optional raw-recipient logging remains a separate setting with its existing
consent rules. Disabling it does not disable approved token collection; disabling
delivery evidence does not silently change ordinary mail logging or transport.

### Normalized contract version 1

All persisted values use an explicit allowlist. Unknown fields are discarded at
the provider boundary, never copied into a generic metadata bag.

| Field | Definition / bound |
| --- | --- |
| schema_version | Integer 1 |
| source_id | Locally authenticated integration UUID |
| provider | Supported adapter identifier, initially postmark |
| message_uuid | Correlated retained attempt UUID, never invented from callback |
| provider_message_id | Validated provider identifier, max 255 ASCII bytes, no controls |
| event_key | 64-character SHA-256 deduplication key scoped to source |
| kind | delivery or bounce; separate evidence kinds, not send lifecycle statuses |
| recipient_token | Matched versioned HMAC token; absent means no coverage claim |
| occurred_at | Valid provider timestamp normalized to UTC with microseconds |
| received_at | Server UTC receipt timestamp, independent of event time |
| authentication_method | Fixed adapter enum, not headers/secrets; initially postmark_basic_tls |
| reason_code | Optional fixed normalized code: hard_bounce, soft_bounce, unknown_bounce |

Configuration revision comes from the attempt association, never from the payload.
Authentication evidence is a server-side result, not a client-supplied true flag.
Require a real timestamp with timezone; reject malformed or more than five minutes
future-dated timestamps. Old events may still be legitimate retries: use retained
attempt/source validity and deduplication, not a short delivery-age cutoff.

Postmark Delivery maps MessageID, DeliveredAt and Recipient; Bounce maps MessageID,
BouncedAt and Email. Bounce type must use an explicit tested mapping; unknown
types retain unknown_bounce rather than imply permanence. Do not retain Details,
Description, Content, Subject or DumpAvailable callback values.

Postmark Bounce ID is its preferred stable event identity. For Delivery use a
versioned tuple of source, MessageID, matched recipient token, kind and normalized
DeliveredAt, preserving a seventh fractional digit in identity when present
(display timestamps use microseconds). Do not deduplicate on MessageID alone. Trace headers may aid transient
processing but are not the sole semantic identity. SendGrid's future adapter uses
sg_event_id with source scoping and custom_args correlation; it needs separate
authenticated fixtures and live verification before enabling it.

### Authentication, retries and outcomes (ticket 2 requirements)

Postmark does not provide SendGrid's signed-event scheme. Use HTTPS and dedicated
high-entropy Basic credentials configured for this webhook source, constant-time
comparison, plus maintained source-IP restrictions as defence in depth. Do not
trust forwarded-IP headers unless a proxy is explicitly trusted. Never put secrets
in query strings, application logs or UI markup. Infrastructure access/error logs
also need redaction. The send API token is not the webhook secret.

SendGrid will require its documented signature over timestamp plus original raw
body (or a separately approved supported authentication mechanism). Never parse
and reserialize JSON before verification. A WordPress nonce/login cannot authenticate
a third-party callback; only webhook management uses administrator capabilities
and nonces. The receiving route is an explicitly documented server-to-server
exception to the Backend Architecture's default admin REST permission rule, not
an unauthenticated public API. No admin read or mutation route inherits this
exception. Postmark Basic authentication cannot prove cryptographic message age:
replay protection here means durable deduplication within retention, with explicit
limits after deletion/key loss. Do not claim signed-payload assurance for Postmark.

Ticket 2 must bound request bytes before decoding (initial ceiling 256 KiB), reject
invalid shapes and unsupported content types, and rate-limit without logging
payloads. Postmark is one event per request; batching belongs to another adapter.
Unauthenticated requests have no event-side effects. Valid duplicates return 200
only if the original record was durably committed. Temporary persistence failures
return 5xx. Authenticated unsupported or permanently uncorrelatable events receive
a fixed acknowledgement with no delivery update; allow only a bounded aggregate
diagnostic counter, not a raw quarantine. Do not use Postmark 403 for temporary
failures because it stops retries. Unknown/retention-expired attempts are not
recreated from callbacks. Provider test events never establish production delivery.

### Append-only evidence and claims (ticket 3 requirements)

Use a dedicated delivery-evidence repository and migration, with a unique
(source_id, event_key) constraint and indexes for bounded attempt/time reads.
Persist evidence and its timeline projection atomically; concurrent duplicate
requests append once. Preserve both event time and receipt time. Arrival order is
not a truth precedence rule. Delivery followed by bounce, or vice versa, retains
both facts and displays mixed evidence rather than silently overwriting either.

Original send-attempt status, audit history and acceptance counts do not change.
Display Delivered only for a matched authenticated delivery event, qualified as
recipient-server acceptance. Show per-recipient coverage counts without exposing
Bcc identity. Complete delivery coverage requires all expected recipient tokens;
missing membership/partial/mixed evidence remains explicit. No numerical delivery
score, inbox claim, opens/clicks or spam-placement claim is introduced here.

### Existing timeline presentation

Extend the existing Email Logs timeline drawer and full-page fallback rather than
introducing a disconnected delivery history screen. Each evidence entry contains
the UI/UX document's four elements: time, status, icon and explanation. Use the
provider's event time in the site's display timezone with an explicit timezone
label; retain UTC internally. Show receipt time separately when a delayed callback
would otherwise be confusing. Keep the append-only receipt order and label delayed
events; never rewrite original send timestamps to simulate a chronological send.

Use "Delivered (recipient server)" only for matched authenticated delivery, and
"Bounce reported" for a bounce fact, not as a replacement send-attempt status.
Provide fixed plain-language explanations and safe recommended actions. Icons and
colour supplement visible text, never replace it. Explain partial coverage as, for
example, "Delivery confirmed for 1 of 3 recipients; remaining outcomes unknown".
Mixed delivery/bounce evidence must remain visible, including later contradictions.
Never expose Bcc identities, tokens or raw provider responses through the drawer,
fallback page, accessible labels or exports. Test keyboard and narrow-screen views.

The original log row's transport status stays Accepted/Prepared/etc.; show delivery
evidence as a separate labelled summary. Missing evidence must distinguish
"Not enabled", "Awaiting evidence" and "Unavailable" with a reason rather than
implying failure or success. The same read model must back drawer and full page.

### Separate Deliverability Centre and scoring boundary

Preserve the UI/UX requirement for a Deliverability Score separate from the existing
configuration/Email Health Score. Tickets 1-3 introduce no numerical deliverability
score and do not relabel the existing health score or a delivery percentage as one.
Ticket 7 must define evidence coverage, freshness, exclusions and explainable
weights before a number is shown; insufficient evidence remains "Not assessed".

The later Deliverability Centre must address the documented breakdown:
authentication, domain alignment, DNS quality, header quality, provider reputation
and historical performance, plus ranked recommendations (Critical / Important /
Optional). An unsupported component remains explicitly unavailable, not a pass.
Mailbox placement and spam-risk testing remain separate evidence sources in later
tickets. Delivery callbacks alone cannot satisfy the full Centre or score design.

### Retention and deletion

Retention must delete associations, recipient tokens, evidence and deduplication
state consistently with their attempt and existing opt-in uninstall policy.
Use the existing `advanced.log_retention_days` setting (default 30 days), not a
separate delivery retention default. Expiry is anchored to the attempt's creation
time, not the callback time; late events and retries never extend its lifetime.
Do not accept new evidence for an already-expired attempt merely because scheduled
cleanup has not run yet. Apply retention changes through the existing bounded
cleanup workflow and disclose that physical deletion occurs during cleanup.

Delete delivery timeline projections with the associated evidence, even if another
history category permits longer retention. Explicit supported mail-history deletion
must include the new records. Remove obsolete matching-key versions when no retained
association references them; do not remove a key still needed by another source or
attempt. Deactivation must not unexpectedly erase history. Opt-in uninstall deletion
must cover the new tables, configuration and key material; retained-data uninstall
must preserve whatever is needed to read the retained history securely.

A callback for deleted history cannot resurrect it. Schema-readiness failure closes
ingestion safely. Rollback disables the endpoint/registration first, keeps additive
data for retention, and never rewrites send history or triggers automatic resend.

## Alignment with implementation documents

- Backend Architecture: follows Database Design Principles, Privacy Requirements,
  Data Retention Policy and Timeline Events. The dedicated evidence repository,
  per-recipient HMAC tokens and key lifecycle are proposed extensions to its sample
  schema/recipient_hash field, not requirements already specified there.
- Technical Architecture: preserves modular provider/logging boundaries and
  security controls. Delivery facts extend the timeline without changing the
  established transport contract or adding speculative routing/failover.
- UI/UX Design: preserves Email Timeline View's time/status/icon/explanation and
  the separate Deliverability Centre/score. Full Centre implementation is later
  work, not implied completion of this contract ticket.
- Product Vision and Part 2: supports delivery visibility, actionable explanations
  and recommendations while distinguishing recipient acceptance from inbox proof.

For differing interface names, namespaces or example scoring weights, current
repository contracts and accepted ADRs take precedence under AGENTS.md. This
proposal does not restore historical example lifecycle names or team assignments.

## Implementation and review gate

### Persistence implementation, 2026-10-02

The approved dependency work adds four InnoDB tables in schema 0.5.0: immutable
matching key versions, source-bound attempts, keyed recipient membership and
append-only events. Migration verifies columns, engine, full index order and
uniqueness before advancing; it creates no records or historical backfill.
Default uninstall retains these tables; explicitly opted-in deletion drops them.

Matching keys are generated as 32 random bytes by explicit internal provisioning,
encrypted using the existing server encryption root with a dedicated authenticated
context. The version UUID is included inside the encrypted payload to detect
envelope substitution. No new manual server-key setting is introduced. Missing
versions or an unavailable root fail closed, with no automatic replacement.
Provisioning does not select a version for a source. Rotation can create an
independent version without overwriting old ones. Schema 0.6.0 adds an explicit
retirement timestamp and index without editing the applied 0.5.0 definition.
Retirement rejects new attempt references but preserves matching for retained ones.
Cleanup deletes only explicitly retired versions with no attempt references,
locking and rechecking them against concurrent preparation. Source management
must stop selecting a version before retiring it; that integration remains pending.

Attempt association and deduplicated membership commit together. Provider IDs use
compare-and-set and cannot overwrite contradictory evidence. The event repository
locks the retained attempt, rechecks source, provider, identifier and membership,
and commits an event and privacy-safe timeline projection together. Duplicate
identity must agree with all semantic fields; receipt time may differ on a retry.
No stored event changes the transport status. A unique database index is the
additional cross-attempt collision guard. Repositories suppress database error
printing while operating on tokens/envelopes and restore the prior setting.

Delivery retention and key cleanup now run through the existing hourly service.
One site-calendar cutoff is converted to UTC for attempt-age expiry. Delivery
cleanup locks the same attempt rows as event ingestion, atomically removes tokens,
events and delivery projections, and leaves original send lifecycle rows alone.
Mail-log deletion also removes delivery children within its own transaction.
This includes orphaned attempts and does not extend expiry for late callbacks.

These repositories are not wired into transport or HTTP yet. Source coordination,
collection consent, live source verification, read-model coverage and source/key
selection remain before activation. Real concurrent duplicate callback workers
passed against disposable tables (one stored, one duplicate, one projection).
Limiter/source lifecycle race verification still remains. Real-database tests use
disposable tables, not retained customer history.

Ticket 2 draft management uses a shared `PostmarkWebhookSettings` service and a
separate optional wizard form. The audit contract adds `webhook_configuration`
with `saved_disabled` and `removed` outcomes, correlated to source UUID only. This
does not represent collection enablement. Credentials remain external-input-only
and are never populated back into UI markup. No callback endpoint is registered.

This approved contract is not itself executable webhook support. Further changes
to identity, pseudonymous-recipient storage or retention require Bernie's review.
Implement ticket 2's authentication and
normalization offline first; enable a public receiver only with ticket 3's durable
association/deduplication path. Live QA needs an owner-approved public HTTPS endpoint;
localhost is not reachable by Postmark. Opening a tunnel/deploying is separate
authorization. See the acceptance matrix in ../MILESTONE-8-DELIVERY-EVIDENCE.md.

## Sources checked 2026-10-02

- [Postmark delivery events](https://postmarkapp.com/developer/webhooks/delivery-webhook)
- [Postmark bounce events](https://postmarkapp.com/developer/webhooks/bounce-webhook)
- [Postmark webhook authentication and retries](https://postmarkapp.com/developer/webhooks/webhooks-overview)
- [SendGrid event identifiers and custom arguments](https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/event)
- [SendGrid webhook security](https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook-security-features)
