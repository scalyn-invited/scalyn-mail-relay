# ADR 0041: Repair legacy alert storage engine

Status: Implemented in draft PR #77; local migration tests passed. Jay's
integration/release decision and controlled staging acceptance remain pending.

## Context

Installed schema 0.14.0 can retain a MyISAM `scalyn_alerts` table because the
foundation inherited the server default and the applied 0.7.0 engine repair
excluded alerts. AlertRepository correctly refuses work unless both incidents
and notifications use InnoDB. Editing the old migration cannot repair an
already-upgraded site.

## Decision

Add schema gate 0.15.0 and a focused AlertEngineSchema migration for exactly
`$wpdb->prefix . 'scalyn_alerts'`. Read its engine using exact current-database
metadata; skip verified InnoDB, convert only MyISAM with a prepared identifier,
and verify both query success and resulting engine. Missing metadata, other
engines, query errors and ineffective conversion fail with a fixed error.
The existing migrator advances the version only after this step and verifies
version persistence. Retrying after conversion but failed option persistence
skips ALTER. The applied 0.7.0 migration is unchanged.

Fresh foundation creation explicitly requests InnoDB for alerts. dbDelta
continues to own structure/index creation; explicit versioned ALTER is necessary
because dbDelta does not change engines. The notification table already requests
InnoDB. This migration does not repair other notification-table damage, weaken
repository guards, enable a channel/recovery, change cron, or send anything.

## Operations and rollback

Jay must approve the migration and staging safeguards before installation. Back
up structure/data first. ALTER may
rebuild and lock a large table, requires ALTER permission, InnoDB support and
free disk space, and implicitly commits. Schedule an appropriate maintenance
window. Failure retains the old schema version but cannot roll back completed
DDL; retry is intentional and safe. Code rollback may leave InnoDB in place
because row/column/index contracts are unchanged. Never automatically reverse
the engine conversion, lower the stored schema version, or restore a backup
over newer activity. Data restore requires its own reviewed recovery plan.

## Validation boundary

Unit tests cover allowlisting, explicit fresh engine, no-op/retry, metadata and
DDL failures, ineffective conversion, unsupported/missing engines and failed
version writes. The opt-in real-DB test uses WordPress 6.5 wpdb/dbDelta and an
isolated socket-only MariaDB with session default MyISAM. It covers installed
0.14.0 upgrade, row/index preservation, fresh creation, both alert-table engines,
versioning, no-op exclusion, missing-table failure and retry. It is not a full
WordPress installation, scheduler/notification test, MySQL compatibility matrix
or staging retest. Local QA builds are not production releases.
