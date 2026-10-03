# Milestone 8: delivery evidence implementation handoff

Owner: Bernie. Started 2026-10-02 from merged PR #61 (`f1f6ff6`).
Current branch: `feature/m8-t2-postmark-webhook-auth` (ticket 1 documentation preserved).

## Ticket 1 status

Contract recorded in [ADR-0022](adr/0022-delivery-evidence-contract.md).
Bernie approved starting implementation after alignment refinements on 2026-10-02.
This ticket is documentation only: no callback URL, migration, live settings change,
new tracking, sends, delivery labels or runtime PHP interfaces have been introduced.
The existing metadata UUID travels to both API providers, but the provider message
ID is not currently persisted by logging; ticket 3 must close that gap explicitly.

Proposal tightened after implementation-document review: collection is explicitly
opt-in per source, timeline presentation uses the existing logs UI, evidence follows
mail-log retention (default 30 days), and the later Deliverability Score remains
separate from Email Health. Approval does not imply runtime implementation complete.

## Ticket 2 implementation boundary

Offline `WebhookAuthenticator` validates dedicated Basic credentials, trusted HTTPS
and a nonempty maintained exact-IP allowlist (IPv4/IPv6; CIDR configuration is not
implemented). It reads no forwarded headers, stores no credentials and makes no
network calls. Provisioning must generate a random base64url username/password;
the length/character checks alone cannot prove entropy.

Offline `WebhookNormalizer` bounds JSON at 256 KiB, checks source/message/recipient
and timestamps, invokes a caller-supplied source-bound correlation resolver, and
returns allowlisted delivery/bounce fields with deterministic event keys. Invoke it
only after authentication. Ticket 3 now provides internal source-bound attempt
lookup and versioned HMAC matching, but they are not wired into the request path
or sending path yet. Unknown/unmatched events return no evidence; malformed
input and unavailable storage are distinct safe failures. No raw recipient, body,
arbitrary metadata or provider text enters its output. Test recipient hashes are
synthetic fixtures; real key provisioning and matching are tested separately.

`WebhookIngress` now composes request validation, authentication, an injected atomic
budget gate and normalization. It requires POST and JSON, limits body/header sizes,
rejects duplicate/case-variant headers, content encoding and invalid Content-Length,
ignores forwarded network claims, and authenticates disabled sources before ignoring
them. The budget adapter must return true to proceed; false produces 429, unavailable
or invalid results produce 503. Authenticated body parsing follows that budget gate.
`WebhookRateLimitRepository::consume` supplies a database-serialized budget of 60
authenticated requests per source per fixed UTC minute. It uses a site/database/
source-scoped MySQL advisory lock and fresh reads/writes of one private non-autoloaded
option per configured source. Database time determines the window. No option cache,
recipient, credential or request body is involved. Contention, corrupt/future state,
write failure and uncertain lock release fail closed with a safe unavailable error.
This fixed-window policy permits a boundary burst; it is not a sliding-window limit.
Calls must use a previously authenticated, configured source, never an arbitrary
payload UUID. The repository remains unregistered and creates no live rows now.
Tests use a database double; real multi-connection concurrency and supported-host
GET_LOCK behavior still require integration verification before endpoint activation.
Upstream unauthenticated traffic controls remain necessary.

Ingress results are internal: ready includes minimized evidence for future atomic
storage and never includes a success HTTP code. Ignored has no evidence. Rejections
contain only a fixed disposition/status. Future REST adapters must not serialize
these internal event arrays to the caller. Proxy deployment support is not implicit:
current HTTPS/peer inputs must come from trusted server facts, and the server adapter
must preserve duplicate-header evidence or reject ambiguous requests upstream.

`PostmarkWebhookSettings` now provides a capability/nonce-protected disabled-draft
service. Dedicated username/password values are encrypted together under a new
`postmark-webhook` cipher context, separate from API token contexts. Public reads
require management permission and return no credentials. Saves never enable tracking,
verify the server, change transport settings or collect recipient tokens. Keep and
replace are explicit actions; unreadable encryption fails closed. Server/stream
changes require explicit removal of the disabled draft first, clearing its budget,
then creation of a new source UUID. This restriction is for disabled drafts only;
historical active-source retirement still belongs to ticket 3.

The shared container now provides the draft service. Postmark wizard step 3 includes
an optional collapsed advanced form for disabled drafts, with explicit save/remove
feedback and empty password-type inputs for both credentials. It never blocks normal
setup, provides no callback URL and tells administrators not to register a webhook
yet. Dedicated credentials are supplied from the administrator's password manager;
the plugin neither generates nor displays them. Audit records contain only the source
UUID and fixed saved_disabled/removed outcomes, never values. No-op saves do not audit.
Source identity is locally validated but NOT provider-verified. Server verification
and explicit collection opt-in UI remain activation prerequisites.
Tests submit synthetic credentials only. The existing server encryption
key is reused with purpose-separated authenticated data; no new manual key is needed
for webhook credentials. Recipient-token key management remains separate future work.

