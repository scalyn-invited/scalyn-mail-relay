# ADR 0035 — Logical message and submission evidence

Owner: Bernie. Implements the approved Milestone 9 recovery safety boundary.

Schema 0.12.0 adds an InnoDB submission-attempt table. A logical UUID identifies
one mail request; each transport attempt has its own UUID and a unique bounded
logical/attempt-number pair. Existing logs and delivery associations retain their
message UUID meaning (the individual submission). No historical attribution is
backfilled. New attempt evidence contains IDs, provider, registered origin,
revision, timestamps and normalized outcomes only. No recipient, header, body,
credential or raw provider response is stored here.

Prepared is persisted before adapter invocation; incomplete evidence does not
become Failed or Accepted. Each outcome finalizes once. Logging remains isolated
for legacy synchronous sends. The opt-in recovery path requires durable attempt
evidence and stops before transport if that evidence cannot be established.

SendResult gains backward-compatible optional safe_to_retry and
retry_after_seconds fields. Defaults explicitly forbid resubmission. Retry requires
both retryable and safe_to_retry, a recognized transient category and neither
success nor acceptance_unconfirmed. Auth/config, permanent, unknown, partial,
unconfirmed or accepted results never permit recovery. ProviderInterface does not
change. Provider minimum delays remain numeric and are not inferred from text.

Submission metadata follows existing log retention; uninstall retains by default
and explicitly opted-in deletion includes the new table. Recovery operational
state and encrypted payload storage are implemented separately in ticket 6.
Code rollback is safe with this additive table retained; never replay unfinished
attempts after rollback or worker interruption.
