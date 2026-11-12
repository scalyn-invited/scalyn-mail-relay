# ADR 0026: Deliverability assessment coverage, freshness and scoring policy

Status: **Accepted** by Bernie on 2026-10-05 (Milestone 8 ticket 7).
D1–D4 are accepted; D5 is deferred. Implemented locally on 2026-10-12;
automated validation is recorded below. Owner/live visual QA remains pending.
No numerical Deliverability Score may be displayed until this policy is
implemented and validated. The configuration-only Email Health score (ADR-0021) is unchanged
and must never be relabelled as deliverability.

## Context

ADR-0022 reserves a separate Deliverability Score with components for
authentication, domain alignment, DNS quality, header quality, provider
reputation and historical performance. After Milestone 8 tickets 2–6, the
evidence each component can actually rely on is:

| Component | Evidence available | Persisted? | Accepted treatment |
| --- | --- | --- | --- |
| Authentication | `spf_record`, `dkim_record`, `dmarc_policy` (ADR-0023), revision-scoped | Yes (diagnostic runs) | Scored |
| Domain alignment | DMARC configuration assessment (ADR-0023); message-level analysis (ADR-0025) | Configuration plus minimal informational header-result codes | Scored on configuration only; message-level stays informational until sufficient persisted evidence and scoring rules are implemented (D4) |
| DNS quality | `mx_record`; `reverse_dns` for SMTP only (ADR-0024) | Yes | Scored where applicable; API providers exclude reverse DNS |
| Header quality | ADR-0025 in-memory analysis | Minimal verdict/alignment codes under D4; raw headers remain transient | **Not assessed** (weight 0); future scoring requires sufficient persisted evidence and explicit scoring rules |
| Provider reputation | None (no provider reputation API integrated) | No | **Unavailable** (weight 0); never inferred |
| Historical performance | Mail-log Accepted/Failed outcomes; authenticated delivery/bounce evidence (Postmark, SMTP2GO and Brevo, opt-in) | Yes | Scored when sample and coverage minimums are met |

## Accepted policy

### 1. Scoring model

- Per-check points: pass = 100, warn = 50, fail = 0. **unknown, error, not assessed
  and unavailable are excluded**, never scored as zero or as a pass. Stale
  evidence is excluded as well; missing or stale evidence is not a failure.
- Component score = mean points of its included checks. Overall = weighted mean of
  components that have evidence, renormalized over the weights present.
- Accepted weights (D1): authentication 35, domain alignment 15, DNS quality 10,
  historical performance 40, header quality 0, provider reputation 0. Weights are
  constants in code, so for identical persisted inputs the score is identical.
- Historical performance (D2):
  - Submission reliability: Failed ÷ (Accepted + Failed) over the window. Under 2% = 100, 2–5% = 50, over 5% = 0.
  - Bounce rate, where delivery evidence is enabled: hard-bounced ÷ tracked recipients with evidence. Under 2% = 100, 2–5% = 50, over 5% = 0.
  - Delivery confirmation is reported as coverage, not scored, because missing callbacks are not failures.

### 2. Coverage gate (when a number may be shown)

A score is shown only if **all** of these hold. Otherwise the UI shows
**"Not assessed"** with the list of missing evidence:

1. Authentication has at least two of SPF, DKIM and DMARC with non-unknown results
   for the current configuration revision.
2. Historical performance has at least 20 Accepted or Failed submissions for the
   current provider within the window (D3).
3. Components with evidence carry at least 60% of the total non-zero weight.

When delivery evidence is enabled, the bounce-rate sub-score additionally needs
at least 20 tracked recipients with evidence and at least 50% evidence coverage.
Otherwise only the submission-reliability sub-score is used, and the UI says so.

### 3. Freshness

- DNS and authentication evidence is fresh for 7 days, or twice the scheduled
  monitoring cadence if that is shorter. Stale evidence counts as missing for the
  coverage gate; it is not silently reused.
- The historical window is a rolling 30 days, capped by the mail-log retention
  setting. With a shorter retention, the window shrinks and the UI says so.
- A configuration revision change invalidates authentication and DNS evidence
  immediately (ADR-0021). Historical performance is provider-scoped; see ADR-0027
  D1 for revision attribution.

### 4. Traceability and persistence

