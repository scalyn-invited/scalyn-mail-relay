# SendGrid workflow — Milestone 7 ticket 4

This ticket registers the existing SendGrid adapter and connects encrypted
configuration, sandbox verification, a deliberate real test email, and ordinary
`wp_mail()` traffic. It does not send email during deployment. SMTP settings and
the existing SMTP transport path remain unchanged.

## Setup and use

1. Configure the protected server encryption key as described in
   [configuration](SENDGRID-CONFIGURATION.md). Save a Mail Send API key and a
   verified From address in Mail Relay → Providers. A saved key indicator is
   only a presence check.
2. In Setup Wizard, select SendGrid. Selection immediately changes the active
   provider. If the key/sender is missing or unreadable, SendGrid sends fail
   closed; they do not fall back to SMTP or PHP mail. Configure before switching.
   Switching providers clears the previous verification and accepted test-email
   markers.
3. Step 3 validates that the stored credential is readable without exposing it
   to the Admin view. Step 4 sends a synthetic SendGrid sandbox request. HTTP 200
   validates the request format; no email is sent and real-send eligibility or
   recipient delivery is not established.
4. Step 5 sends one real email only after an administrator submits a recipient
   address. A 202 response means SendGrid accepted it, not that it arrived.
   Confirm in the inbox and SendGrid activity/bounce data. No live test is
   attempted automatically.

When SendGrid is active, a `pre_wp_mail` bridge maps normal WordPress To,
subject, body, CC, BCC, Reply-To, safe headers, UTF-8 plain/HTML content type and
local attachments into the shared `MailDispatcher`. A prior `pre_wp_mail`
short-circuit is respected. When SMTP is active, the bridge returns control to
WordPress unchanged. SendGrid uses the configured From address and name; a
different explicit `From` header, unsupported header, inline embed, non-UTF-8
content type, malformed input or unsupported attachment fails closed rather
than silently dropping data or using another transport. This bridge does not
reproduce WordPress's `wp_mail_succeeded`/`wp_mail_failed` hooks for intercepted
mail; operational outcomes use Scalyn's own events and logs.

## Operational boundaries

The adapter receives plaintext credentials only immediately before transport.
Admin views receive only sender fields and key presence. Credentials, HTTP
response bodies, exceptions, recipients, subjects and message bodies are not
added to logs, timeline data, transients or audit events by this integration.
The later, separately approved [optional mail metadata policy](adr/0019-optional-mail-metadata.md)
allows the shared log repository to retain bounded To addresses and subjects
only after explicit administrator opt-in. This does not change adapter payloads,
timeline privacy or audit/report projections.
The shared dispatcher catches unreadable credentials and provider exceptions
with fixed safe messages. A request with no observed HTTP response is recorded
as `Prepared` plus an `acceptance unconfirmed` timeline event, never as proven
`Accepted` or `Failed`, and is not automatically retried. See [ADR-0018](adr/0018-unconfirmed-provider-acceptance.md).

See [ticket 5 outcomes](SENDGRID-OUTCOMES.md), [ticket 6 correlation](SENDGRID-CORRELATION.md)
and [ticket 7 verification](MILESTONE-7-VERIFICATION.md) for subsequent normalization,
attribution, provider-contract parity and live verification evidence. Webhook delivery and
bounce evidence remain Milestone 8. Bernie must review the shared-result/hook
decision and perform browser/live QA with an account he controls before release.

## Local verification

On 2026-09-30, PHPUnit passed 1,029 tests and 2,548 assertions, including new
workflow, fail-closed, unconfirmed-outcome and provider-switch tests. All 179
non-vendor PHP files passed syntax checks; full WPCS and `git diff --check`
passed at the initial implementation checkpoint. Subsequent browser QA confirmed
encrypted settings could be saved and the verification step reached. A live
sandbox probe returned HTTP 401 with a credits-exceeded indication, so successful
sandbox verification and real email acceptance/receipt remain blocked on the
operator's SendGrid account allowance. No real email was sent during these
probes. Status-only 401/403 guidance now includes credits/billing and account
restrictions, and does not infer authentication failure. Detailed error
normalization is now implemented in ticket 5. The unrelated release-readiness PDF was not
modified. Final check results and remote CI are recorded in the pull request.