No REST receiver or ingress container registration is added. Server/proxy integration,
limiter wiring/concurrency verification, credential provisioning/verification, opt-in UI, durable replay/duplicate
handling and live QA remain required before activation. Stable event keys are not duplicate protection
by themselves. A normalizer result is not permission to acknowledge an event before
atomic persistence. No Postmark signature support is claimed; SendGrid is separate.

Explicit disabled-draft removal and opt-in uninstall delete its private
`scalyn_webhook_budget_<source UUID>` option and encrypted draft. Default uninstall
retains both. Removal does not revoke credentials at Postmark.
No per-minute option proliferation is allowed: expired counters are overwritten in
the same row. Live source provisioning, active-source retirement and associated
delivery-history cleanup remain required before enabling ingestion.

### Local validation, 2026-10-02

- Focused webhook suite: 14 tests / 116 assertions including request-gate and dependency-failure cases.
- Budget repository suite: 5 tests / 79 assertions passed using a database double.
- Disabled webhook settings: 6 tests / 33 assertions; retain/delete uninstall coverage added.
- Form tests cover save/remove, safe audit output, invalid input, capability/nonce
  rejection, read-only rendering and audit exception isolation.
- Full PHPUnit: 1,207 tests / 4,203 assertions passed.
- Full WPCS, 215 PHP syntax checks, 7 JavaScript tests, strict Composer validation
  and tracked diff whitespace checks passed.
- No live settings, sends, webhook registrations or database changes. No hosted
  CI run or PR created for this work. Ticket 2 remains incomplete until its
  integration controls and persistence-dependent duplicate handling are verified.
- Live browser QA retry passed for rendering at desktop and 390px mobile widths:
  keyboard expansion/collapse worked, credential fields were empty, the disabled
  warning and normal Continue action were present, and no horizontal overflow was
  detected. No settings were submitted or messages sent. One dropdown-arrow
  crowding issue was identified; the credential action now uses the available
  form width with explicit arrow padding.

### Completion dependency

Ticket 2 is not complete. Under ADR-0022, durable duplicate protection requires
ticket 3's versioned schema, pre-send source/attempt associations, recipient-token
key lifecycle, atomic evidence persistence and retention integration. The disabled
draft and deterministic event keys must not be reported as an active receiver or
replay protection. Complete these dependencies before collection opt-in or public
endpoint registration. Live verification additionally needs an owner-approved
public HTTPS test endpoint; no tunnel or deployment is authorized by local QA.

## Required test matrix for tickets 2 and 3

### Approved persistence dependency work, 2026-10-02

Bernie approved including ticket 3's required persistence work. Implemented:

- Schema 0.5.0 creates four verified InnoDB tables without backfill or collection.
- `DeliveryKeyRepository` provisions immutable, encrypted random matching-key
  versions; `RecipientTokens` binds HMAC to version, source, attempt and address.
  Local-part case, dots and tags are preserved; only domain case is normalized.
- `DeliveryAttemptRepository` atomically stores pre-submission associations and
  deduplicated membership, binds acknowledgement IDs without overwrite, and
  resolves exact source/provider/attempt/recipient matches within retention.
- `DeliveryEventRepository` atomically appends evidence and a safe timeline
  projection; rechecks association/membership and compares semantic duplicate
  fields. It never updates the mail-log transport status.
- Repositories suppress database error output during sensitive operations and
  restore the prior setting. Uninstall retain/delete policy includes new tables.

The repositories are intentionally not registered in the send/receiver path yet.
Source enablement coordination, consent and source verification, recipient parsing
at dispatch, coverage read model, HTTP adapter and limiter/source lifecycle race
verification remain. Neither ticket is checked done.

Earlier persistence validation: 1,225 PHP tests / 4,323 assertions; 226 PHP syntax
checks; 7 JavaScript tests. Real local database smoke checks passed for idempotent migration, encrypted
key lookup, exact correlation, duplicate association/event handling and forced
membership/timeline rollback. Disposable `scalyn_qa_` tables and their triggers
were removed. No live provider settings, sends or webhook registrations changed.
The normal administrator migration path may now create the empty 0.5.0 tables;
this is not tracking enablement. Hosted CI and live callback QA remain pending.

### Retention and concurrency follow-up

- Schema 0.6.0 adds explicit matching-key retirement. Retired versions cannot gain
  new attempt references, remain usable for retained attempts, and are deleted
  only after a locked reference recheck finds none. Unretired keys are preserved.
