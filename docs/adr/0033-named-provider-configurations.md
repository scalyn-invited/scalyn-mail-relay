# ADR 0033: Named configurations and immutable sending revisions

Owner: Bernie. Milestone 9, ticket 3.
Status: implements the approved ticket scope; architecture/owner acceptance pending.

## Identity and compatibility

A configuration has a stable UUID, plain display name, built-in provider ID,
archive flag and current revision UUID. Existing evidence columns named
`configuration_id` continue to mean **revision UUID**, never stable identity.
Revision snapshots contain the provider's protected settings and DKIM selector.
Sending/credential/selector edits append a revision and reset wizard progress;
renaming and selecting an unchanged configuration do not rotate it. Evidence
continues to be filtered by provider and exact revision, with existing freshness
and Unknown/stale rules. Reselecting an existing configuration may restore its
own retained evidence; a newly created configuration has none.

`SettingsRepository` remains the sole settings persistence boundary.
`NamedConfigurations` transforms its private catalogue without database access.
The catalogue uses format version 1 inside the existing settings option, which
is saved with autoload disabled. Active compatibility slots, identity selection,
revision and progress are written in the same option update: no cross-table
partial activation. No SQL schema or evidence-column migration is necessary;
the pending Milestone 8 schema 0.10/0.11 ordering is unchanged. ProviderInterface
and transport contracts are unchanged.

## Import and encryption

Import is an explicit capability/nonce-protected action, not a write on GET or
plugin boot. It is idempotent and imports existing configured built-in slots,
preserving the active provider's existing revision and verification display.
Inactive legacy slots receive new, unattributed revisions; old logs are never
backfilled. Creating a configuration imports legacy slots first, but does not
select the new configuration. Empty/unconfigured defaults cannot send.

API envelopes retain their provider-bound encryption. SMTP login fields use a
separate authenticated `smtp` context; no newly stored username/password is
plaintext. Private transport reads decrypt only in memory. Public SMTP reads
omit both login fields and ciphertext; forms never render saved usernames or
passwords. Blank login fields keep saved values; complete replacements can
recover after a key rotation. New configurations require server encryption.

Without a key, import preserves the existing legacy SMTP slot and records only
a marked legacy reference, never another plaintext copy. Legacy sending and
selector-only edits remain available. Credential edits require encryption.
Marked legacy revisions are historical attribution only, **not replayable
transport snapshots**; future routing/recovery must not submit from them.

## Writes, permissions and audit

Catalogue operations require MANAGE_SETTINGS and the managed provider unlock;
SMTP credential edits additionally respect the SMTP lock. Administrators retain
the existing override. Controllers validate nonces before reading action data.
Credential, verification, test-send and webhook forms carry an opaque revision
token and reject stale submissions after selection/edit in another tab.

All settings writes acquire an atomic create-only option lock (including legacy
requests racing the import) and
reject stale loaded state before updating. Locks are released in `finally`.
The lock is deliberately not stolen on a timer: after an interrupted writer,
an operator must confirm no settings request is still running before deleting
only `scalyn_mail_relay_settings_write_lock`. A pending progress write after a
slow verification/send cannot overwrite another request's configuration.

Audit adds the fixed `configuration` action with import/create/select/rename/
archive outcomes. Existing identities may be correlation UUIDs; labels,
addresses, credentials and snapshots never enter audit metadata. Credential
edits continue to audit allowlisted changed field names only.

## Webhooks and retention

Existing source bindings remain tied to their exact revision. Selection/edit
does not rebind sources or copy verification; tracking new submissions pauses
on mismatch. Authenticated late callbacks still resolve retained attempts by
their original source/provider/message/recipient membership. They cannot attach
to a new default just because it is selected now.

This ticket retains the existing single source per webhook-capable provider.
Configuring another source uses the existing explicit disable/remove/recreate
workflow; this does not promise simultaneous collection for multiple saved
configurations. Removing a source stops its future callbacks. Historical
delivery events retain their original attempt/revision attribution.

Archive is the only removal operation, and the active default cannot be archived.
Archived identities/revisions remain retained; log/evidence cleanup still uses
the existing retention policy. Catalogue storage is bounded at 50 identities
and 1,000 total revisions and rejects excess writes rather than pruning silently.
Encrypted historical credential snapshots are retained with the catalogue;
provider-side revocation remains the owner's responsibility. No bodies,
attachments or recipient data are introduced. Explicit uninstall deletion
removes the existing settings option (including catalogue) and write lock;
default uninstall retains configuration data.

## Delivery and rollback

This branch depends on local tickets 1–2 (`06cee73`, `5d35fb6`) and preserves
uncommitted Milestone 8 work. No new providers, routing, retry queue or failover.
Acceptance is never relabeled as delivery. No staging deployment in this ticket.

Rollback after import requires the matching database/settings backup: old code
does not understand protected SMTP login slots. Stop writes, restore the plugin
and corresponding settings backup together, and verify the default before
resuming mail. Never downgrade only the code and assume SMTP still works.
