# SendGrid configuration — Milestone 7 ticket 2

This document records ticket 2's configuration design. Ticket 4 now connects it
to SendGrid sending; see [the workflow](SENDGRID-WORKFLOW.md). Existing SMTP
sending is unchanged. See [ADR-0017](adr/0017-sendgrid-credential-protection.md)
for the approved storage model and operational limitations.

## Server preparation

1. An operator generates 32 cryptographically random bytes, encodes them in base64,
   and sets `SCALYN_MAIL_RELAY_ENCRYPTION_KEY` in protected server configuration
   before WordPress loads the plugin (for example, an untracked `wp-config.php`
   reading a deployment secret). Use a secret manager or trusted local generator.
   Never paste the encryption key or SendGrid key into chat, tickets or Git.
2. Preserve the encryption key in a separate protected backup. A lost or changed
   key means the saved API credential must be replaced. OpenSSL must be enabled.
3. In SendGrid, prepare a Mail Send-restricted API key and the verified sender.
   Configure domain authentication there; this screen does not manage DNS.

## Administrator workflow

Open Mail Relay → Providers → SendGrid settings. Enter the sender
email/name, choose Add or replace key, enter the API key and save. The password
field is always blank on rendering. Saving validates input and protects the key;
it does not verify the SendGrid account, send email or switch active providers.

To edit sender settings, choose Keep saved key and leave the API-key field blank.
To replace a key, select Add or replace and enter the new value. To remove it,
select Remove saved key, leave the API-key field blank and confirm removal.
Removal does not require a working encryption key, but it does not revoke the
credential at SendGrid. Revoke compromised credentials with the provider.

The presence message only means an encrypted value exists; it is not evidence of
readability, authentication or delivery. Failed saves leave prior settings intact.
Missing or changed server keys never trigger plaintext storage.

The form shows an explicit encryption warning when the server key is missing or
invalid, or OpenSSL is unavailable. On a first save, Add or replace key is the
default. Save feedback uses a visible success/error notice, and the provider
summary is calculated after processing the save. Once a sender and encrypted
key are stored, a persistent saved indicator and wizard shortcut are displayed.
Configuring a key at SendGrid alone does not save it in this WordPress site.

## Validation and remaining work

Focused automated tests cover encryption round trips, random nonces, tampering,
wrong/missing server keys, invalid inputs, protected form actions, private markup
and audit data, keep/replace/remove, failed writes, SMTP preservation and stale
SendGrid verification invalidation. Full check results are recorded after execution.

No live API requests, real credentials, server-key provisioning or test sends are
part of this ticket. Bernie reported browser QA complete on 2026-09-30. That
report establishes the local configuration workflow only; it does not verify API
authentication or actual sending. Bernie must review the implementation and
production secret deployment before use.

Local validation on 2026-09-28 (PHP 8.2.12):

- Focused suite: 31 tests and 68 assertions passed.
- Full suite after service-container integration: 1,011 tests and 2,399 assertions
  passed, including existing Admin page tests. Persistence warnings are expected
  failure-path fixtures, not test failures.
- Syntax: all 175 tracked/new PHP files passed; subsequently edited integration
  files were rechecked successfully.
- WPCS: full repository check passed after formatting corrections. The three
  documented base64 exceptions are limited to binary key/envelope encoding.
- `git diff --check`: passed.
- No commit, push or remote CI run. Ticket 1 documents and the earlier approved
  provider roadmap edits are preserved; the unrelated release PDF is untouched.
