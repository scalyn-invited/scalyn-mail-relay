# ADR-0011: Monitoring freshness presentation

Date: 2026-09-22. Status: Implemented for Bernie's review.

Dashboard and Diagnostics include a shared, read-only MonitoringStatusPresenter
and escaped panel after their existing capability checks. No new endpoint,
capability, schema, dependency or scheduled action is introduced.

Schedule registration, execution result and freshness are independent. Overdue
means next event is more than five minutes past due. Stale scheduled status means
no confirmed scheduled completion within the current cadence plus five minutes;
missing completion is unknown, not fresh. Manual success cannot hide scheduled
failure. Future completion times are unknown. Completion-unconfirmed never
asserts failure or current activity.

Retained evidence uses its own site-time timestamp interpreted with wp_timezone,
not a status pointer. Evidence is stale after cadence plus five minutes, or a
daily reference plus five minutes when monitoring is disabled. Invalid/future
dates are unknown. Diagnostic results and latest health snapshots may belong to
different runs, which the view states explicitly.

Labels and remediation are fixed translated strings. UTC execution times are
labelled as UTC; evidence dates as site time. Settings links require management
permission. The panel does not execute, repair or reschedule work during reads.
WordPress init reconciliation remains existing behavior.

Operational WP-Cron guidance is in MONITORING-OPERATIONS.md. Low-traffic verification
uses an overdue fixture and the real WordPress cron entry point, not a wait for
real time to pass. Server scheduler installation remains the site owner's action.
Rollback removes presenter/view wiring without modifying operational data.
