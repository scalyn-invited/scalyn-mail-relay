# Milestone 4 completion evidence

Owner: Bernie. Date: 2026-09-22. Branch: feature/m4-monitoring-orchestration.
Baseline: origin/develop 8b12a65. Milestone 4 remains uncommitted; remote CI has
not run on this candidate.

## Delivered

Tickets 1–4 establish shared execution, opt-in scheduling, overlap protection,
bounded checks, atomic diagnostic/health publication and durable safe run status.
Tickets 5–6 add read-only monitoring panels on Dashboard and Diagnostics, separate
retained-evidence freshness, and operational cron verification/documentation.
See ADR-0008 through ADR-0011 and the earlier per-ticket QA records.

All 789 tests / 1791 assertions passed on PHP 8.2.12. PHP lint passed for 135 files;
full WPCS, strict Composer validation and git diff --check passed. The final
timestamp-validation change also passed targeted lint/WPCS and the full suite.
Added tests cover disabled,
missing/mismatched, overdue, stale, unknown and future-time conditions, five-minute
boundaries, manual versus scheduled status, site timezone parsing, privacy and
capability-dependent configuration links.

## WordPress cron verification

The isolated verify-m4-cron.php helper guarded database name, prefix and source/
installation paths. It temporarily isolated the cron option and substituted
synthetic checks. An event two hours overdue remained pending with traffic cron
disabled; reconciliation did not push its due time forward. The actual WordPress
wp-cron.php entry point executed one scheduled run, committed five results, and
rescheduled into the future without a catch-up storm. All assertions passed.

This verifies behavior with an overdue timestamp, not a multi-hour observation.
It does not install or certify a host scheduler. Source inspection confirmed
WordPress reschedules/unschedules recurring events before invoking callbacks.
Original schedules, settings and status were restored; only named synthetic
diagnostic/score/audit fixtures were removed. No email or external probe was sent.

## UI verification and limits

A browser screenshot and accessibility tree of the actual PHP-rendered monitoring
panel verified readable overdue/stale/failure descriptions, timestamps, labelled
definition list and settings link. This used synthetic data and a standalone
preview wrapper, not an authenticated full Admin session. Existing page permission
tests pass. Full authenticated navigation, narrow-screen and keyboard QA remain
release checks.

Local MariaDB 10.4.32 is below the supported 10.6 minimum. Supported database,
packaged-candidate QA and Bernie's review remain release gates. Implementation
completion is not release authorization. Milestone 5 alerts are not included.
The unrelated local release PDF is preserved.
