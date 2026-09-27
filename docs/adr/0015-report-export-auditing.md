# ADR-0015: Report export auditing and expiry

Status: Implemented for Bernie's review. Date: 2026-09-27.

## Decision

Milestone 6 ticket 7 extends the existing audit contract with `report_export`.
After capability, POST, nonce and option validation, the controller emits
`started` with a fresh operation UUID. Successful snapshot capture and encoding
emit `prepared` with the same operation UUID. Capture/encoding failures emit
`failed`; output already started also emits `failed` after preparation.
Prepared proves generation only. It cannot establish that a browser received,
saved or opened a file. A started event without a result may reflect interruption
or a failed audit write. Repeated downloads are separate attempts and captures.

Version 3 metadata is restricted to report exports and adds exactly `format`
(csv/json/pdf), `references` (boolean) and `report_uuid` (empty before capture,
otherwise a UUID; required for prepared). The report UUID matches the capture
reference in the report. The operation UUID groups audit events. Actor identity
and execution context use the existing trusted runtime capture. No submitted
actor, filter, provider, date range, response, body, filename or arbitrary metadata
is persisted. Other actions retain version 2. The repository validates version 3
on read; malformed rows become unknown. Older version 1/2 history still renders.

The existing audit subscriber and repository persist events. Observer and audit
database failures are isolated from export success/failure, consistent with
ADR-0006/0007. This remains a best-effort operational trail, not a guaranteed
security ledger. Unauthorized or malformed requests do not become export events.
The normalized internal controller response also carries audit correlation and
export details for output-failure recording; only its body is downloaded.

## Retention and files

Reports are generated in memory and streamed as private, no-store attachments.
No final report is saved, so there is no generated report directory or expiry
job. Downloaded copies belong to the operator and cannot be expired by WordPress.
Renderer libraries and bundled fonts are dependencies, not generated reports.
If persistent reports are introduced later, file access, expiry and deletion
must be designed explicitly before enabling storage.

Export audit rows use the existing Settings retention policy, transactional
bounded batches and lifecycle hooks. Rows expire individually, so part of an
attempt can expire before another part. No schema, new cron hook, capability,
REST endpoint or dependency is introduced.

## Rollback

Revert the controller, contract, read model and view changes together. No schema
rollback or generated report cleanup is needed. Older readers display version 3
events as unknown; existing audit retention can still expire them.
