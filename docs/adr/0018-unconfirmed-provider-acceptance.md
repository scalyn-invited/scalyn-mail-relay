# ADR-0018: Preserve unconfirmed provider acceptance

Date: 2026-09-30. Owner: Bernie, sole project owner and developer.
Status: Implemented for Milestone 7 ticket 4; awaiting Bernie's architecture review.

## Context and decision

SendGrid can time out or lose its HTTP response after a request has been attempted.
An absent response is not evidence that SendGrid accepted or rejected the message.
The prior shared dispatcher sent every non-success result to `MAIL_FAILED`, which
would record a proven `Failed` status for this ambiguous case and could drive an
unsafe resend.

`SendResult` now carries an explicit `acceptance_unconfirmed` flag, defaulting to
false for existing providers. The dispatcher routes a flagged result to
`MAIL_OUTCOME_UNCONFIRMED`, not `MAIL_SENT` or `MAIL_FAILED`. The log records
`Prepared` (the last safe known state) and an append-only timeline event with
the same message UUID. No automatic retry is permitted. The caller still gets
`success=false` and safe guidance to inspect provider activity before retrying.
The setup-wizard test-email audit uses `unconfirmed`, never `failed`, in this
case.
HTTP 202 alone is `Accepted`; sandbox HTTP 200 verifies request format only.

This is a bounded extension of the existing contract, not a new lifecycle state.
Milestone 7 ticket 5 extends this policy to uncertain HTTP statuses and SMTP
send exceptions without phase/per-recipient evidence. See [outcome rules](../SENDGRID-OUTCOMES.md).
This conservative interpretation is implemented and awaits Bernie's review.
Ticket 7 adds an explicit SMTP connection boundary before `send()`; only failures
before that boundary are known non-submissions. Any exception after send begins
is unconfirmed, regardless of keyword classification, since partial-recipient
acceptance or a lost DATA response may have occurred. Connections are closed in
a finally block. No additional shared result fields or lifecycle states are added.
Milestone 8 delivery webhooks
remain the only basis for confirmed delivery; acceptance never means inbox
placement.

## Consequences and rollback

Unconfirmed attempts appear as `Prepared`, with an explicit timeline note. They
are neither counted as accepted nor as failed. Operators must inspect SendGrid
activity before deciding whether to resend. No schema, migration, new stored
secret, dependency, or new scheduled job is introduced. If rolling back, first
restore SMTP as the active provider so WordPress mail does not silently fall
back to local PHP mail, then revert this ticket's code. Retained log/timeline
rows use existing schema and need no data migration.
