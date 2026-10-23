# Staging acceptance preflight — 0.1.1-qa.2

Status: operator checklist, not installation approval or a production release. Jay
installs the ZIP; QA does not change hosting, WordPress settings, cron, the
staging database, or mail routing without a separately agreed procedure.

## Identity and trigger

- Use the locally verified `scalyn-mail-relay-0.1.1-qa.2-tree-433c07290250.zip`;
  SHA-256 `3f7a5d8305c9c5a989c96dcbe7e5a6af7999c03712b2d141b7011f3583161dd7`.
  The package is from commit `d7e637b10ca508a74c7a23ff1de68878f014bec0`;
  later PR #77 changes are documentation and an opt-in test, not executable
  plugin files. Confirm the bytes Jay will upload, not just the filename.
- The staging Plugins page last showed active `0.1.1-qa.1`; read it afresh.
  The staging database was previously identified by its `siteurl`/`home` as
  `mailrelay.newwebsite.live`, prefix `wppf_`, stored schema `0.14.0`.
  `wppf_scalyn_alerts` was MyISAM and notifications InnoDB. These are dated
  observations, not current-state assertions.
- Installation/activation can call `Lifecycle::activate()` and `Migrator::migrate()`.
  Replacing an already active plugin can call `Migrator::maybe_upgrade()` on the
  first authenticated `admin_init` request by a user with the plugin's
  manage-settings capability. Backups and controls must precede the upload and
  first admin request; a version check after upload can itself start the ALTER.
- Alert scheduling is reconciled on `init` and activation, with a five-minute
  recurring hook. An already-overdue tick can run on or near the first request,
  including around migration; if the webhook is enabled it may attempt a
  notification. Do not assume the next tick is five minutes away.
  Disabling browser-triggered WP-Cron alone does not stop a separate external
  cron runner or live WordPress mail.

## Go/no-go before Jay uploads

1. Name the operator and a maintenance window with date, start/end times and
   timezone; assess table size, potential rebuild/locking, available disk,
   InnoDB support and ALTER privilege. Confirm a restorable database-and-files
   backup and who will execute rollback. MyISAM-to-InnoDB ALTER implicitly
   commits; reverting plugin code does not undo a completed engine conversion.
2. Specify and verify a reversible staging-only mail safeguard covering **all**
   WordPress mail routes, including the active SMTP/provider configuration and
   ordinary customer-triggered mail. A controlled sink/egress rule or an
   equivalent tested interception is preferable to relying on “we won't click
   Send.” State how attempts are logged, how authorized test recipients are
   isolated, and when the safeguard will be removed. Do not assume a test
   recipient alone blocks unrelated customer mail.
3. Separately contain alert webhook delivery and scheduled alert evaluation.
   Confirm whether the webhook is enabled and where it routes, and whether an
   external cron runner exists. If pausing cron, use a reversible, time-bounded
   operator procedure and account for other WordPress scheduled work. Keep
   recovery disabled; do not replay queued mail or force an alert tick for QA.
   Control both the upgrade interval **and the period after it** when natural
   ticks may resume.
4. Record a go/no-go from Jay against those concrete controls and the window.
   If any item is unknown, do not upload the schema-changing ZIP. Do not make
   a manual staging ALTER to bypass the migration.

## Narrow read-only baseline and post-install evidence

The operator should first select the candidate WordPress database and confirm
its identity, rather than assuming the phpMyAdmin default database. Example
queries below are SELECT-only. Replace the explicit schema placeholder after
identification; do not quote a guessed database name or publish session URLs,
credentials, full options, recipient addresses or provider secrets.

```sql
SELECT option_name, option_value
FROM wppf_options
WHERE option_name IN ('siteurl', 'home', 'scalyn_mail_relay_db_version');

SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'REPLACE_WITH_VERIFIED_STAGING_DATABASE'
  AND TABLE_NAME IN ('wppf_scalyn_alerts',
                     'wppf_scalyn_alert_notifications');
```

Before upload, save the Plugins version, narrow schema/version results, current
Alerts & Recovery status, cron configuration, backup confirmation and the
agreed mail/webhook safeguards. After Jay installs, read back the active Plugins
version `0.1.1-qa.2`, stored DB version `0.15.0`, and **both** alert-table engines
as InnoDB. A successful version/engine read is a migration check, not evidence
of a successful scheduled evaluation or notification delivery. With recipient
controls still active, observe a naturally scheduled alert tick and its status
without triggering one; if the status fails, capture the safe notice and
sanitized logs and stop rather than improvising SQL or a send.

## Acceptance still required

Exercise QA-03–07 affected flows only with synthetic fixtures and an agreed
recipient/permission matrix. The isolated real-DB cursor test verifies SQL
pagination but not the installed-site UI. A connection test proves neither a
provider send nor inbox receipt. A normal provider acceptance is not delivery.
Keep runtime acceptance, production versioning, PR integration and package
installation as distinct decisions. On migration or mail-safety failure, stop
traffic/upgrade activity according to the operator's plan, preserve new data,
and escalate to Jay; do not restore an old backup over newer customer activity.
