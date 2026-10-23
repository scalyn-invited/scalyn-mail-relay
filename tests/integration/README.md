# Integration tests

PHPUnit's regular suite uses isolated WordPress stubs. The alert engine regression
also has an explicit opt-in real-DB runner, never automatically run by PHPUnit:

```
php -n -d extension=/path/to/mysqli.so \
  tests/integration/alert-engine-real-db.php /path/to/wp-fixture \
  /path/to/disposable/mysql.sock upgrade
php -n -d extension=/path/to/mysqli.so \
  tests/integration/alert-engine-real-db.php /path/to/wp-fixture \
  /path/to/disposable/mysql.sock fresh
```

An optional fourth argument, `PLUGIN_ROOT`, selects a trusted local candidate
source directory (for example the build directory verified byte-for-byte against
the QA ZIP). It defaults to this repository. Unit helper fixtures are always
loaded from this repository, not the packaged candidate. No archive is installed.

Only use an explicitly approved disposable local server. The runner authenticates
as `jayryan_ai` over the supplied Unix socket without a password; it requires
`@@skip_networking=1`, creates a unique `scalyn_alert_engine_test_<random>` schema,
and drops that exact schema in `finally`. Do not point it at staging or production.
A process kill or fatal memory exhaustion can prevent cleanup; report any leftover
schema and obtain permission through the applicable tool approval boundary before
manual deletion. Never broaden cleanup to other schemas.

The fixture root must contain unmodified WordPress 6.5 files:

- `wp-includes/class-wpdb.php` from
  https://raw.githubusercontent.com/WordPress/WordPress/6.5/wp-includes/class-wpdb.php
  SHA-256: 4f8e67d9773fb32615f29b6556e548cde7b74c62e88c1b0892cdebd56513500f
- `wp-admin/includes/upgrade.php` from
  https://raw.githubusercontent.com/WordPress/WordPress/6.5/wp-admin/includes/upgrade.php
  SHA-256: 5c6d283cec76e34c873ae997fe9ba98e9ac39497ef95ca1d5ecc23e80c75e715
- `wp-admin/includes/admin.php` and `schema.php`: PHP-only bootstrap shims;
  no administration is booted and explicit plugin SQL is passed to dbDelta.

The runner uses actual wpdb and dbDelta, with minimal hook helpers from the unit
fixtures and a small database-backed option adapter (no WordPress option cache).
It is not a full WordPress installation or a Plugins UI installation test.

Both scenarios force the session default engine to MyISAM. Upgrade synthesizes
foundation/outbox tables, explicitly makes alerts MyISAM, inserts one synthetic
incident, and sets installed version 0.14.0. Checks cover the pre-repair exclusion
failure, post-repair engines, row/index preservation, version advancement,
unrelated table preservation, local no-op exclusion completion, idempotence,
missing-table failure without advancement and retry. Fresh additionally verifies
explicit InnoDB before the new repair runs and executes the full migration chain.
No mail, provider call, cron tick, channel enablement or recovery is invoked.

## Recovery-history cursor (SMR-QA-07)

The separate opt-in runner checks the current `RecoveryQueueRepository::summaries()`
against WordPress 6.5 `wpdb` and real MariaDB SQL, rather than the in-memory unit
stub. Use only a disposable, socket-only local server as above:

```
php -n -d extension=/path/to/mysqli.so \
  tests/integration/recovery-history-real-db.php /path/to/wp-fixture \
  /path/to/disposable/mysql.sock
```

The optional third argument selects a trusted local repository PHP file for a
regression comparison; the fourth selects an unprivileged local database user
(default: `$USER`). This runner authenticates without a password; it does not
access a WordPress site. It checks `@@skip_networking=1` and the actual socket,
creates a random `scalyn_qa07_history_<random>` schema, inserts 61 synthetic
metadata rows with a non-secret payload, asserts 50/11 equal-timestamp pages
without skips/duplicates, payload exclusion and legacy timestamp-only exclusion,
then drops only that schema and reads back its
absence. Fatal process termination can prevent cleanup; never point this runner
at a customer or staging database. It does not exercise the installed UI, mail,
providers, or live recovery.
