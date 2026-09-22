# Configure a DKIM selector

Data Controls is now labelled **Settings**. Its existing URL is retained for
bookmarks and integrations. DKIM and data-control settings use separate forms
and nonces, so saving either form preserves the other settings. The Scheduled
health monitoring status panel appears only on the Dashboard; its cadence is
still configurable in Settings. Diagnostics retains a link to DKIM settings.

Open **Mail Relay → Settings → DKIM configuration**. Enter your provider's
selector (for example `selector1`) and click **Save DKIM selector**, then **Run
Diagnostics Now** on the Diagnostics page. Scheduled runs also read this saved setting. Existing results
are retained unchanged until another run publishes new evidence.

Use the selector corresponding to the domain in your configured From email.
For `sender@example.com` and selector `selector1`, the check queries
`selector1._domainkey.example.com`. If no valid From email exists, the existing
diagnostic site-domain fallback applies. This option does not override the
signing domain: if your provider signs with a different domain, do not interpret
a lookup against your From domain as verification of that signature.

Enter only the selector, not a full DNS hostname or a public/private key. Current
support matches the existing checker: one label, 1–63 letters/digits/hyphens/
underscores, starting and ending with a letter or digit. Dotted selectors are
not currently supported. Blank clears the setting; DKIM then remains Unknown.

Saving requires the manage-settings capability and a valid WordPress nonce.
Diagnostic-only users cannot edit it. Invalid submissions preserve the saved
value. The existing settings option stores `advanced.dkim_selector`; no database
schema change is needed. Audit records contain only the changed field name,
never the value. The credential-free diagnostic context allowlists this public
selector when present; SMTP credentials remain excluded.

This performs a DNS-record diagnostic. It does not publish DNS records, enable
provider-side DKIM signing, or cryptographically verify a delivered message.

## Verification (2026-09-22)

- Full PHPUnit suite after the Settings relocation: 869 tests, 1,981 assertions passed; selector tests cover
  save/clear, invalid input, permissions, nonce, failed writes, audit minimization,
  credential isolation and the actual checker lookup name.
- Page regression tests verify independent form saves, DKIM on Settings rather
  than Diagnostics, and the monitoring panel remaining only on Dashboard.
- WPCS and `git diff --check` passed; PHP syntax passed for 150 source/test files.
- Isolated WordPress CLI QA verified real capability/nonce-protected saves,
  persistence, context-to-check propagation, invalid-input preservation and clear.
  The prior isolated settings were restored afterward; DNS lookup was stubbed,
  with no message sent and no DNS record changed.
- Browser review of the actual form rendered with synthetic configuration
  verified label association, help text and layout. No live settings were entered.

Implemented alongside the existing uncommitted Milestone 5 work, which remains
intact. No commit or pull request was created for this addition. Bernie reviews
the added public context setting and audit field before release.
