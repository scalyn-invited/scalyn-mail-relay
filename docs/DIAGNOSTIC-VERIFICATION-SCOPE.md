# Diagnostic verification scope

The dashboard and diagnostics score describes configuration evidence and provider
submission history, not receiver authentication or inbox delivery. A score of
100/100 can coexist with a later authentication rejection.

- SPF checks find records and a recognized terminal mechanism. They do not
  evaluate the actual outbound IP, envelope sender, recursive includes or lookup limits.
- DKIM checks find a non-empty public-key value at the configured selector.
  They do not validate key usability, enable provider signing or verify a message signature.
- DMARC checks inspect the published policy, not actual message alignment.
- Unknown/error checks remain excluded from scoring. New score summaries state
  how many stored DNS/transport checks contributed; weights and alert thresholds
  are unchanged. No arbitrary penalty is substituted for missing evidence.
- Both pages display verification limitations independently of stored snapshots,
  including historical findings that used the word “valid”. Rerun diagnostics to
  generate the more precise finding text; historical evidence is not rewritten.
- Accepted means the configured provider acknowledged submission. The recipient
  can reject the message later. This version does not ingest those later bounces
  automatically, so the original Accepted status remains a handoff observation.

## Investigating a Gmail authentication rejection

For a 550 5.7.26 bounce reporting failed SPF and DKIM, ask the sending provider to
verify its actual outbound IP against the envelope-sender domain's SPF policy,
and to enable/correct DKIM signing with the matching published key. The domain
checked by the plugin (configured From domain, or site fallback) may differ from
the envelope-sender or signing domain. Obtain the provider's required records;
do not blindly authorize an IP or publish a guessed key.

After correcting provider/DNS configuration, send an explicitly authorized test
and inspect receiver Authentication-Results or provider delivery evidence.
SMTP acceptance alone does not verify the correction. See
[Google's sender authentication requirements](https://support.google.com/mail/answer/81126#authentication).

This change adds no schema, credentials, message-body storage, DNS writes or
outbound mail. It corrects diagnostic claims, coverage presentation and test-email
guidance; it does not implement a message-level authentication engine.
