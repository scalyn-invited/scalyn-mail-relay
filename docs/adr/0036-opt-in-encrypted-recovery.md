# ADR 0036 — Opt-in encrypted queue and conservative recovery

Owner: Bernie. Implements approved Milestone 9 tickets 6–7, subject to owner acceptance.

Recovery is disabled by default and separately authorized by an administrator or
agency manager with manage-settings and critical-setting override. Enabling it
requires explicit temporary-message-storage consent, current schema, a configured
named default and a valid separately configured server encryption key.

With recovery enabled the WordPress bridge takes asynchronous durable ownership
before returning true. This is Prepared work, not provider acceptance or delivery.
Without it, existing synchronous behavior is preserved. Wizard test sends remain
explicit synchronous tests. There is no synchronous fallback after an uncertain or
failed queue ownership write and no automatic manual resend action.

Schema 0.13.0 adds a non-public InnoDB outbox. Body, recipients, headers, original
attachment names/bytes and the pinned ordered route are one authenticated encrypted
payload. It uses a distinct AES-256-GCM context, never WordPress salts or plaintext
fallback. Logical identity is authenticated inside the envelope and checked on read.
Limits: 5 MiB combined body/attachments, at most ten attachments, 8 MiB serialized
JSON, 100 retained nonempty payloads and 20 MiB aggregate encrypted content. Capacity
is serialized by a fail-closed create-only lock; crash lock removal is an explicit
operator action after confirming no writer remains. No cross-request exactly-once
guarantee or customer-data deduplication is claimed.

Attachments are captured before CF7 removes its originals. Workers restore them
only in exclusive OS temporary handles with restricted permissions and automatic
deletion on close/process exit. MailMessage gains optional attachment_names to keep
original names without creating persistent plaintext copies. ProviderInterface is
unchanged. An abrupt OS failure still requires normal OS temporary-file hygiene;
no plaintext files are placed in the plugin or public uploads directories.

A compare-and-set claim and irreversible submitting boundary precede dispatch.
Mandatory Prepared attempt evidence must exist before adapter invocation. Crashed
claims or uncertain/partial outcomes become review and can never be replayed.
Expired, archived, edited, undecryptable or unconfigured targets stop. Renames and
default selection do not rewrite pinned route identity. Accepted stops immediately.
At most three submissions use up to three ordered configurations; when there are
fewer alternatives the last pinned target is reused only after proven transient
nonacceptance. Delays are at least 60 then 300 seconds from observed completion and
honor longer normalized Retry-After hints. Delays beyond payload expiry stop work.

Adapters permit retry proof only for SMTP failures before send begins (connectivity
or timeout) and explicit API HTTP 429 rejection. API timeouts, 408, 5xx, redirects,
unexpected success shapes and partial responses remain unconfirmed. Auth/config,
certificate/TLS and permanent rejections never fail over. No raw response text is
used to infer safety. Adapters still submit at most once per invocation.

Encrypted content expires at 24 hours, cannot be decrypted or sent after expiry,
and is erased immediately on terminal outcomes. Cleanup also runs while recovery
is disabled. WP-Cron is traffic-dependent: physical deletion runs at the next
worker/cleanup execution, not a guaranteed wall-clock SLA. Sites requiring physical
deletion by the deadline must run WordPress cron from a reliable system scheduler.
Metadata uses existing retention. Deactivation stops workers, activation reconciles
cleanup, uninstall retains data unless explicit deletion was selected. After
reactivation stale claims require review. Disable recovery and stop workers before
rollback; never replay a snapshot containing a possibly submitted claim.

Admin sees bounded content-free summaries and attempt history only. Review requires
checking provider activity externally; cancellation cannot recall in-flight mail.
No payload, sender plan, address, subject, credential or response is exported.
