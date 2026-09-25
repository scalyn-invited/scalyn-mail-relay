# Milestone 6 ticket 5: CSV and JSON exports

Owner: Bernie. Baseline: merged develop `036aa43` (PR #51).

Open **Mail Relay → Reports**. Choose a site-local start and exclusive end using
`YYYY-MM-DD HH:MM:SS` (maximum 366 days), mail provider scope and format. Specific
provider uses the exact provider identifier, for example `smtp`. All providers and
unattributed mail are separate choices. Diagnostics and health remain site-wide.

Each download creates one immutable snapshot using the ticket 4 service. JSON
retains nested report sections; CSV uses `path,type,value` columns for the same
sections, including limitations, empty arrays, nulls, booleans and zero values.
CSV is a complete report interchange table, not a flat message-list export.
Separate downloads are separate captures and may differ as retained data changes.

## Privacy and security

- Both the page and download require the existing `EXPORT_REPORTS` capability.
  Merely viewing logs or managing settings does not authorize export.
- The authenticated `admin_post_scalyn_export_report` handler accepts POST only
  and verifies an export-specific WordPress nonce before reading evidence.
- Strict input validation rejects arrays, invalid dates, oversized periods,
  unsupported formats and invalid specific-provider identifiers.
- Evidence identifiers (row IDs and message/run/score UUIDs) are excluded by
  default. An explicit checkbox includes them for troubleshooting. The generated
  report UUID is retained because it identifies the capture, not a source record.
- The snapshot projection excludes recipients, subjects, bodies, credentials,
  raw diagnostic/provider responses and free-text database summaries in both modes.
  Metadata and timestamps remain operational information, not anonymized data.
- CSV quotes every cell, doubles embedded quotes, uses CRLF record separators
  and prefixes dangerous formula-leading cells with an apostrophe, including
  whitespace/BOM-prefixed operators and leading tab/CR/LF. JSON is not altered by
  CSV protection. Spreadsheet re-saving or stripping the protective prefix can
  remove that protection; do not treat an edited CSV as a safe original export.
- Responses use fixed filenames, attachment disposition, nosniff and private
  no-store headers. Encoding finishes before download headers/body are emitted;
  errors produce a safe message, not a partial or fabricated report.

No report is written to server storage. Browser-downloaded copies remain under
the operator's control and are not affected by plugin retention. Export-specific
audit events are ticket 7; [PDF is now available in ticket 6](PDF-REPORTS.md). No capability, schema, dependency,
retention or mail lifecycle change. No email is sent. Rollback removes the new
page, handler and serializer; no migration or server file cleanup is necessary.

## Validation — 2026-09-23

- PHPUnit: **960 tests, 2,225 assertions passed**, including 25 new cases for
  capability/nonce/method gates, malformed options, database failure, privacy,
  immutable input, CSV injection/quoting and explicit missing-value preservation.
- Full WordPress Coding Standards, PHP syntax (168 files) and diff checks passed.
- Isolated real WordPress/MariaDB: a synthetic retained SMTP failure matched JSON
  and parsed CSV; real nonce/capability validation passed, anonymous and invalid
  nonce requests failed, reference opt-in did not expose a private response
  sentinel, and the fixture was removed afterward.
- Browser QA: Reports menu/form, associated labels, timezone help, format controls
  and unchecked reference default verified. CSV and JSON download events confirmed
  through the actual WordPress admin-post route using an empty future test period.
- No customer data, live settings or external email changed. No server-side export
  artifacts created. Bernie review and remote CI remain separate from local checks.
