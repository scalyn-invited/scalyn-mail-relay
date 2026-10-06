# SMTP2GO provider expansion

Owner: Bernie. Branch: feature/provider-expansion-smtp2go. Base: develop 8c1aac5.

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
