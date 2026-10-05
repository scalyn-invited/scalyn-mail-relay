# ADR 0027: Provider Health Assessment

Status: **Accepted** by Bernie on 2026-10-05 (Milestone 8 ticket 8).
D1–D4 and the additional unknown/stale policy are accepted. Implementation remains
pending. The ADR-0026 acceptance dependency is satisfied by its acceptance on the
same date; this does not mean either feature has been implemented.

## Context

Provider cards currently show "Settings saved" and a "Verification: Success
recorded" timestamp. That records a past success, not current health, and is not
scoped to a configuration revision. The checklist requires a separate
**Connection status** and **Provider health** that are provider-specific,
traceable and freshness-aware, and never reuse the site-wide health score.

Current attribution facts, from the code:

- `scalyn_mail_logs` stores `provider` and `status` but **not** the configuration
  revision, so a send cannot be attributed to the configuration that made it.
- The diagnostic configuration revision (ADR-0021) rotates on provider, provider
  settings or DKIM selector changes.
- Delivery attempts (ADR-0022) do store `configuration_id`, but only for opted-in
  Postmark sends.
- The provider verification timestamp is a single value, not per revision.

## Accepted rules

### Connection status (independent of health)

Shows the latest connection check for the **current** revision, with its timestamp:
"Connected (checked {time})", "Connection failed (checked {time})", or "Not checked
for the current configuration". Connection evidence is labelled stale after 7 days
(D2). A historical success is never shown as a continuously live connection.

### Provider health states

Health is evaluated per provider and current configuration revision, from evidence
inside the freshness windows:

| State | Rule (first match wins) |
| --- | --- |
| **Critical** | Latest current-revision connection check failed with auth/config; or at least 3 consecutive auth/config submission failures; or submission failure rate over 20% with at least 10 attempts in 24 h |
| **Warning** | Failure rate 5–20% (at least 10 attempts, 24 h); any provider-rejection or rate-limit failure in 24 h; connection evidence stale; or, with delivery evidence, a hard-bounce rate over 5% (at least 20 tracked recipients, 7 days) |
| **Healthy** | Connection check passed for the current revision within 7 days, at least 10 attempts in 7 days, and no Warning or Critical rule matched |
| **Unknown** | Anything else, including too few attempts, no current-revision connection check, or a new configuration. Unknown is never shown as Healthy |

Each state lists its supporting findings (rule, counts, window, timestamps) and a
recommended action. Unconfirmed acceptance (ADR-0018) counts as neither success
nor failure; it is reported separately.

Unknown and stale states are not failures and must never be silently converted
to Healthy or Critical. They are excluded from scoring calculations where
applicable. A stale-connection Warning above is an evidence-freshness advisory,
not a failed connection or submission. Stale evidence cannot support a Healthy
or Critical finding; fresh, independent failure evidence may still support
Critical under the rules above.

### Provider-specific notes

- **SMTP:** out-of-band delivery evidence is unavailable. Health relies on
  connection checks and submission outcomes only, and the card says so.
- **SendGrid:** sandbox verification does not prove real-send permission (ADR-0016),
  and its event webhook is not yet integrated.
- **Postmark:** delivery and bounce evidence is used only when collection is enabled
  and the source is verified (ADR-0022).

### Configuration attribution (decision D1)

Accepted option A; alternatives B and C are retained for decision history only:

- **A (accepted):** a versioned, additive migration adds a nullable
  `configuration_id` to `scalyn_mail_logs`, set at dispatch from the current
  revision. Existing rows stay unattributed and are excluded from per-revision
  health; they are never backfilled.
- **B:** no schema change. Attribute by time, counting only sends after the latest
  revision change, which needs a persisted "revision changed at" timestamp. This
  is less exact when changes and sends interleave.
- **C:** per-provider health only, ignoring revisions. This contradicts the checklist
  requirement for configuration-change invalidation, so it is not recommended.

### Read model and boundaries

A `ProviderHealthAssessment` service computes states on read from existing
repositories, behind capability checks. It uses bounded, indexed queries and
adds only D1's attribution column and D4's revision-keyed connection-verification
evidence, with explicit retention. Admin views consume only the read model.
Multiple saved configurations, routing and automatic recovery remain Milestone 9.

