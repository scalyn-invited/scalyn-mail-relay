# Uninstall Policy

## Default: retain data

Deactivation and uninstall stop all plugin-owned scheduled work. Unless deletion
was explicitly enabled, settings, credentials, custom tables, mail history,
diagnostics, health snapshots, cleanup/execution status and capabilities remain available
after reinstalling. Uninstall does not run a final retention cleanup.

## Explicit deletion

Open Mail Relay → Settings with the manage-settings capability. Select
“Delete all plugin data when the plugin is uninstalled”, separately confirm the
permanent-deletion consequence, and save. The form requires a valid WordPress
nonce. Enabling the policy does not delete data immediately; uninstall triggers
it later. Clearing the policy and saving disables deletion.

Only boolean `true` in `advanced.delete_data_on_uninstall` enables deletion.
Missing values, false, numbers and strings (including `'true'` and `'1'`) retain
data. Existing boolean opt-ins remain valid. Programmatic saves through
`SettingsRepository` require `confirm_delete_data => true` when enabling the
policy; confirmation itself is not persisted. Avoid printing the full settings
option because it contains transport credentials.

When enabled, uninstall removes only this site's owned data:

- Tables: `scalyn_mail_logs`, `scalyn_mail_timeline`, `scalyn_diagnostics`,
  `scalyn_health_scores`, `scalyn_alerts`, `scalyn_alert_notifications`, `scalyn_audit_logs`.
- Options: `scalyn_mail_relay_settings`, `scalyn_mail_relay_db_version`,
  `scalyn_mail_relay_version`, `scalyn_mail_relay_retention_status`,
  `scalyn_mail_relay_diagnostic_run_status`, `scalyn_mail_relay_alert_status`.
- Plugin capabilities from all roles, current/historical owned cron events,
  `scalyn_mail_relay_health_cache` and `scalyn_mail_relay_diagnostics_cache`.

Unrelated plugin data and capabilities remain. Permanent deletion is recoverable
only from a backup. Deactivation never deletes operational data, even when the
uninstall policy is enabled.

## Retention versus uninstall

Hourly retention removes expired operational history while the plugin is active;
uninstall policy controls all owned data when the plugin is removed. Retention
defaults to 30 days and accepts 1–3650 whole days in Settings. Reducing the
period makes existing older history eligible for the next tick. Increasing it
cannot recover removed data. WP-Cron requires traffic or a server scheduler.

Diagnostic execution status is bounded control metadata, not history: its five
slots are replaced by later runs and are not expired by the history cutoff.
Pointers can outlive their diagnostic evidence; they do not retain that evidence.

The five-minute alert tick removes up to 100 resolved incidents and their complete
notification records together, measured from UTC resolution time using the same
retention period. Active incidents remain until observed recovery. The bounded
last alert-execution status is control metadata, not expiring history. Webhook
constants in server configuration are not plugin-owned and are never removed by
uninstall; remove those manually when retiring the integration.

## Scope and verification

Single-site only. Multisite lifecycle is not supported. The uninstall script
operates on the current site's table prefix and does not sweep a network.

Automated `UninstallTest` covers retained and explicitly destructive modes,
strict flag handling, owned option removal and unrelated-state preservation.
See [Milestone 2 evidence](qa/2026-09-21-milestone-2-completion.md),
[ADR-0005](adr/0005-retention-execution-and-controls.md) and the
[release checklist](RELEASE-CHECKLIST.md).
