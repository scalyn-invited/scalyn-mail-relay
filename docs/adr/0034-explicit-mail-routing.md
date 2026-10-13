# ADR 0034 — Explicit mail origin and pinned ordered routes

Owner: Bernie. Implementation decision under the approved Milestone 9 plan.

Routing is an opt-in option repository, independently versioned and non-autoloaded.
It stores registered source/type keys and at most three ordered stable configuration
identities. The first target handles a synchronous send. Alternates do not enable
recovery; only the separate recovery opt-in may consume them.

Source attribution uses explicit PHP call sites: WordPress `retrieve_password`,
Contact Form 7 `WPCF7_Mail::compose`, and WooCommerce `WC_Email::send` subclasses.
A bounded argument-free stack inspection identifies these calls, not arbitrary
request-wide hooks, message subjects, recipient domains or supplied headers.
Unsupported call sites remain unknown. Trusted PHP integrations can register a
source/type and wrap their actual send in `MailOrigin::during`, which restores nested
scopes even on exceptions. Registration grants no WordPress user privileges.

At dispatch time ordered targets are pinned to exact current immutable revisions.
Renaming preserves them. Archiving, edits or unavailable encryption stop pinned
resolution; they never cause silent fallback. The selected default is unchanged.
ProviderInterface remains unchanged; SettingsRepository privately resolves transport
configuration. SMTP uses the common bridge only when explicit routing is enabled;
disabled routing preserves existing WordPress SMTP behavior.

API bridge sender validation still checks the configured default authorized sender;
after selecting a trusted route the dispatcher uses that route's authorized sender.
This prevents user-supplied From headers from choosing or spoofing a route.

Existing webhook collectors are default-revision scoped. Non-default routed sends
do not bind to the default source, even for the same provider. Absence of that
delivery evidence is unknown, never a success or failure.

Policy mutations require manage-settings, critical managed-access override, nonce,
fresh form/configuration tokens, bounded input and repository validation. Audit
records fixed outcomes only. No message data or credentials enter the policy.
Uninstall retains it unless explicit deletion is selected. Disable routing to roll
back runtime behavior; do not downgrade encrypted configuration storage code alone.
