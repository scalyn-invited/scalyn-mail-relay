# ADR 0030: Authenticated SMTP2GO and Brevo delivery evidence

Status: implemented locally for Bernie's review, 2026-10-06.
Requested scope: authenticated delivery/bounce handlers for both new providers.
Depends on ADR 0029 transports. Supersedes its receiver exclusion only; automatic
failover, open/click tracking and bounce-rate scoring remain out of scope.

## Contracts and trust boundary

Two source-scoped POST routes under scalyn-mail-relay/v1:
- /webhooks/smtp2go/{source_uuid}
- /webhooks/brevo/{source_uuid}

Routes exist only while a local source exists. These are server callbacks, not
administrator REST actions: permission_callback permits routing, then the handler
requires a dedicated Bearer secret, trusted HTTPS and an exact peer-IP allowlist
before parsing or correlation. Forwarded headers never override REMOTE_ADDR/TLS.
A source URL is not an authentication credential. No new WordPress capabilities.
Admin actions require MANAGE_MAIL and a provider-specific nonce.

Use independently generated, random 32–128 character tokens (letters, digits,
hyphen, underscore), not API keys or WordPress passwords. Provider-specific GCM
contexts isolate credentials. No secret, raw callback, subject, failure text,
recipient address or SMTP2GO auth field is stored or echoed. Operators must also
disable request-body/Authorization logging at upstream proxies and web servers.

This is authenticated bearer transport, not provider-signed payload verification.
Possession of a leaked token and access through an allowed peer compromises this
trust boundary. Rotate while disabled. Keep provider IPs current; no automatic
DNS trust or broad CIDR expansion. Proxies must securely preserve actual peers.

## Opt-in, configuration and privacy

Wizard step 3 owns source setup. Save creates a disabled draft bound to the
current configuration revision. It is NOT a remote-account verification.
Admin explicitly confirms provider-side setup and privacy terms before enabling;
public HTTPS, ready storage and readable encryption are required. Existing mail
is not backfilled. Changing provider/configuration pauses NEW tracked sends.
Changing source revision requires disabling, removing and recreating the source.
Retained attempts stay pinned to their original provider, source and revision.

Disabled sources authenticate then acknowledge without writes. Removal retires
the recipient-matching key, removes credentials/route/rate counter, and retains
history until normal cleanup. Remove the external webhook separately. Optional
destructive uninstall removes new options/counters under the existing opt-in.
A disable immediately before persistence is rechecked; like Postmark, an already
in-flight transaction can commit after a concurrent disable (not a hard barrier).

## Correlation and evidence

Before submission, store source/revision/attempt UUID and HMAC recipient membership
using the existing key repository. Failure to prepare tracking never blocks mail:
it remains explicitly untracked. Sending payloads carry a reserved attempt UUID:
SMTP2GO X-Scalyn-Message-UUID; Brevo X-Mailin-custom. External account configuration
must echo the SMTP2GO custom header. This is a hint, not authorization.

Acknowledgement binds the canonical provider ID without lowercasing opaque IDs.
Callbacks must resolve a retained, source AND provider-bound attempt and exact
recipient token. A conflicting hint/identifier, unknown recipient, expired or
untracked message never produces delivery evidence. Brevo documents both bare
and angle-bracket IDs; only that bracket normalization is permitted.

SMTP2GO accepts single JSON delivered/bounce events, using email_id, rcpt, time
and hard/soft bounce classification. Brevo accepts single JSON delivered,
hard_bounce and soft_bounce events using message-id, email and ts_event.
Configure Brevo batching OFF. Unrelated events are ignored, never treated as
failure/success. Event dates are strictly parsed; future dates beyond five minutes
are rejected. The provider webhook id is NOT treated as a unique delivery event.

Tuple-derived event identity includes source, provider ID, recipient token,
event kind/time/classification. Existing transactional persistence deduplicates,
locks the retained attempt, rechecks membership, binds early callbacks and appends
a minimal timeline entry atomically. Receipt time may change on replay; semantic
conflicts do not overwrite evidence. No original Accepted/Failed rewrite.

## Bounds and responses

POST JSON only; 256 KiB body; 64 single-valued headers, 16 KiB combined;
no compressed requests, ambiguous duplicate auth headers, or batches.
Existing atomic per-source budget applies after authentication and before parsing.
200: stored, committed duplicate, or authenticated ignored event.
400/405/413/415/431: invalid envelope. 401: authentication failure.
429: budget exhausted. 503: unavailable keys/storage or uncertain commit.
Responses are empty and no-store; errors never echo raw exceptions.

## Schema, ownership and rollback

No migration: existing provider columns and 255-byte binary provider IDs suffice.
DeliveryAttemptRepository adds an explicit provider parameter defaulting to
Postmark for backward compatibility. DeliveryEventRepository permits only three
provider/authentication pairs with bounded IDs. Container services, source controls,
REST routes, tracker and read model expand deliberately here. ProviderInterface
and lifecycle vocabulary are unchanged. Bernie owns review and integration.

Disable collection and remove external webhooks before rolling back. Retained
evidence remains readable; old code does not ingest new-provider callbacks.
Select an available transport before also reverting the transport dependency.
No schema rollback or deletion is needed.

## Provider references verified 2026-10-06

- https://developers.smtp2go.com/docs/setup-a-webhook
- https://developers.smtp2go.com/reference/add-webhook
- https://developers.smtp2go.com/docs/webhooks-overview
- https://developers.brevo.com/docs/secured-webhooks
- https://developers.brevo.com/reference/create-webhook
- https://developers.brevo.com/docs/transactional-webhooks

Offline contract tests are not live-provider verification. Both providers require
controlled public HTTPS delivery, bounce, replay, disable and retention QA before
release; no account settings or live mail were changed by this implementation.

