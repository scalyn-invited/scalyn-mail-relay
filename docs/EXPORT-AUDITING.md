# Milestone 6 ticket 7: export auditing and expiry

Owner: Bernie. Baseline: merged develop `deb9c66` (PR #54).

CSV, JSON and PDF export attempts now appear in **Mail Relay → Audit History**.
Each valid authorized attempt records its WordPress actor, execution context,
time, format and evidence-identifier choice. The correlation UUID groups the
attempt's events. A report reference links generated output to its capture.

- **Started:** the request passed authorization and input validation.
- **Prepared:** capture and encoding finished; browser receipt is not confirmed.
- **Failed:** capture/encoding failed or the response could not be started because
  output was already sent. An output failure can follow a prepared event.

Audit writes are best effort and cannot change an export result. Missing events
do not prove an operation did not occur. Invalid or unauthorized requests are
rejected before creating an export event. Report content, filters, recipients,
subjects, credentials and internal errors are excluded from the audit metadata.

Export audit rows follow the retention period in Settings. The plugin streams
reports without saving final files, so there are no generated report files to
expire. Downloaded copies remain under the operator's control. The architectural
contract and rollback are documented in [ADR-0015](adr/0015-report-export-auditing.md).

## Verification

Full PHPUnit suite: **980 tests, 2,331 assertions passed**. WordPress Coding
Standards, PHP syntax across 171 files and `git diff --check` passed. These are
local results; remote CI will run when the ticket is submitted as a pull request.

Focused tests cover all three formats, actor attribution, correlation, capture
failure, authorization and validation rejection, audit storage/observer failures,
metadata allowlists, safe history normalization and rendering.

Isolated real WordPress/MariaDB verification generated CSV, JSON and PDF in memory,
confirmed paired persisted events, report UUID linkage and rendered history details.
Existing audit retention removed three expired synthetic events and retained all
three events exactly at the cutoff. All remaining synthetic events were removed.
No live customer records, settings or mail were changed.

Milestone 6's earlier [reporting queries](REPORTING-QUERIES.md),
[snapshot consistency checks](REPORT-SNAPSHOTS.md), [export permission/privacy
checks](REPORT-EXPORTS.md) and [PDF validation](PDF-REPORTS.md) remain applicable.
Bernie's review and release browser QA remain outstanding; automated checks do
not establish browser download receipt or email delivery.
