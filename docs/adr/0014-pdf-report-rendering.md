# ADR-0014: PDF report rendering

Date: 2026-09-25. Status: Implemented for Bernie's review.

## Decision

Add Dompdf as a production Composer dependency (`^3.1`, locked to 3.1.6).
Render the same privacy-filtered snapshot used by CSV/JSON; do not query evidence
again while formatting. Keep the existing export capability, POST nonce, period
validation and attachment response. PDF is an additional format, not a new access
path or durable report store.

Use fixed escaped HTML, A4 pagination, bundled DejaVu Sans fonts, repeated trend
table headings and page numbers. Remote access, embedded PHP, PDF JavaScript and
resource URL protocols are disabled. No images, user CSS, external fonts or links
are rendered. Reject a globally loaded Dompdf class from another plugin's path
instead of silently using its possibly incompatible version. Other dependency
namespace collisions remain a packaging risk; full vendor-prefixing is not added
incidentally to this ticket.

## Packaging and operations

Release packages must include `vendor/` built from the lock file with
`composer install --no-dev --prefer-dist --optimize-autoloader`, including bundled
fonts and upstream license/notice files. Git source archives alone do not contain
the renderer. DOM and MBString PHP extensions are required by the new dependency.
The source autoloader fallback still permits non-PDF features; missing renderer
classes produce a safe export-unavailable response.

Dompdf is LGPL-2.1; retain its license and those of transitive libraries/fonts,
and review commercial distribution obligations before release. No proprietary
code is copied into the library and no vendor sources are modified.

The final PDF stays in memory and downloads directly. Dompdf may use its normal
temporary font-subset files (removed by the renderer) and reusable font metrics;
these are font artifacts, not report documents. Rendering is CPU/memory intensive;
existing period/result bounds apply, but large reports still need suitable PHP
memory and execution limits. No background queue or arbitrary resource-limit
override is introduced.

## Limits and rollback

Labels are currently English; DejaVu supports many, not all, Unicode scripts.
This is a selectable-text PDF, not certified PDF/UA or a tagged accessible PDF.
CSV/JSON remain available for structured data. Privacy defaults and explicit
retention/delivery caveats remain visible. No schema or retention changes.

Rollback reverts the PDF format/renderer and restores the previous Composer
manifest and lockfile, then reinstalls dependencies in the release build. No
stored report migration is required. Export audit events remain ticket 7.

Validation and operator guidance: [PDF reports](../PDF-REPORTS.md).
