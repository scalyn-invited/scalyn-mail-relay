# ADR 0042 — Install-and-use SMTP and automatic local credential protection

Decision owner: Jay. Direction authorized by Jay in this implementation conversation;
implementation/security verification pending. This supersedes the SMTP default
pass-through in ADR 0034 and the fresh-save plaintext legacy path, not routing,
recovery consent, evidence attribution or external-key cryptography.

## Default SMTP

Selecting Custom SMTP makes ordinary `wp_mail()` synchronous sends use that
transport without routing or recovery. Earlier `pre_wp_mail` short-circuits are
preserved. Unsupported shapes and selected-transport failures return false; no
silent native PHP-mail fallback occurs. Routing and asynchronous recovery remain
separate explicit opt-ins. Acceptance is never presented as delivery.

## Threat boundary and key sources

New SMTP username/password fields are encrypted even before named configuration
import. Existing plaintext remains readable without an implicit write on GET;
the next authorized SMTP save/import protects it. Public settings/forms omit
credentials and ciphertext. Unchanged credentials preserve their envelope.

Existing explicit `SCALYN_MAIL_RELAY_ENCRYPTION_KEY` and injected test keys retain
v1 envelopes and existing authenticated contexts byte for byte. A present invalid
external key never falls back for new writes. A local v2 envelope always resolves
the local key, even when an external key is later configured; v1 never tries a
local key. v2 authenticates its source/version and current blog identity.

A small local key store uses the canonical trusted WordPress content directory,
`scalyn-mail-relay-private/credentials-key-blog-<positive blog id>.php`. Each
operation resolves the current blog, including a reused service after switching
blogs. An optional trusted `SCALYN_MAIL_RELAY_KEY_DIRECTORY` may identify a
non-public directory; no manual hosting setup is required for the normal case.
The key is never a database value, never derived from salts/public information,
and never returned in a public status projection. WordPress files and database
must be backed up together. Changing blog IDs requires deliberate key migration.

Automatic file storage protects against database-only disclosure, not malicious
PHP/plugins, a hosting administrator, same-account filesystem compromise or full
backup theft. In the default content-directory location, confidentiality also
depends on correct PHP source handling. The key file is an unconditional-exit PHP
wrapper with no closing tag or output; code reads a bounded exact format, never
requires/evaluates it. This is not a claim that the directory is outside the web
root. Unsupported/insecure filesystem semantics fail closed; an externally
managed key remains the advanced alternative.

## Initialization and failure behavior

`is_available()`, construction, decrypt, ordinary/background transport and GET
views only load existing key material. A separate side-effect-free setup status
may report whether initialization is possible. A separate initialization method
requires manage-settings/native administrator authority when creation or marker
persistence is needed, and is called only after the relevant write's validation,
nonce, capability and managed-access checks. Generic encrypt never creates a key.

Private directory mode is 0700 and key mode 0600, with regular-file, owner and
symlink checks below the canonical trusted root. Publication uses a unique 0600
PHP-wrapper temporary file and no-overwrite hard-link publication; it does not
rename over a possible winner. A creation lock serializes initializers. A failed
or interrupted publication is unavailable rather than a reason to replace key
material. Losing initializers read the fully published winner or fail safely.

A nonsecret initialized/fingerprint marker is persisted before the first v2 data
write. A missing/corrupt key with a marker cannot initialize again. If the marker
is absent, initialization also checks the complete settings/catalogue, fixed
webhook/AI options and recovery, delivery-key and AI-session ciphertext stores.
Read errors/schema uncertainty block initialization, not prove emptiness. A valid
retained key file may be consumed without writing a marker on GET; an authorized
write establishes the marker before new local ciphertext. Existing insecure,
partial or symlink objects are never silently repaired or overwritten.

## Lifecycle and recovery limits

The key is outside the plugin directory and survives plugin replacement and
retain-data uninstall. It is deliberately retained even on delete-data uninstall
as a separately managed encryption/backup artifact; operational tables/settings
and the nonsecret initialized marker may be removed, but the key is not destroyed
under an uninstall handler whose database drops are not transactionally verified.
Only an operator who has verified no live data or required backup depends on it
should explicitly remove the current site's key. Other sites' files and external
keys are never touched. UI/uninstall documentation must state this exception.

No transparent key rotation, destructive reset, missing-key regeneration, salt
fallback, live credential migration, staging configuration change or real provider
request is authorized by this implementation. Legacy data is protected only on
an authorized save/import, not by rewriting a live site's credentials on boot.
