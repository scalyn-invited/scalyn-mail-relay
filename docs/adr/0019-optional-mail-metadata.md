# ADR-0019: Optional recipient and subject metadata

Status: Scope approved by Bernie in the UI redesign conversation; implementation subject to final review.

## Decision

Align Email Logs with the UI/UX blueprint using existing operational fields and
explicit opt-in metadata. `advanced.log_message_metadata` defaults to false.
Only the privileged, nonce-protected Settings form exposes this policy.
The existing VIEW_LOGS capability permits reading retained metadata.

Schema 0.3.0 adds nullable `logged_recipients` and `logged_subject` to the mail
aggregate through an additive, version-gated dbDelta migration, verified before
advancing the schema version. Capture only on insertion after schema readiness
and explicit consent. Never backfill old records or update metadata on subsequent
outcomes. Keep at most 20 validated To addresses without display names and 255
plain-text subject characters. CC/BCC headers, bodies, credentials, raw headers
and provider transcripts remain excluded. Subject content can still be sensitive;
administrators must assess their privacy policy before enabling capture.

Existing mail retention deletes these columns with their parent rows. Turning
capture off stops future collection, not deletion of already retained metadata.
Reports, exports, audit payloads and diagnostic evidence must not gain these
values. Auditing records only the changed setting identifier, not its contents.
No provider, message or send-result contract changes are required.

## Search and presentation

Repository-owned prepared filters operate before pagination. Dates are inclusive
site-local calendar days; provider uses exact ID; source and recipient use literal
substring matching; search accepts a subject substring or exact message UUID.
All filters are bounded and validated. Missing metadata is labelled Not recorded,
not inferred. No synthetic delivery events or raw provider responses are shown.

## Rollback and limitations

Disable capture before rolling back code. Additive columns can remain on rollback;
older code ignores them and normal parent-log retention still removes them.
Do not lower the stored schema version or delete columns automatically. Capture
does not provide a full archive: addresses and subjects are bounded, historical
rows lack metadata, and header recipients are intentionally excluded. Substring
search is bounded in returned rows but can scan retained history; monitor query
cost on large sites before adding further search capabilities. Metadata and search
terms can be personal data; restrict database backups and admin URL/access logs.
