# ADR 0027: Provider Health Assessment

Status: **Proposed**, awaiting Bernie's decision (Milestone 8 ticket 8), 2026-10-03.
Depends on ADR-0026 being accepted. No implementation until this ADR is accepted.

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

## Proposed rules

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

### Provider-specific notes

- **SMTP:** out-of-band delivery evidence is unavailable. Health relies on
  connection checks and submission outcomes only, and the card says so.
- **SendGrid:** sandbox verification does not prove real-send permission (ADR-0016),
  and its event webhook is not yet integrated.
- **Postmark:** delivery and bounce evidence is used only when collection is enabled
  and the source is verified (ADR-0022).

### Configuration attribution (decision D1)

Options:

- **A (recommended):** a versioned, additive migration adds a nullable
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
stores no new history beyond D1's column. Admin views consume only the read model.
Multiple saved configurations, routing and automatic recovery remain Milestone 9.

## Decisions requested from Bernie

- **D1** Attribution option (A recommended).
- **D2** Freshness windows (connection 7 days; failure rate 24 h; health eligibility 7 days; bounces 7 days).
- **D3** Thresholds (5% and 20% failure rate; 3 consecutive auth/config failures;
  minimum 10 attempts; 5% hard bounces with at least 20 recipients).
- **D4** Whether the connection check needs a per-revision record. Recommended:
  store the verification result and timestamp keyed by revision, replacing the
  single timestamp for health purposes while keeping the existing display.

## Consequences if accepted

Implementation adds D1's migration (if A), a revision-keyed verification record
(D4), the read model and provider-card UI, and tests for attribution, stale and
missing evidence, mixed outcomes, configuration changes, permissions, privacy and
explanations. It also needs controlled SMTP/SendGrid/Postmark verification and
desktop/mobile card QA.
