# SMTP Verification 2026 09 17

## Result and scope

Milestone 1 ticket 3 passed the local existing-configuration SMTP verification.
The wizard connection test succeeded; one explicitly authorized test email was
accepted by the configured SMTP server; Bernie confirmed "Received in Inbox."
No production code, SMTP credentials, DNS records, or provider settings were changed.

This is evidence for one configured transport and one recipient, not a general
deliverability certification or evidence that the earlier Gmail authentication
failure has been resolved. User-reported receipt is recorded here separately;
the plugin's persisted status remains Accepted, not Delivered.

## Environment

- Source commit: `7a9699404e86c5184c8f91b04024380abf42e583`.
- Existing branch: `fix/diagnostics-qa-followups`; verification/documentation only.
- Site: existing authenticated local WordPress installation at `http://localhost/bernz/`.
- WordPress 7.1, PHP 8.2.12, MariaDB 10.4.32.
- Existing SMTP configuration was reused; credential replacement and a new-provider setup were not exercised live.
- MariaDB remains below the supported 10.6 minimum; see the clean-install record.

## Observations

1. Wizard step 4 displayed: "Successfully connected to the configured SMTP server."
   The connection-only action sends no email.
2. Step 5 sent one message to the inbox designated by Bernie, with subject
   "Scalyn Mail Relay — Test Email." No retry was performed.
3. The result displayed: "The configured SMTP server accepted the test email.
   Check your inbox to confirm receipt."
4. Bernie explicitly confirmed Inbox receipt in this task.
5. Email Logs displayed the newest row as ACCEPTED, provider smtp, zero attachments.
6. Its View Timeline link opened UUID `8ae78e94-e4c9-4cb8-85b0-29d6bb5088dc`.
   Created and Accepted at were both `2026-09-17 00:19:57` as displayed by WordPress
   (site timestamp; not independently converted to the workstation timezone).
   One event read "Message accepted by provider" with status ACCEPTED and the
   safe message "Message accepted by the configured SMTP server."
7. Both log and timeline views explained that acceptance does not guarantee inbox delivery.

## Automated evidence and boundaries

- Focused command: `php vendor/bin/phpunit --filter 'WizardControllerTest|SmtpProviderTest'`.
- Result: **128 tests, 324 assertions passed**, PHP 8.2.12 / PHPUnit 10.5.64.
- Nearby wizard tests cover configuration validation, blank-password preservation,
  capabilities/nonces, connection verification, safe results, and test dispatch.
- `git diff --check` passed after the documentation update.
- No PHP changes were made. Full lint/WPCS/PHPUnit baseline evidence is in
  [the prior baseline record](2026-09-16-baseline-verification.md); full checks
  were not rerun for this documentation-only verification.
- Live failure injection, credential replacement, other SMTP configurations,
  and recipient-provider authentication validation were not performed.
- Source displayed as an em dash and the timeline exposed only the acceptance
  event. This run does not claim complete Generated-through-Accepted stage coverage.
- The next checklist ticket remains open for diagnostics, persisted health scoring,
  and broader correlated log/timeline verification.

## Privacy and side effects

One email and its normal plugin log/timeline records were created. Provider
verification and test-acceptance state were updated through the existing wizard.
No credentials, recipient address, message body, or raw server responses are
included in this report. No records were deleted, no migration was needed, and
no commit, merge, push, or release action was performed. Final review is Bernie's.
