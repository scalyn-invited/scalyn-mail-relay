# ADR-0017: SendGrid credential protection

Date: 2026-09-28. Owner: Bernie.
Status: Encrypted database storage with a separate server key approved by Bernie;
implementation ready for review after validation. Ticket 4 later enabled the
transport workflow; encryption policy remains unchanged.

## Decision

Use OpenSSL AES-256-GCM authenticated encryption with a fresh random 12-byte nonce,
16-byte authentication tag and a 32-byte server key. The versioned envelope is
`v1:` followed by base64(nonce + tag + ciphertext). Authenticated additional data
binds the value to `scalyn:sendgrid:api-key:v1`. PHP OpenSSL is required; unavailable
crypto or an invalid key fails closed. There is no plaintext or WordPress-salt
fallback. PHP Sodium is not available in the current local environment.

`SCALYN_MAIL_RELAY_ENCRYPTION_KEY` must contain the base64 encoding of 32 random
bytes in protected server configuration. The plugin does not generate, save,
display or rotate this server key. Back it up separately from the database and
preserve it during migrations. This protects against a database-only disclosure,
not a compromised WordPress/PHP process or a backup containing both keys and data.

## Storage and boundaries

`SettingsRepository` owns the additive `sendgrid` group in the existing settings
option: `from_email`, `from_name`, and `key_cipher`. Public readers receive only
sender fields and credential presence. Ticket 4 added a transport-only reader
for the registered adapter. It decrypts immediately before provider use and
fails safely on missing keys or failed authentication.

The form on Providers requires MANAGE_MAIL and a valid POST nonce. New secrets
are never echoed, even after a validation error. Keep requires a readable existing
credential; replace requires a new valid credential; remove requires explicit
confirmation and works without the server key. Empty replacement is rejected.
Input shape is validated, not treated as proof that an API key is authentic.

Save does not activate SendGrid, contact the API, modify SMTP or mark verification
successful. If a SendGrid configuration is already active, a change clears its
verification and accepted-test timestamps. Working SMTP verification is preserved.
Provider switching and end-to-end verification were added in ticket 4.

AuditEvent's field allowlist adds `sendgrid.api_key`, `sendgrid.from_email`, and
`sendgrid.from_name`; only changed field names are recorded, never values or
ciphertext. Audit observer failure does not undo an already committed save.

## Lifecycle, rotation and rollback

No schema migration, new table, scheduler, capability or Composer dependency is
needed. The existing settings retention/uninstall policy applies: default retain
keeps encrypted credentials; explicit destructive uninstall deletes the settings
option. Neither mode edits server configuration or revokes a provider API key.

To rotate the server key, first retain access to provider credentials, replace the
server key and re-enter each protected API key. Existing ciphertext will become
unreadable; there is no automatic re-encryption or fallback. To revoke a SendGrid
key, use SendGrid itself; local removal is not remote revocation. Database backups
can retain old ciphertext and need their own retention controls.

Code rollback leaves the additive settings group intact and removes the form.
Before returning to older code, remove saved credentials if retention is unwanted.
Do not delete shared SMTP settings. Older audit readers may show these new field
names as unknown; they never receive credential values.