- Each computed score is persisted with its run or computation UUID, weights
  version, per-component inputs (counts, statuses and windows) and exclusion
  reasons, so it can be reproduced and explained. No recipient data is involved.
- Retention follows the existing health-score retention.
- D4 authorizes persistence of minimal header-analysis verdict and alignment
  results only, without raw message headers or recipient data. This extends
  ADR-0025's transient-only boundary for these minimal results; raw headers
  remain transient. Storage, retention and validation must be implemented before
  results are used. Future domain-alignment and header-quality scoring requires
  sufficient persisted evidence and explicit scoring rules; header quality
  remains weight 0 under the accepted weights.

### 5. Presentation

- Label it "Deliverability Score (evidence-based estimate)", separate from
  "Email Health (configuration)". Never use inbox-placement language.
- Always show the component breakdown, coverage, freshness, exclusions and ranked
  recommendations (Critical / Important / Optional), as ADR-0022 requires.

## Decisions recorded from Bernie — 2026-10-05

- **D1 — Accepted:** weights 35/15/10/40/0/0 in the component order above.
- **D2 — Accepted:** 2% and 5% thresholds for submission failures and hard bounces.
- **D3 — Accepted:** at least 20 Accepted/Failed submissions; when delivery
  evidence is enabled, at least 20 tracked recipients with at least 50% evidence
  coverage for the bounce sub-score. The 60% component-weight coverage gate is mandatory.
- **D4 — Accepted:** persist minimal verdict and alignment results, without raw
  headers or recipient data. Future scoring use is conditional on sufficient
  persisted evidence and implemented scoring rules, not automatic on approval.
- **D5 — Deferred:** no provider reputation source at this stage. The component
  remains "Unavailable", weight 0, and must never be inferred.

## Implementation consequences

Schema 0.11.0 adds `scalyn_deliverability_evidence`, with append-only computation
UUIDs, configuration/provider attribution, diagnostic-run correlation, weights
version and allowlisted JSON traces. `DeliverabilityScorer` replays the persisted
inputs; the repository rejects incompatible or non-reproducible traces.
No old results are backfilled. This follows the existing, separately implemented
0.10.0 failure-category migration; the version gates must remain ordered.

`DeliverabilityAssessment` is shared by Dashboard and Diagnostics. An explicit
nonce/capability-checked refresh computes and saves an assessment, including
Not assessed outcomes. Authorized interactive diagnostic runs also refresh it
after successful publication. A score-save failure never rolls back or relabels
a completed diagnostic run. Scheduled runs do not bypass user capabilities;
their new evidence requires an authorized score refresh.

Implementation interpretations of the accepted rules:

- History requires both provider and configuration revision, following ADR-0027
  D1. Unattributed, other-revision/provider and future-dated logs are excluded.
- The existing DMARC configuration verdict
  `possible_with_configured_selector` contributes **warn = 50**, only with a
  fresh, eligible DMARC result. It never contributes an actual-message pass.
  Other alignment states are Not assessed. Header verdicts remain informational.
- Postmark, SMTP2GO and Brevo opt-in authenticated current-source aggregates are
  supported over the retained 30-day window. The existing provider-health caller
  retains its default seven-day window. Over-limit/unreadable cohorts are
  unavailable, not zero or a truncated sample. Submission reliability remains
  eligible independently when bounce minimums cannot be met.
- The UI labels the historical window and recorded calculation time explicitly;
  new mail/webhooks require refresh to be incorporated. Changed diagnostic run,
  configuration, retention/cadence or collection source/state hides the saved
  number pending refresh. Expired/future diagnostic evidence also hides it.
- Only fixed-enum SPF/DKIM/DMARC verdicts and SPF/DKIM alignment codes are retained
  from the protected header-analysis action. No header text, domains, receiver
  identifiers, addresses, subjects, message IDs or bodies are copied into this
  new storage. User-pasted verdicts are not authenticated webhook evidence.
- Both kinds of evidence use existing health-history retention, bounded to 100
  deletions per cleanup batch. Uninstall retains by default and deletes the new
  owned table only with the existing explicit administrator deletion opt-in.

Tests cover policy thresholds, gates, staleness, exact revision reads, replay,
privacy projections, capability/nonce enforcement, shared markup, schema retry
and uninstall. See [implementation QA and remaining live checks](../qa/2026-10-12-deliverability-score.md).
