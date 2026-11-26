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
php -n -d extension=/path/to/mysqli.so \
  tests/integration/alert-engine-real-db.php /path/to/wp-fixture \
  /path/to/disposable/mysql.sock audit-upgrade
php -n -d extension=/path/to/mysqli.so \
  tests/integration/alert-engine-real-db.php /path/to/wp-fixture \
  /path/to/disposable/mysql.sock audit-denied
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

Both alert scenarios force the session default engine to MyISAM. For acceptance
under a server-wide MyISAM default, also start the **new disposable** server with
`--default-storage-engine=MyISAM` and record `@@global.default_storage_engine`.
Upgrade synthesizes foundation/outbox tables, explicitly makes alerts MyISAM,
inserts one synthetic incident, and sets installed version 0.14.0. Checks cover
the pre-repair exclusion failure, post-repair engines, row/index preservation,
version advancement to `SCALYN_MAIL_RELAY_DB_VERSION` (currently 0.16.0), unrelated
table preservation, local no-op exclusion completion, idempotence, missing-table
failure without advancement and retry. Fresh verifies explicit InnoDB for both
alerts **and audit logs before repair**, despite the MyISAM session default, and
executes the full migration chain.

The audit scenarios reuse this bootstrap rather than duplicating it. They build
current tables, **explicitly ALTER the audit table back to MyISAM**, insert three
synthetic rows and set installed version 0.15.0; they do not mistake current
InnoDB creation for a legacy fixture. Only the pending audit gate then runs.
`audit-upgrade` verifies InnoDB conversion, exact ordered row and full column
preservation, and all `SHOW INDEX` fields except engine-dependent `Cardinality`.
It checks the expected primary/user/action index names before comparing snapshots.
The unrelated MyISAM table's engine, DDL, data, columns and indexes must remain
identical. Installed version must reach the target constant, a current-version
retry must issue only its version read, and replaying the 0.15 gate on the already
converted table must preserve it and avoid another ALTER.

`audit-denied` additionally requires permission to create/drop a synthetic
passwordless `pr80_denied_<random>` account and grant only SELECT/INSERT/UPDATE/
DELETE on the new dedicated schema (no ALTER privilege). **Use it only in a new
ephemeral datadir/network namespace, never an existing server.** It exercises
real MariaDB ALTER permission denial through unmodified `wpdb::query()`, not a
query shim, in four suppression/display-policy combinations. It checks a generic
unchained exception, no raw SQL/driver display or PHP error log, restored caller
error policy, no version advancement, unchanged audit data/schema, and successful
retry using the fixture administrator. The synthetic account is dropped and its
absence read back in `finally`; all scenarios also drop/read back their schema.
WordPress's native mysqli reporting policy is used; this is not a strict-driver
reporting-mode matrix. Temporary PHP error-log capture is restored after each
attempt. Server general-query logs, if deliberately enabled for this entirely
synthetic fixture, are separate from PHP/WordPress error reporting.

Before any schema operations, the outer local runner must prove an empty network
route table/no non-loopback interfaces, `@@skip_networking=1`, and the exact owned
socket/datadir. Use `--no-defaults` for bootstrap/server/client commands; never
reuse a datadir or connect to an existing socket. Keep command/exit records,
fixture hashes, RED/GREEN output and cleanup evidence. Shut down only the owned
server and verify its PID and socket are gone. A network-isolated `bwrap` wrapper
can use `--die-with-parent --unshare-net --unshare-pid --ro-bind / / --tmpfs /tmp
--bind EVIDENCE EVIDENCE --dev /dev --proc /proc` with its own finite runner and
short socket path under private `/tmp`. No mail, provider call, cron tick, channel
enablement or recovery is invoked. This is **wpdb/dbDelta acceptance**, not a full
WordPress installation or minimum-supported-database-version matrix.

## Audit migration error boundary (offline real wpdb)

`audit-log-errors-wpdb.php` needs only the checksum-pinned WordPress 6.5
`class-wpdb.php` above: no database, mysqli extension, credentials or WordPress
installation. An optional second argument selects a trusted local plugin root.

```sh
php -n tests/integration/audit-log-errors-wpdb.php /path/to/wp-fixture
```

It checks **28 combinations**: success, already-InnoDB, initial metadata failure,
ALTER failure, post-ALTER metadata failure, ineffective ALTER and thrown driver
error, each with suppression and error display independently enabled/disabled.
Preparation and query outcomes are explicit shims; native `get_var()`,
`suppress_errors()` and `print_error()` exercise WordPress's result handling and
error reporting. SQL is never executed. Checks cover generic unchained failure,
no raw SQL/driver output or PHP error log, error-policy restoration and expected
ALTER count. This complements, but does not replace, the real permission-denied
scenario above; it does not prove SQL or an actual MySQL-driver failure.

## Recovery read failure boundary (offline real wpdb)

`recovery-read-wpdb.php` uses the same unmodified WordPress 6.5 `class-wpdb.php`
fixture and hash listed above. It needs no database, mysqli extension, credentials,
mail/provider transport or installed WordPress site:

```sh
php -n tests/integration/recovery-read-wpdb.php /path/to/wp-fixture
```

Optional `PLUGIN_ROOT` as the second argument selects a trusted local source tree
for a baseline comparison. Only the repository's test helper functions are loaded;
no WordPress/site bootstrap runs. Preparation and driver outcomes are explicit
shims. Native `wpdb::query()`'s not-ready early false return, the native legacy
readers' discarded returns, and native suppression/error logging/display behavior
are exercised. SQL is never executed. The runner checks 32 combinations of claim
versus submission history, early/SQL/thrown/empty result, prior suppression state
and display-error state. Failed reads must throw a fixed unchained exception,
produce no SQL/driver output or log, preserve the caller's error policy and do
no claim writes. Successful empty reads preserve null/empty-array contracts.

This complements unit cases for successful row reads, compare-and-set claim races,
worker no-send behavior and unavailable UI; it is **not** a real-database test,
actual MySQL-driver failure, installed-site or supported-version matrix test.

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
