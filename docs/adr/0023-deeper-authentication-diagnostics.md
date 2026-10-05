# ADR 0023: Deeper authentication diagnostics evaluate published policy, not messages

Status: Implemented for Bernie's review (Milestone 8 ticket 4), 2026-10-03.

## Decision

The existing `spf_record`, `dkim_record` and `dmarc_policy` checks keep their IDs,
categories, `DiagnosticResult` shape and scoring inputs. They now evaluate the
published DNS policy more deeply. Every message continues to state that sending-IP
authorization, message signatures and message alignment are **not** verified.

### SPF (`SpfEvaluator`)

- Walks `include` and `redirect` chains through the check's injected TXT lookup and
  counts DNS-querying terms (`include`, `a`, `mx`, `ptr`, `exists`, `redirect`)
  against the RFC 7208 limit of 10, and void lookups against the limit of 2.
- `redirect` is ignored when the record contains `all` (RFC 7208 §6.1). A redirected
  record's `all` counts as the domain's own.
- Only a cycle in the current chain counts as a loop; the same include in separate
  branches is legal and simply counted twice.
- **fail**: more than 10 lookups or 2 void lookups, include/redirect targets without
  exactly one SPF record, loops, invalid syntax or networks, multiple redirects, or
  `+all` (explicit or implicit).
- **warn**: `?all`, deprecated `ptr`, missing terminal mechanism, or macros. With
  macros the lookup count is only a lower bound, because they cannot be expanded offline.
- **unknown**: any include or redirect that cannot be resolved. An incomplete
  evaluation is never a pass.
- Behaviour change: `?all` previously passed and now warns, because a neutral
  policy gives receivers no instruction.

### DKIM

- Parses `k=`, `t=` and `h=`, and measures the RSA modulus length with OpenSSL
  (SPKI or PKCS#1).
- **fail**: key under 1024 bits, unparseable or non-base64 key, unsupported key
  type, or a malformed Ed25519 key.
- **warn**: 1024–2047-bit RSA, testing mode (`t=y`), hashing restricted away from
  sha256, Ed25519 (not verified by every receiver), or a key length that cannot be
  measured because OpenSSL is unavailable.
- Selector configuration is unchanged (ADR-0021); no selector is ever guessed.

### DMARC

- Uses a DMARCbis-style tree walk: the exact `_dmarc` name first, then parent
  domains above the TLD, so subdomain senders inherit the organizational policy.
  A subdomain applies `sp=` when present. No public suffix list is bundled, and the
  walk never queries a bare TLD.
- Validates `pct`, `adkim`, `aspf` and `sp`. An enforcing policy with `pct<100` or
  invalid tags warns.
- Alignment is a **configuration assessment only**, stored in `raw.alignment`:
  alignment modes, whether a DKIM selector is configured on the From domain
  (`possible_with_configured_selector` or `not_assessed`), whether `rua` reporting
  exists, and SPF alignment as `not_assessed`. The envelope-sender domain belongs
  to the provider and is not observed. Actual alignment requires message headers
  or aggregate reports.

## Consequences

Some installations will see a status change after their next run, for example
from pass to fail for SPF policies over the lookup limit. This reflects receiver
behaviour, not a regression. Evidence remains public DNS data plus include domain
names; no credentials or message content are involved. Results are deterministic
for a given DNS state. No schema, REST, capability or dependency changes.