## Decisions recorded from Bernie — 2026-10-05

- **D1 — Accepted:** option A, nullable `configuration_id` on `scalyn_mail_logs`.
  Existing records remain unattributed and are excluded from revision health.
- **D2 — Accepted:** connection freshness 7 days; failure-rate window 24 hours;
  health eligibility 7 days; delivery/bounce evidence 7 days.
- **D3 — Accepted:** 5%/20% failure thresholds, 3 consecutive authentication or
  configuration failures, minimum 10 attempts, and the 5% hard-bounce threshold
  with at least 20 tracked recipients.
- **D4 — Accepted:** store verification results and timestamps keyed by
  configuration revision. The existing display may remain, but health
  calculations must use revision-keyed evidence.
- **Additional policy — Accepted:** unknown and stale are not failures, are never
  silently converted to Healthy or Critical, and are excluded from scoring
  calculations where applicable.

## Implementation consequences (pending)

Implementation adds D1's option-A migration, a revision-keyed verification record
(D4), the read model and provider-card UI, and tests for attribution, stale and
missing evidence, mixed outcomes, configuration changes, permissions, privacy and
explanations. It also needs controlled SMTP/SendGrid/Postmark verification and
desktop/mobile card QA.

### First implementation slice — dispatch attribution (2026-10-05)

Schema 0.8.0 adds nullable `scalyn_mail_logs.configuration_id` and the
`configuration_created (configuration_id, created_at, id)` index. The migration
uses `dbDelta()`, verifies the nullable column and exact index, and only advances
the version after verification. No existing row is backfilled.

`MailDispatcher` captures the opaque revision from the same settings instance
used for sending, before transport begins. The three terminal lifecycle hooks
carry it as an optional third argument. Existing two-argument observers and
publishers remain compatible; missing or malformed revisions stay unattributed.
Logging never derives attribution from message context, provider metadata or
settings read after sending. It writes attribution only on insertion, not when
updating an existing message row. Before schema 0.8.0, the new column is omitted
so a pending migration does not break ordinary logging or sending.

The column follows mail-log retention and existing uninstall policy. Rolling
back code leaves the additive column/index in place; do not downgrade the schema
version or backfill old evidence. No recipient or credential data is added.

No health state or score may be inferred from the attribution column alone.

### Second implementation slice — connection evidence (2026-10-05)

Schema 0.9.0 adds `scalyn_connection_evidence`, keyed by configuration revision
and provider. Each key retains one latest-started completed check: `passed`,
`failed`, or `unknown`, with UTC microsecond start and completion timestamps.
An atomic conditional upsert prevents a delayed older check from replacing a
newer one; equal start timestamps leave the existing record unchanged.

The wizard's capability/nonce-protected step 4 invokes `ConnectionVerification`.
It captures the revision before transport and persists no provider message,
metadata, credential, header, or recipient data. Exceptions are `unknown`, not
inferred authentication failures. Generic failed checks have no inferred failure
category and cannot alone establish an auth/config Critical finding. Storage
errors do not reverse the actual transport result; the read model reports only
retained evidence and its timestamp, never a continuously live connection.

The current-scope read model requires the settings capability, never falls back
to the legacy verification flag or timestamp, and returns Unknown for missing,
malformed, unreadable, future-dated or retention-expired evidence. Evidence older
than seven days is Stale, not Failed. Ordinary/test email acceptance does not
create connection-check evidence. The existing wizard success display remains
for compatibility; provider-health calculations must use this new read model.

Connection evidence follows the configured mail-log retention, including an
hourly indexed cleanup limited to 100 rows. Default uninstall retains it;
explicit delete-on-uninstall removes the table. Rolling back leaves additive
schema in place; do not backfill verification from the legacy success timestamp.

Local unit/controller regression and disposable real-database checks cover
scope, privacy, freshness, idempotency, late-result ordering and retention.
Controlled live transport QA and owner review remain pending. Health calculations,
provider-card UI and assessment scoring are not implemented by these two slices.
