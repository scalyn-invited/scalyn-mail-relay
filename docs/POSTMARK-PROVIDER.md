# Postmark provider: Milestone 7B

Owner: Bernie. Branch: `feature/m7b-postmark-provider`, based on merged develop
`2d06975` (PR #60). Postmark is an additional provider; active selection is never
changed by registration or visiting the Providers page.

## Setup and controlled QA

1. Create a Postmark account and a **Live Server**. Complete sender/domain
   verification and any account approval requirements. Do not purchase a plan
   solely because this adapter requires Live: Live describes server behavior.
2. Copy its **Server API token**, not the Account API token. Keep it private; do
   not paste it into chat, tickets or source files. Server tokens can access server
   details as well as sending, so use a dedicated server for this integration.
3. In Setup Wizard step 2 select Postmark. Selection changes the active route
   immediately: do this during a controlled setup window. Existing SMTP/SendGrid
   settings remain saved but are not automatic fallback routes.
4. In step 3 enter the verified sender/name, choose Add or replace key, enter the
   Server API token and save. The existing SCALYN_MAIL_RELAY_ENCRYPTION_KEY server
   configuration and PHP OpenSSL are required. The token input always stays blank
   after save; confirmation describes saved configuration, not a verified token.
5. Continue to Verify Connection. This only checks access to a Live server. No
   message is sent, and sender authorization/account allowance remain unverified.
6. Deliberately send one test to an inbox you control. Check Postmark activity and
   the recipient inbox/spam separately from the plugin's Accepted result.
7. Verify a plain message and an HTML message with a harmless attachment using
   fresh UUIDs. Confirm sender, formatting, attachment, recipient roles and logs.
   Do not retry an uncertain send until provider activity has been checked.

Only the `outbound` transactional stream is supported. Sandbox and POSTMARK_API_TEST
tokens cannot be used to replace real WordPress sending. API acceptance does not
prove delivery; provider webhooks and complete provider health remain Milestone 8.
The existing Diagnostics SMTP/TLS check is not a Postmark connection/health check;
existing DNS snapshots must not be treated as proof of Postmark authentication.

## Boundaries and privacy

- Up to 50 total To/Cc/Bcc recipients; one Reply-To; plain text or HTML body up to
  1 MiB; subject up to 998 bytes; strict UTF-8 JSON payload up to 8 MiB.
- Up to ten local attachments, 4 MiB combined raw content, safe filenames;
  attachments use application/octet-stream. Paths are never transmitted.
- Supported extra headers: X-Priority, X-Mailer, List-Unsubscribe. Unsupported
  headers/embeds fail before network access instead of silently changing content.
- Fixed HTTPS endpoints, TLS verification, redirects disabled, bounded response
  reads and no automatic retry. Each send rechecks Live server type first.
- Open/link tracking disabled. Raw provider bodies, echoed recipients and server
  tokens are discarded. Only validated provider message UUID and fixed outcomes
  enter logs; existing opt-in recipient/subject capture policy is unchanged.
- No added dependency, schema migration or webhook endpoint. See
  [ADR-0020](adr/0020-postmark-before-delivery-evidence.md) for security and rollback.

## Verification status

### Contact Form 7 compatibility

The WordPress API-mail bridge consumes CF7's internal `X-WPCF7-Content-Type`
marker instead of forwarding it as an unsupported provider header. Plain-text
and HTML messages (including supported local attachments and Reply-To) are
covered for Postmark and SendGrid with fake HTTP regression tests. Repeated,
invalid or conflicting markers and other unsupported headers still fail closed.
The configured sender must still match the form's From address. SMTP is unchanged.

Owner QA on 2026-10-01 confirmed receipt of CF7 plain text, plain text with
attachment, and HTML with attachment. Supplied screenshots show the attachments
and rendered bold HTML, with Inbox labels on both plain-text examples. These
are manual receipt confirmations, not automated delivery tracking. No real email
was sent by the coding agent while implementing this compatibility fix.

Automated tests cover the real adapter with fake HTTP boundaries, shared provider
contracts, token encryption/context separation, permission/nonce checks, sender
configuration, safe outcomes and WordPress dispatch correlation. Live Postmark
verification and controlled recipient confirmation have now been completed with
owner-supplied configuration. Final PR review and release acceptance remain
pending. No live token was read, changed or submitted by this implementation.

Latest local regression result: 1,175 PHP tests / 3,944 assertions, including
CF7 compatibility and provider-scoped diagnostics. The earlier evidence below
describes the initial implementation snapshot, before live owner QA.

### Local evidence (2026-10-01)

- Full PHPUnit: 1,159 tests / 3,784 assertions passed with no PHPUnit warnings.
- Seven JavaScript tests, 198 PHP syntax checks, full WPCS, strict Composer
  validation and `git diff --check` passed.
- Shared contract tests now cover SMTP, SendGrid and Postmark. Postmark-specific
  cases cover non-sending Live verification, blocked sandbox/test tokens,
  malformed acknowledgement/MessageID, preflight failures, HTTP rejection,
  timeout uncertainty, no retries, private outcomes and encrypted token isolation.
- Wizard tests cover Postmark sender/config resolution, verification state,
  unreadable credentials, safe audit outcomes and inline forms. No real provider
  calls are made by the automated suite.
- Live WordPress Providers page renders Postmark as Not configured; its selection
  link reaches wizard step 2. Desktop and 320px mobile selection reviewed; mobile
  client/scroll widths both 305px, with all provider options visible. SMTP remains
  selected. No live settings submitted, tokens accessed or mail sent. Step 3 save
  and live verification/send QA remain pending to avoid changing the active route.
- No remote CI or PR has been created for this uncommitted feature branch.
