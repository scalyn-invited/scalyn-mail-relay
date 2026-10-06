# SMTP2GO and Brevo provider expansion

Owner: Bernie. Branch: feature/provider-expansion-smtp2go. Base: develop 8c1aac5.
SMTP2GO commit: 8f9c738. Brevo is a separate stacked change on
feature/provider-expansion-brevo, which contains both providers for local QA.

Implementation covers registration, encrypted keep/replace/remove, inline wizard
configuration, non-sending verification, wp_mail routing, bounded plain/HTML and
attachment sending, current-revision diagnostics and existing health projection.
No account, provider selection, DNS, schedule or real message was changed during
implementation. Remote CI and live delivery are not claimed by offline tests.

## Manual acceptance (pending)

1. In an approved staging site, select SMTP2GO in Wizard step 2.
2. Save an authorized sender and API key in step 3. Enable /email/send and
   /stats/email_cycle permissions in SMTP2GO. Disable unwanted tracking there.
3. Confirm save feedback, empty credential markup and Continue to verification.
4. Run step 4: no email should be sent. A successful read check does not prove
   sending permission. A wrong/revoked key must fail without exposing a response.
5. Send one test to an inbox you control, then a Contact Form 7 plain/HTML test
   with an attachment. Compare receipt with the provider activity and log UUID.
   Accepted in the plugin is not proof of inbox delivery.
6. Check Providers shows API (HTTPS), revision-specific connection/health, and
   unsupported delivery evidence. No Postmark evidence may be reused.
7. Change the sender/key or switch provider. Prior diagnostics/verification must
   not appear current; wizard step 6 starts without results for the new revision.
8. Keep a key with a blank field; replace it; test confirmed removal. Confirm
   audit contains field names only. Restore the intended staging provider.

Do not induce ambiguous real sends for retry QA: automated fixtures cover partial
acceptance and timeouts without risking duplicates. Do not paste keys into reports.

Repeat the manual steps for Brevo using a Brevo API key (not an SMTP key), an
authorized sender and an activated transactional account. Its connection check
reads /account and never submits email. Check allowed IPs if access is rejected.
Brevo supports specific attachment extensions; use a small .txt, .pdf or .png
fixture for live QA. Provider-specific delivery/bounce webhooks remain unavailable.

## Local evidence

Final combined validation (2026-10-06): PHP lint passed for 269 files; repository
WPCS passed; PHPUnit passed 1,476 tests / 5,326 assertions; JavaScript syntax and
all 7 JavaScript tests passed; git diff --check passed. Failure-injection tests
emit expected sanitized audit/logging warnings; the suite completed successfully.

- WordPress read-only UI check: both new cards render as API (HTTPS), not
  configured, inactive health not assessed, delivery evidence unavailable.
- Wizard step 2 visually checked: five providers render with matching spacing,
  radio labels and guidance. Postmark remained selected; no save or send action.
- Offline form tests cover inline saving, nonce/capability rejection, encryption
  unavailable, keep/replace/remove, audit privacy and blank secret markup.
- Offline transport tests cover plain/HTML, Cc/Bcc/Reply-To, attachments, fixed
  HTTP security options, wrong credentials, rejection, timeout, malformed and
  partial responses, no retries, wp_mail routing and revision invalidation.
- Offline wizard and diagnostic-context tests cover the new providers and ensure
  previous-provider SMTP details and secrets are absent from current checks.
- Live SMTP2GO/Brevo credentials, account verification and inbox tests are pending.
  No deployment, release, remote CI or owner review is claimed here.
