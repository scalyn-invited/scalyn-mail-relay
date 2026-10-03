# ADR 0026: Deliverability assessment coverage, freshness and scoring policy

Status: **Proposed**, awaiting Bernie's decision (Milestone 8 ticket 7), 2026-10-03.
No numerical Deliverability Score may be displayed until this ADR is accepted
and implemented. The configuration-only Email Health score (ADR-0021) is unchanged
and must never be relabelled as deliverability.

## Context

ADR-0022 reserves a separate Deliverability Score with components for
authentication, domain alignment, DNS quality, header quality, provider
reputation and historical performance. After Milestone 8 tickets 2–6, the
evidence each component can actually rely on is:

| Component | Evidence available | Persisted? | Proposed treatment |
| --- | --- | --- | --- |
| Authentication | `spf_record`, `dkim_record`, `dmarc_policy` (ADR-0023), revision-scoped | Yes (diagnostic runs) | Scored |
| Domain alignment | DMARC configuration assessment (ADR-0023); message-level analysis (ADR-0025) | Configuration only; header analysis is transient | Scored on configuration only; message-level stays informational unless persistence is approved (D4) |
| DNS quality | `mx_record`; `reverse_dns` for SMTP only (ADR-0024) | Yes | Scored where applicable; API providers exclude reverse DNS |
| Header quality | ADR-0025 transient analysis | No | **Not assessed** (weight 0) until persisted evidence exists |
| Provider reputation | None (no provider reputation API integrated) | No | **Unavailable** (weight 0); never inferred |
| Historical performance | Mail-log Accepted/Failed outcomes; authenticated delivery/bounce evidence (ADR-0022, Postmark only, opt-in) | Yes | Scored when sample and coverage minimums are met |

## Proposed policy

### 1. Scoring model

- Per-check points: pass = 100, warn = 50, fail = 0. **unknown, error, not assessed
  and unavailable are excluded**, never scored as zero or as a pass.
- Component score = mean points of its included checks. Overall = weighted mean of
  components that have evidence, renormalized over the weights present.
- Proposed weights (D1): authentication 35, domain alignment 15, DNS quality 10,
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

### 5. Presentation

- Label it "Deliverability Score (evidence-based estimate)", separate from
  "Email Health (configuration)". Never use inbox-placement language.
- Always show the component breakdown, coverage, freshness, exclusions and ranked
  recommendations (Critical / Important / Optional), as ADR-0022 requires.

## Decisions requested from Bernie

- **D1** Component weights (proposed 35/15/10/40/0/0).
- **D2** Rate thresholds (proposed 2% and 5% for both submission failures and hard bounces).
- **D3** Minimum sample (proposed 20 submissions and 20 tracked recipients) and the 60% weight-coverage gate.
- **D4** Whether ADR-0025 header-analysis results may be persisted, as minimal
  verdict and alignment rows with no headers, to feed domain alignment and header
  quality. If not, both stay informational.
- **D5** Whether to integrate any provider reputation source later. Until then the
  weight stays 0 and the component shows "Unavailable".

## Consequences if accepted

Implementation needs a versioned migration for persisted score inputs, a read
model shared by Dashboard and Diagnostics, and tests for the gate, freshness,
exclusions and determinism. Until then the existing Email Health score remains the
only number shown, with its configuration-only caveat.
