# ADR-0012: Incidents and webhook outbox

Date: 2026-09-22. Status: Implemented; pending Bernie's architecture review.

Milestone 5 adds three site-wide incident types. Three most recent terminal mail
records, all failed within 15 minutes, open send_failures; a later Accepted result
recovers it (acceptance is not delivery). Scheduled failed execution, an unresolved
start older than 15 minutes, or a schedule overdue by 15 minutes opens monitoring;
only a fresh completed scheduled run with a present schedule recovers it. Disabled
monitoring is unknown, not recovered. Missing or mismatched enabled schedules also
open monitoring. Completed runs remain fresh for the cadence plus 15 minutes.
Health snapshots no older than 24 hours and below 60 open health;
75 or above recovers; the middle band and unavailable/stale data hold current state.

A five-minute cron tick evaluates these rules and processes at most five webhook
jobs under a site/database connection-owned lock. Existing scalyn_alerts stores
incident history. A versioned 0.2.0 dbDelta migration adds scalyn_alert_notifications
for a transactional outbox, unique per alert/event. Both tables require InnoDB.
No migration is inferred from the plugin version; older schema disables alert work
until successful activation or capability-protected Admin upgrade.

Only transitions create notifications; repeated observations do not remind.
Opening notifications for the same type have a 15-minute cooldown from the prior
opening's creation or last attempt, whichever is later (not its future due time);
this avoids accumulating future delay during flapping. Recoveries
cancel unsent opening jobs and create a separate recovery event. Disabled channel
events are recorded as skipped, with no retroactive delivery on enabling.
At most three attempts, with minimum 1/5 minute retry delays (processed on a later
five-minute cron tick), fixed HTTP status codes,
stable idempotency key, no response-body persistence. Ambiguous delivery can repeat;
receivers must deduplicate by notification UUID. Acknowledgement is not operator action.

Webhook URL and optional bearer token are server constants, never rendered,
stored in plugin options or included in audit data. Admin enables delivery with
capability and nonce checks. HTTPS only, no userinfo/fragment, no redirects, safe
WordPress HTTP validation, 5-second timeout and bounded response. Payloads contain
only a contract version, incident/notification IDs, fixed type/event and an
event-specific message;
no site URL, mail address, body, provider response or diagnostics evidence.

Resolved incidents and notification jobs expire together under the configured
history period; active incidents remain until observed recovery. Cleanup is bounded
to 100 resolved incidents per tick. Default uninstall retains both tables;
explicit deletion removes them. Audit captures opening/recovery/notification
outcomes and enablement changes with UUIDs/fixed labels only.

The non-autoloaded `scalyn_mail_relay_alert_status` option records only last tick
state and epoch time. Admin distinguishes never-run, failed, unconfirmed and
overdue evaluation from a successful run. Incident/outbox timestamps use UTC;
the observation adapter converts existing site-time mail/score timestamps and
uses diagnostic execution's existing epoch timestamps without reinterpretation.

Bernie reviews schema, policy, service and lifecycle changes. No OS scheduler or
external receiver is provisioned. A failed site/PHP cannot alert about itself;
external uptime monitoring is still required.
