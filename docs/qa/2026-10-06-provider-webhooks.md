# SMTP2GO and Brevo authenticated webhooks — 2026-10-06

Current owner update (2026-10-06): Bernie confirms SMTP2GO and Brevo sending QA
is complete. Live webhook delivery/bounce/retry testing remains in the backlog.
SendGrid remains deferred. Earlier pending sending-QA notes below are historical;
this update records owner confirmation, not an independent rerun.

## Status

Implemented locally on feature/provider-webhook-evidence, stacked on the unmerged
SMTP2GO and Brevo transport commits 8f9c738 and 01ff50d. The checked remote develop
baseline for this work is 8c1aac5. No PR, push, deployment or live-provider claim.

Bernie owns review and release. See [ADR 0030](../adr/0030-authenticated-provider-webhooks.md)
for security, REST, persistence, configuration lifecycle and rollback decisions.
No schema migration. Existing Postmark handling remains supported.

## Implemented

- Separate SMTP2GO/Brevo source-scoped POST routes, dedicated encrypted bearer
  credentials, HTTPS and exact peer-IP checks. No forwarded-header trust.
- Bounded single-event JSON intake, authenticated rate budget, fixed empty
  responses and fail-closed storage handling.
- Delivery and hard/soft bounce normalization; no subject, address, raw reason,
  API key, authorization header or callback body retention.
- Pre-send HMAC recipient associations; source/provider/configuration binding,
  early-callback hints, opaque-ID preservation and transactional deduplication.
- Wizard step 3 optional source controls; save disabled, explicitly enable,
  pause on configuration change, disable, rotate while disabled, remove.
- Provider-card collection state; existing timeline/coverage UI presents evidence
  separately from the original sending outcome. No inbox-placement inference.
- Explicit source-option/rate-counter cleanup under existing destructive
  uninstall opt-in. Normal evidence/key retention remains unchanged.

## Automated evidence

- Full PHPUnit: **1,496 tests / 5,576 assertions passed**.
- JavaScript regression suite: **7 tests passed**.
- WordPress coding standards and PHP syntax checks passed.
- git diff --check passed.
- Tests cover bounded request/authentication failures, no forwarded-IP bypass,
  disabled sources, unavailable budgets, malformed payloads, provider isolation,
  secret-free projection, key-context isolation, nonces/capabilities,
  explicit consent/HTTPS, revision pauses, rotation/removal, identifier case,
  membership, duplicate persistence and uncertain commits returning 503.
- Simulated full handler tests traverse the actual normalizer, key/correlation,
  rate-limit and event repositories with an in-memory database fixture.
- Wizard template tests render the new controls without reflected credentials.
  This is NOT browser visual QA or actual MySQL concurrency/provider proof.

## Controlled public HTTPS QA still required

No provider accounts were changed, no tokens installed, no collection enabled,
and no live emails sent during implementation. Provider setup is manual:

1. Select and save the provider in Setup Wizard step 3. Complete the normal
   non-sending verification. Use only an approved staging site and test recipients.
2. Expand **Advanced: delivery and bounce evidence (optional)**. Generate an
   independent high-entropy token using a password manager, 32–128 characters
   from letters/digits/hyphen/underscore. Do not reuse the sending API key.
3. Enter current provider webhook peer IPs, one exact address per line. No CIDRs.
   SMTP2GO documents the A record for webhooks.smtp2go.com; Brevo publishes its
   webhook outbound addresses. Confirm those official sources at setup time.
   Save the disabled source. Copy the generated HTTPS endpoint; never add secrets
   to the URL. The plugin does not claim account/webhook verification.
4. In SMTP2GO Settings > Webhooks: JSON output; select Delivered and Bounce;
   scope to the sending API key; include X-Scalyn-Message-UUID in email headers.
   Set Authorization to Bearer with the same dedicated token.
5. In Brevo: transactional email webhook, delivered/hardBounce/softBounce
   subscriptions, Bearer token authentication, batching OFF. Do not use marketing,
   opens/clicks or batched event streams. The API auth setting is
   auth.type=bearer with auth.token set privately; batched=false.
6. Confirm setup/privacy and enable collection locally. Submit one approved
   message. Confirm original status remains Accepted and the message timeline
   separately shows recipient-server delivery evidence after a real callback.
   A provider dashboard sample without a retained message is ignored by design.
7. Test a controlled provider-supported bounce, one duplicate callback/retry,
   wrong bearer token, missing token, disallowed peer and malformed JSON. Verify
   unauthorized requests cannot store evidence and duplicate events create no
   second timeline entry. Never forward real payloads to third-party debugging tools.
8. Disable collection: new messages remain untracked; valid authenticated
   callbacks receive an empty acknowledgement without writes. Re-enable only
   explicitly. Change sender/provider config: new tracking must pause. Recreate
   the source for the new revision and update the external endpoint/token.
9. Test failed persistence/retry only on an isolated staging copy with a rollback
   plan. Confirm 503 is not treated as successful receipt, then provider retry
   persists exactly once after recovery. Do not disrupt production storage.
10. Review retention and remove a disabled source. Confirm history stays readable
    until normal expiry and external webhook cleanup is done separately.

Real delivery/bounce/retry QA, browser visual/accessibility QA, supported-database
concurrency QA, remote CI and Bernie's acceptance remain pending. Do not mark the
full provider-expansion checklist complete from offline tests alone.