- `DeliveryRetentionRepository` removes expired attempt memberships, events and
  delivery timeline projections atomically, including orphaned attempts. Ordinary
  send history is unchanged. Mail retention removes delivery children in the same
  transaction as their parent. Failures roll back and fail the cleanup status.
- Shared lazy services connect delivery/key cleanup to hourly retention. The
  existing retention setting supplies one site-calendar boundary; delivery storage
  uses its UTC equivalent. Full delivery/key batches report more work pending.
- Real database checks verify deletion rollback, retained-key availability,
  retired-key rejection, orphan cleanup, preservation of transport timeline rows,
  and no resurrection from a callback after deletion.
- Two independent callback worker processes produced one stored result and one
  duplicate, with one projection. The first Windows pipe-based harness stalled
  and failed; cleanup completed. File-backed worker output fixed the harness and
  the retry passed. Disposable QA tables, triggers and output files were removed.
- Full PHPUnit: 1,234 tests / 4,371 assertions. Full WPCS and diff checks passed.
  No live settings, sends or webhook registrations were changed. Collection and
  the public receiver remain disabled; this does not complete tickets 2 or 3.

| Case | Expected evidence |
| --- | --- |
| Authenticated Postmark delivery, matching source/attempt/recipient | One delivery record, original Accepted or Prepared unchanged |
| Missing/wrong credentials; spoofed source/proxy headers | No delivery storage or timeline effects |
| Invalid JSON/type/date, oversized payload, future timestamp | Safe rejection without raw payload logging |
| Duplicate sequential or concurrent callbacks | One record and one timeline projection; acknowledged repeat |
| Two recipients on one provider message ID | Two distinct recipient facts, no whole-message shortcut |
| Delivery and bounce in either arrival order | Both facts retained; mixed evidence visible |
| Known UUID with wrong provider, source, recipient or provider message ID | No delivery claim |
| Missing metadata, unique exact source/provider message-ID match | Correlation allowed; ambiguous match rejected |
| Callback before acknowledgement or after transport timeout | Uses pre-submission association; does not invent Accepted |
| Provider/configuration switch before delayed callback | Original attempt history only; never new-provider health |
| Missing historical source/recipient membership | Unavailable coverage, no inferred all-recipient delivery |
| Token-key rotation/loss, case-sensitive address mismatch | Explicit unavailable/unmatched coverage; no guessed match |
| Persistence failure between evidence and timeline writes | Rollback; retryable 5xx, no partial acknowledgement |
| Retention deletes attempt then callback arrives | No resurrection; no recipient/payload retention |
| Raw payload includes secrets, addresses, HTML or provider response text | None copied to logs, REST, exports or UI |
| Admin configuration/read without capability or nonce | Denied; server callback uses independent authentication |
| SMTP and SendGrid without enabled event adapter | Out-of-band evidence unavailable, never a pass |
| Provider demo/test callback | Not production delivery evidence |
| New install/upgrade, provider selection or address-logging toggle | Delivery collection stays off until explicit per-source enablement |
| Enablement without acknowledgement, capability, nonce or prerequisites | No enablement; safe actionable feedback |
| Disable then receive callback; re-enable after untracked sends | No writes while disabled; no backfill of untracked attempts |
| Raw-recipient logging off with delivery evidence explicitly enabled | Tokens only; no persisted raw callback address or Bcc disclosure |
| Changed retention, late callback, expired attempt awaiting cleanup | Uses attempt creation and configured log retention; no lifetime extension |
| Cleanup/history deletion/uninstall and multiple key versions | Associated evidence/tokens/projections deleted; referenced keys preserved until no longer needed |
| Drawer/full-page timeline, delayed or partial/mixed evidence | Consistent time/status/icon/explanation, explicit timezone and privacy-safe coverage |
| Keyboard/narrow-screen timeline and assistive technology | Labels explain outcomes without relying on colour or exposing private identifiers |
| Delivery callbacks exist but scoring policy/coverage is incomplete | No numerical Deliverability Score or reuse of Email Health score |

Each implementation ticket adds focused PHPUnit tests plus full PHP lint, WPCS,
PHPUnit, JavaScript checks where relevant, and diff checks. Migration tests cover
fresh install, idempotent upgrade, partial failure, retention and uninstall.
UI changes require keyboard, narrow-screen and private-recipient checks.

## Live verification gate

After review and implementation, use an owner-approved HTTPS staging endpoint and
Postmark Live server. Do not expose the local WordPress site without approval.
Configure dedicated webhook authentication and disable content inclusion in bounce
events. Verify a controlled delivery and bounce, duplicate handling and delayed
callback after a provider switch. Record provider evidence and user receipt
separately. Never send to arbitrary third parties to generate failures.

SendGrid remains a separate authenticated-adapter/live-QA follow-up. Deeper DNS,
header/mailbox analysis, scoring and provider health remain later Milestone 8 tickets.
