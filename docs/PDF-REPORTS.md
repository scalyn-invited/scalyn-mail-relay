# Milestone 6 ticket 6: PDF reports

Owner: Bernie. Based on merged develop `9ae4d73` / PR #52.

In **Mail Relay → Reports**, choose **PDF**. Existing period, provider and
evidence-reference controls apply unchanged. The download contains capture
metadata, period/timezone, privacy mode, mail activity, site-wide health/trends,
diagnostic findings, recommendations, freshness and limitations. Null scores
remain unknown, zero remains zero, and Accepted is never described as delivery.

`ReportExporter` applies the same reference exclusion before selecting CSV, JSON
or PDF. `PdfReportRenderer` receives only that filtered data and does not reread
the database. Each download still captures separately. PDF bytes, layout and
creation metadata are not intended to be byte-identical across requests.

The layout uses A4 pages, bundled fonts, wrapped text, compact daily trend tables
with repeated column headings and page-number footers. Metadata labels are
English. The PDF is not tagged/PDF-UA certified; use CSV/JSON for structured data.
Font coverage is limited to the bundled DejaVu glyphs.

Security, dependency distribution and rollback details are in
[ADR-0014](adr/0014-pdf-report-rendering.md). The endpoint retains the existing
capability/nonce checks, fixed filename, attachment disposition, no-store and
nosniff headers. All source values are escaped as text; PHP, JavaScript, remote
assets and resource URL loading are disabled. No final PDF is stored on the
server. Downloaded copies remain under the operator's control.

## Validation — 2026-09-25

- Full suite: **963 tests, 2,248 assertions passed**. PDF-specific tests exercise
  real renderer output/page structure, escaping, zero/null semantics, sections,
  attachment MIME/filename and permission denial.
- WordPress Coding Standards, PHP syntax (170 files), Composer strict validation
  and diff checks passed; renderer-path hardening rechecked separately.
- PDF skill visual QA: generated synthetic populated, reference-opt-in and empty
  reports. Reviewed all rendered PNG pages: respectively 4, 5 and 2 pages, with
  readable columns/wrapping and page numbering. Compact trend table replaced the
  initial verbose per-day blocks after visual review.
- Pypdf text checks verified default reference exclusion, explicit inclusion,
  final trend date, diagnostic finding, null wording, limitations and footers.
  No PDF JavaScript or document open action was present.
- Browser QA confirmed the PDF option and an actual authenticated PDF download
  through the WordPress admin-post route using an empty future test period.
  No email, live settings or customer evidence changed.
- `composer audit --no-dev` found no production dependency advisories.
  Full audit reports an existing development-only PHP_CodeSniffer advisory:
  CVE-2026-67434 / PKSA-rdkp-vv9z-mjkg (fixed in 3.13.6). That unrelated tooling
  dependency was not updated here; do not describe the full dependency audit as
  clean. It should be addressed in a focused maintenance change.

Bernie dependency/architecture review and eventual remote CI remain outstanding.
Synthetic QA PDFs are ignored build artifacts, not shipped reports. Ticket 7
adds export auditing and applicable expiry handling.
