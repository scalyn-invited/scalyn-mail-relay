# Alerts and recovery

Milestone 5 evaluates three site-wide incidents on a five-minute WordPress cron
tick. It does not send email or depend on the configured SMTP provider.

| Incident | Opens | Recovers |
| --- | --- | --- |
| Repeated send failures | Latest three terminal sends all Failed within 15 minutes | Latest terminal send Accepted within 15 minutes |
| Monitoring failure | Enabled schedule missing/mismatched/overdue by more than 15 minutes; latest scheduled run Failed; or still Running after 15 minutes | Current schedule plus a Completed scheduled run no older than cadence + 15 minutes |
| Health degradation | Snapshot no older than 24 hours scores below 60 | Fresh snapshot scores at least 75 |

Missing, stale or intermediate evidence holds the current incident state. Turning
off scheduled diagnostics does not resolve an existing monitoring incident.
Accepted means provider acknowledgement, not inbox delivery. The rules are fixed
initial policies, not per-provider configuration or a new scoring algorithm.
Events that arise and recover between evaluation ticks may not be observed.

## Configure an independent receiver

1. Provision a public HTTPS webhook receiver separately. Implement deduplication
   using `notification_uuid` / the matching `Idempotency-Key` header.
2. Define `SCALYN_MAIL_RELAY_ALERT_WEBHOOK_URL` in protected server configuration
   (for example `wp-config.php` outside source control). Optionally define
   `SCALYN_MAIL_RELAY_ALERT_WEBHOOK_TOKEN` for bearer authentication.
3. Open **Mail Relay → Alerts & Recovery** with the manage-settings capability.
   Enable webhook notifications and save. The form requires a valid nonce.
4. Verify a controlled incident and recovery with your receiver before production
   reliance. Configuration presence is not a connectivity test.

No URL or token is displayed or stored in plugin options, audit metadata, or
notification records. Do not place real credentials in checked-in configuration.
Only public HTTPS destinations are supported; userinfo, fragments, redirects,
and unsafe destinations are rejected. Requests use WordPress safe HTTP, verify
TLS, time out after five seconds and read at most 1,024 response bytes. Response
bodies and transport errors are never persisted.

The JSON contract contains `version: 1`, `notification_uuid`, `incident_uuid`,
`type` (`send_failures`, `monitoring`, `health`), `event` (`opened`, `recovered`),
and a fixed, event-specific `message`. It deliberately omits site URLs, recipient
addresses, email content and raw diagnostic evidence. Route separate site
endpoints/tokens at the receiver if centralized identification is needed.

## Notification behavior

- Repeated observations update neither the incident nor its notification queue.
- A reopening creates a new incident; its opening notification waits out a
  15-minute cooldown from the prior opening's creation or latest attempt.
- Recovery resolves the incident, cancels its pending/interrupted opening job,
  and creates a separate recovery notification. Recovery can arrive without an
  acknowledged opening notification.
- Disabled transitions are recorded as `skipped`, never replayed when enabled.
  Already queued notifications encountered while disabled are also skipped.
- Each tick processes at most five jobs. Attempts are recorded before HTTP.
  Failures retry at most three total attempts with minimum delays of one and
  five minutes; actual retries wait for an eligible cron tick.
- Interrupted jobs consume their recorded attempt. Uncertain acknowledgements
  can cause a duplicate request; receiver-side deduplication is required.
- HTTP 2xx is `sent` (receiver acknowledgement), not proof that a person was
  notified. Exhaustion is `failed`; status/count/last HTTP code remain visible.

## History, audit and data lifecycle

The protected history view uses keyset pagination, at most 50 incidents per page,
and only fixed metadata. Last evaluation status and time distinguish an empty
history from an unconfirmed or overdue worker. Audit records cover opening,
resolution, attempted notification outcomes and notification preference changes.
Audit observers are best-effort and do not cause committed operations to repeat.

Schema **0.2.0** adds `scalyn_alert_notifications` through idempotent `dbDelta()`.
An authenticated settings administrator visit or activation performs the upgrade;
older schemas fail closed for alert execution. The alert and outbox tables must
be InnoDB. The connection-owned site/database lock serializes evaluations and
attempts; incident and outbox changes commit together.

Resolved incidents and their jobs expire together after the retention period
configured in Settings, measured from resolution in UTC, at most 100 incidents
per tick. Active incidents remain. Deactivation clears the owned tick and retains
data. Uninstall retains data by default; explicit deletion removes both tables
and the bounded alert-worker status option. Server constants are never removed.

Rollback: deactivate this version before restoring the prior plugin. Preserve the
additive table and a database backup; do not lower the schema version or delete
history as a code rollback. Re-enable this version to resume its preserved queue.

## Operational limits

WP-Cron still needs traffic or a real server scheduler. A stopped site/PHP/cron
cannot send an outage notification about itself. Use external uptime monitoring.
This milestone does not provision a receiver, install an OS scheduler, confirm
inbox delivery, or perform automated remediation. Bernie owns deployment,
architecture approval and production receiver validation.
