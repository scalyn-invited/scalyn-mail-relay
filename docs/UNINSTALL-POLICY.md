# Uninstall Policy

## Default behaviour: keep all data

Uninstalling Scalyn Mail Relay removes **nothing** unless destructive deletion
has been explicitly enabled first. Deactivating the plugin never removes data
either; it only clears the plugin's scheduled events.

This is the default, and it is what happens for any operator who has not
deliberately changed the setting. Settings, custom tables, mail logs, timeline
events, diagnostic history and health scores all survive an uninstall/reinstall
cycle intact.

## Optional destructive mode

The advanced setting `delete_data_on_uninstall` (default `false`) opts into full
removal. When it is `true`, uninstall removes:

| Removed | Detail |
|---|---|
| Custom tables | `scalyn_mail_logs`, `scalyn_mail_timeline`, `scalyn_diagnostics`, `scalyn_health_scores`, `scalyn_alerts`, `scalyn_audit_logs` |
| Options | `scalyn_mail_relay_settings`, `scalyn_mail_relay_db_version`, `scalyn_mail_relay_version` |
| Capabilities | Every `scalyn_mail_relay_*` capability, removed from every role |
| Scheduled events | Every cron hook the plugin has ever owned |
| Transients | `scalyn_mail_relay_health_cache`, `scalyn_mail_relay_diagnostics_cache` |

Nothing outside that list is touched. Options, capabilities and transients
belonging to other plugins are left alone.

The gate is `true === (bool) $value`, so the option must be **truthy** to enable
deletion. Falsy values — `false`, `0`, `'0'`, `''`, or the key being absent
entirely — all retain data. Note that a truthy string such as `'1'` or `'yes'`
does enable deletion: the cast happens before the strict comparison. Write the
option as a real boolean (`--format=json true` above) rather than relying on
string coercion.

## 0.1.0: the flag is programmatic-only

**There is no admin UI for this setting in 0.1.0.** The only way to enable
destructive uninstall is to set it programmatically. See
[ADR-0002](adr/0002-mvp-release-hardening-accepted-risks.md) for why this was
deferred.

```bash
wp option patch update scalyn_mail_relay_settings advanced delete_data_on_uninstall --format=json true
```

To confirm the current value before uninstalling:

```bash
wp option get scalyn_mail_relay_settings --format=json
```

When the UI is built, this option must require explicit administrator
confirmation — a checkbox alone is not sufficient, because the action is
irreversible and is triggered later, at uninstall time, by someone who may not be
the person who set it. That requirement stands regardless of the current absence
of the UI.

## Scope: single site only

`uninstall.php` operates on the current site's table prefix. On a multisite
network it removes data for the site that runs the uninstall and leaves every
other site's tables and options in place. Multisite is not supported in 0.1.0.

## Verifying uninstall behaviour

Both modes are covered by automated tests in `tests/unit/UninstallTest.php`,
which assert that retain mode issues no `DROP TABLE` and revokes no capability,
and that delete mode removes exactly the table/option/capability/event/transient
set above and nothing else. The manual procedure is in
[RELEASE-CHECKLIST.md](RELEASE-CHECKLIST.md).
