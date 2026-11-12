# Scheduled monitoring operations

In Mail Relay → Data Controls, choose hourly, twice daily or daily diagnostics.
Monitoring is disabled by default. Saving a changed cadence starts a fresh
interval. Disable it to stop future scheduled runs; a currently running operation
may finish. No test email is sent by scheduled diagnostics.

## Reliable triggering

WordPress normally starts cron from site traffic. A quiet site can leave an event
overdue indefinitely. For managed sites, arrange a server task to invoke the
installation's wp-cron.php every five minutes using its appropriate PHP binary
and filesystem path, or invoke due events using an installed WP-CLI. Configure
the task as the site service user, capture failures and prevent duplicate scheduler
jobs. Do not copy paths from this development installation into production.

Only disable traffic-triggered WP-Cron after verifying the server task works.
DISABLE_WP_CRON does not itself create a replacement scheduler and does not stop
a direct invocation of wp-cron.php. Existing WordPress cron locks and other
plugins' callbacks can delay processing. Avoid overlapping manual scheduler
launches; a missing PHP binary, wrong path, disabled task, loopback failure or
database outage should be investigated before retrying repeatedly.

On Windows use Task Scheduler; on Linux use the host's cron/service scheduler.
No operating-system scheduler is installed or changed by this plugin.

## What the UI means

Dashboard and Diagnostics distinguish schedule registration, latest execution
outcome and freshness. An event more than five minutes past due is overdue.
Scheduled completion older than cadence plus five minutes is stale. Manual runs
do not refresh scheduled freshness. Missing/mismatched events and future timestamps
are not passes. Configuration changes use the current cadence for age comparisons.

Displayed retained diagnostic results and health snapshots have their own
timestamps and freshness labels. When monitoring is disabled the evidence-age
reference is 24 hours plus five minutes. Evidence can expire independently of
status pointers, and a retained health snapshot can belong to an older run.

Completion means execution/publication completed, not that every check passed.
A start without a finish is completion-unconfirmed: the operation may be running,
interrupted or unable to save terminal status. Do not assume it failed. Fixed
failure stages suggest configuration, execution-limit or database checks without
revealing sensitive exception details.

## Verification and recovery

After configuring server cron, allow a scheduled interval and confirm that the
scheduled start/completion advances and the next event is in the future.
Use Audit History for UUID-correlated outcomes and Diagnostics for findings.
Test in staging first, including low-traffic operation, failure and recovery.

One tick attempts one run; missed intervals do not cause catch-up loops. Shared
manual/scheduled locks prevent overlap. A run permits at most 20 checks and a
20-second cooperative check budget; an active blocking call cannot be forcibly
interrupted. Database publication is atomic on InnoDB, which is required.

Re-save cadence to repair missing scheduling, then verify the next event. Do not
blindly remove database or WordPress cron locks while another process is active.
Monitor the server task itself: this WordPress plugin cannot alert if PHP or the
whole site is unavailable. Independent alerting is Milestone 5.
