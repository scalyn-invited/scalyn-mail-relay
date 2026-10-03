# ADR 0025: Transient header analysis for received test messages

Status: Implemented for Bernie's review (Milestone 8 ticket 6), 2026-10-03.

## Decision

Message-level authentication evidence comes from an administrator-controlled
test: the administrator sends a test email to a mailbox they control and pastes
the received copy's original headers into Diagnostics, under "Check a received
test message". No external seed-list or inbox-placement service is used.

### Privacy boundary

- Headers are parsed in memory once per request. They are not stored, logged,
  audited or re-rendered, and the form field is cleared after submission.
- Output is limited to the From domain, envelope and DKIM domains, DKIM selectors,
  receiver verdicts, the receiver's authserv-id hostname, hop count and whether
  TLS was observed. Local parts, display names, subjects, message IDs, IP
  addresses, `Authentication-Results` comments and the body are never shown.
- The input is capped at 64 KiB and processing stops at the first blank line, so
  any pasted body is ignored.
- Access requires `RUN_DIAGNOSTICS` and a dedicated nonce. The guidance asks for
  messages sent to the administrator's own mailbox only.

### Evidence semantics

- The topmost `Authentication-Results` header (from the receiving server) is the
  source. A copy without it, such as a sent-folder copy, gives **Not assessable**.
- SPF and DKIM alignment are computed against the From domain: exact domain is
  strict, a subdomain relationship is relaxed, and siblings under a shared parent
  are "possibly aligned", because an exact organizational-domain decision needs
  the public suffix list. When present, the receiver's own DMARC verdict is
  authoritative.
- An SPF pass for the provider's bounce domain is shown as not aligned, without
  lowering an overall result that passed DMARC through aligned DKIM.
- Results describe one message at one receiver. They are **not** persisted, not
  added to the configuration score or history, and not presented as inbox
  placement or ongoing deliverability.

## Consequences

This closes the message-alignment gap left by ADR-0023's configuration-only
assessment, without new storage, dependencies, REST routes or external services.
Persisting results, or feeding them into a Deliverability Score, would need the
ticket 7 policy and a separate decision.
