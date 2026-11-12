# ADR 0024: Reverse DNS only for known sending infrastructure

Status: Implemented for Bernie's review (Milestone 8 ticket 5), 2026-10-03.

## Decision

Add a `reverse_dns` diagnostic check in a new `infrastructure` category.

- **Known infrastructure only:** the check evaluates the configured SMTP host's
  public addresses. With no SMTP host (API providers), it returns
  **unknown / not assessed**, explaining that the provider operates its own
  outbound servers. Private, reserved, loopback and `localhost` relays are also not
  assessed, because the public sending address is not visible.
- **Forward-confirmed reverse DNS:** for up to four public addresses, a PTR name
  must resolve back to the same address. IPv6 is compared in binary form.
  **pass** means every checked address is confirmed. **warn** (medium) means a PTR
  record is missing or does not resolve back. **unknown** means resolution or PTR
  lookups failed.
- **Scope wording:** the configured host is the *submission* server. Results state
  that reverse DNS affects recipient acceptance only when that server delivers
  directly to recipients; provider relays are the provider's responsibility.
- **Scoring:** `HealthScorer` scores only the `dns` and `smtp` categories, so this
  check does not change the configuration score. Whether infrastructure evidence
  joins any score is left to the ticket 7 scoring policy.
- **Recommendations:** a rule exists for SMTP only, and only an observed warn or
  fail produces an item. Unknown or not assessed results never create
  recommendations.
- **UI:** a "Reverse DNS (submission server)" card on the SMTP diagnostics
  section, marked as informational and not scored.

## Consequences

Diagnostic runs store one more result row. API runs record an explicit not-assessed
row rather than nothing. Evidence contains public addresses and PTR names of the
configured server only, with no credentials. There are no schema, REST,
capability or dependency changes.
