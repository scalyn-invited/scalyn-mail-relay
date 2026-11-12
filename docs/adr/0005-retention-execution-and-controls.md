# ADR-0005: Retention execution and data controls

Status: Implemented for Bernie's review. Date: 2026-09-21.

Milestone 2 tickets 5–8 complete the repository contracts in ADR-0003 and
ADR-0004. No database migration, dependency, REST contract or new capability is
introduced. Bernie owns implementation and final architecture/release review.

## Execution

`RetentionService` listens on `scalyn_mail_relay_cleanup_logs` in every request
context. Activation and `init` ensure one hourly event; a legacy daily cleanup
event is replaced. Scheduling does not itself delete anything. The first new
event is due after one hour. A failed scheduling write is visible as “Not
scheduled” on Data Controls and is retried by the next request.

Each tick computes one exclusive site-time cutoff using validated retention days
and WordPress `current_datetime()`. It runs one mail batch and one diagnostic/
health batch, each limited to 100 aggregates of its respective kind. A full
batch means more work may remain; later hourly ticks resume oldest-first.
Related row counts can exceed 100 because a complete timeline or diagnostic run
is indivisible. Bounded selection does not guarantee constant query execution
time on very large histories; monitor database performance before scaling.

`RetentionStateRepository` acquires a nonblocking MySQL/MariaDB `GET_LOCK`, scoped
to database and site prefix, on the same connection as cleanup. This prevents
simultaneous workers from this service. Busy or unavailable lock responses fail
closed without altering another worker's status. The lock is released in
`finally` and by the database if the connection dies. There is no expiring lease
that lets a slow live worker overlap its successor. Persistent connections,
database proxies/reconnection and distributed database topologies require
separate compatibility verification before support is claimed.

Mail and diagnostic transactions remain independent. If diagnostics fail after
mail commits, status records the committed mail counts and a fixed failure state;
the next run retries remaining rows. Selection errors are failures, not empty
successes. A killed process can leave a “running” status; the UI describes it as
started without recorded completion and later ticks can resume. Counts are
operational best-effort status, not an audit ledger: a process dying between
commit and status persistence can leave incomplete counts.

## Policy, UI and lifecycle

The existing `advanced.log_retention_days` applies to mail/timelines,
diagnostic runs and independent health snapshots. Whole days 1–3650 are allowed,
default 30; invalid submissions change nothing and invalid stored values use 30.
Zero does not mean unlimited. Partial advanced saves preserve omitted fields.
Future alert/audit records and pre-existing orphan timelines are outside this
policy. Site-time history cannot reconstruct an earlier timezone after an
administrator changes it; the UI explains that expiry can be affected.

Data Controls requires `MANAGE_SETTINGS` on rendering and saving and a WordPress
nonce on POST. Enabling uninstall deletion requires a separate explicit
confirmation; it is never preselected. The stored gate accepts only boolean
`true`. Existing boolean opt-ins remain valid; truthy strings no longer permit
deletion. Credentials are never rendered. The page exposes fixed status labels,
counts, next run, last success, overdue/no-schedule advice, and deletion effects.

`ScheduledHooks` is the single source for current and historical hooks.
Activation clears stale events then schedules retention. Deactivation and both
uninstall modes stop scheduled work. Retained uninstall preserves settings,
history and status; destructive uninstall also removes the status option.
No durable lock option needs deletion. Multisite remains unsupported.

WP-Cron requires traffic or an external scheduler. One tick does not promise to
drain a large backlog. The page and operator documentation explain this.

## Rollback

Deactivate to stop future ticks; data already expired cannot be restored without
a backup. Reverting to the previous source disables its retention listener; clear
the owned hook if doing a direct source rollback without deactivation. No schema
rollback is needed. The new status option is harmless to older code. Review
retention settings and backups before deployment to managed client sites.
