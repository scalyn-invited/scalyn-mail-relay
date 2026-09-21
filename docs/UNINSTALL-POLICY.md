# Uninstall Policy

## Default: retain data

Deactivation and uninstall stop all plugin-owned scheduled work. Unless deletion
was explicitly enabled, settings, credentials, custom tables, mail history,
diagnostics, health snapshots, cleanup status and capabilities remain available
after reinstalling. Uninstall does not run a final retention cleanup.

## Explicit deletion

Open Mail Relay → Data Controls with the manage-settings capability. Select
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
  `scalyn_health_scores`, `scalyn_alerts`, `scalyn_audit_logs`.
- Options: `scalyn_mail_relay_settings`, `scalyn_mail_relay_db_version`,
  `scalyn_mail_relay_version`, `scalyn_mail_relay_retention_status`.
- Plugin capabilities from all roles, current/historical owned cron events,
  `scalyn_mail_relay_health_cache` and `scalyn_mail_relay_diagnostics_cache`.

Unrelated plugin data and capabilities remain. Permanent deletion is recoverable
only from a backup. Deactivation never deletes operational data, even when the
uninstall policy is enabled.

## Retention versus uninstall

Hourly retention removes expired operational history while the plugin is active;
uninstall policy controls all owned data when the plugin is removed. Retention
defaults to 30 days and accepts 1–3650 whole days in Data Controls. Reducing the
period makes existing older history eligible for the next tick. Increasing it
cannot recover removed data. WP-Cron requires traffic or a server scheduler.

## Scope and verification

Single-site only. Multisite lifecycle is not supported. The uninstall script
operates on the current site's table prefix and does not sweep a network.

Automated `UninstallTest` covers retained and explicitly destructive modes,
strict flag handling, owned option removal and unrelated-state preservation.
See [Milestone 2 evidence](qa/2026-09-21-milestone-2-completion.md),
[ADR-0005](adr/0005-retention-execution-and-controls.md) and the
[release checklist](RELEASE-CHECKLIST.md).
