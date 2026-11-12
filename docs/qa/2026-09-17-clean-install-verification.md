# Clean Install Verification 2026 09 17

## Result and scope

Milestone 1 ticket 2 passed its local clean-install checks: fresh WordPress
installation, standard plugin activation, six initially empty plugin tables,
version options, Administrator capabilities, and five Admin screen render paths.
No database errors or debug-log entries were observed.

This is local functional evidence, not certification of the supported database
baseline: the installed MariaDB version is 10.4.32, below the required 10.6.
Repeat release compatibility checks on MariaDB 10.6+ or MySQL 8+ before release.

## Environment

- Source commit: `7a9699404e86c5184c8f91b04024380abf42e583`.
- Branch: `fix/diagnostics-qa-followups`; pending repository edits are documentation and ignore-rule changes.
- WordPress: 7.1, copied from the installed local core without its site data, plugins, or configuration.
- PHP: 8.2.12.
- Database server: MariaDB 10.4.32.
- Isolated database: `scalyn_clean_20260917`; WordPress prefix: `qa_`.
- Isolated files: `build/clean-install-20260917/` (Git-ignored).
- Plugin: current checkout's `admin/`, `assets/`, `includes/`, bootstrap, and uninstall file; module/asset SHA-256 comparisons found zero mismatches.
- Runtime uses the plugin's source autoloader fallback. This does not verify a packaged release ZIP with Composer production dependencies.

The CLI-only QA configuration reads local database connection settings without
printing or copying credentials into committed files. It uses a separate database,
suppresses installation mail, disables automatic updates and WP-Cron, and blocks
outbound WordPress HTTP requests. No SMTP credentials or existing site data were
copied. An isolated Administrator account uses a generated password that was not
printed or written to an artifact.

## Checks

| Check | Evidence | Result |
| --- | --- | --- |
| Empty starting database | `CREATE DATABASE` without `IF NOT EXISTS`; refuses to reuse an existing database | Pass |
| Fresh WordPress | `wp_install()` completed in the isolated database | Pass |
| No pre-existing plugin state | No `qa_scalyn_*` tables, plugin database-version option, or plugin settings before activation | Pass |
| Standard activation | `activate_plugin('scalyn-mail-relay/scalyn-mail-relay.php')` completed without `WP_Error`; plugin active afterward | Pass |
| Table creation | Exactly `qa_scalyn_alerts`, `qa_scalyn_audit_logs`, `qa_scalyn_diagnostics`, `qa_scalyn_health_scores`, `qa_scalyn_mail_logs`, and `qa_scalyn_mail_timeline` | Pass |
| Empty plugin tables | Each table had zero rows immediately after activation | Pass |
| Version options | `scalyn_mail_relay_version` and `scalyn_mail_relay_db_version` both `0.1.0` | Pass |
| Administrator grants | All seven capabilities returned by `Capabilities::all()` enabled on the role | Pass |
| Menu registration | Five plugin submenu entries registered using real WordPress menu functions | Pass |
| Dashboard | Rendered fresh-state health content | Pass |
| Setup Wizard | Rendered welcome/setup content | Pass |
| Providers | Rendered the SMTP (PHPMailer) provider | Pass |
| Email Logs | Rendered the no-email-activity empty state | Pass |
| Diagnostics | Rendered the configure-provider guidance | Pass |
| Errors | No database error or nonempty `wp-content/debug.log` after installation, activation, and rendering | Pass |

The screen checks called the real Admin controllers under a separate WordPress
bootstrap with `WP_ADMIN` and the isolated Administrator as current user. They
verified expected rendered content, not browser navigation, visual layout,
JavaScript behavior, or HTTP authorization. Previous browser checks on the
existing site are recorded separately in the
[September 16 record](2026-09-16-baseline-verification.md).

## Local harness and retained resources

The local CLI harness is retained in the ignored test directory:

```powershell
php build/clean-install-20260917/verify.php
php build/clean-install-20260917/verify-screens.php
```

Both commands exited 0 during this run. `verify.php` is intentionally a one-time
installation command and will refuse a second run because the database exists.
`verify-screens.php` can recheck the installed screen render paths. A new clean
run requires another isolated database/directory and corresponding configuration.

The test files, database, and generated test account remain available locally.
No reset, deletion, activation change, or configuration change was made to the
existing `bernz` or `mailrelay` installations. The harness is CLI-only and is not
a publicly usable test site or authentication bypass.

## Remaining milestone and release work

- Live SMTP setup, connection verification, and test sending are ticket 3.
- Live DNS/SMTP diagnostics and newly persisted evidence are ticket 4.
- Uninstall behavior is a separate ticket and was not exercised here.
- Supported-database compatibility, clean-site browser QA, and release-package installation remain release validation work.
