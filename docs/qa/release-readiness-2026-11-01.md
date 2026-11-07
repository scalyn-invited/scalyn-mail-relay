# Candidate release-readiness audit — 2026-11-01

Disposition: **NOT approved for production release**. Local checks and a narrow
staging engine-repair acceptance are complete. Focused audit found recovery
failure-path defects R1/R2 and misleading cron guidance R3; full installed-site
acceptance and release integration remain open. This is an evidence inventory,
not a claim that every unchecked path is broken.

## Authorized local implementation follow-up

After this audit, Jay authorized implementation. R1/R2 and the adjacent
submission-history read failure were reproduced with failing tests and fixed
locally; R3 copy was corrected without changing scheduling. See
[the implementation evidence](recovery-read-safety.md) for current results,
source identity and residual gates. The findings/gate table below preserve the
pre-fix audit evidence, not a claim those runtime fixes are still missing locally.
The original QA ZIP and staging do not contain the new working-tree changes.
Installed-site acceptance, strict-test/compatibility and release integration
remain open; this follow-up is not production approval.

## Identity and authority

- Repository: `/home/jayryan_ai/Projects/scalyn-mail-relay-local-build`.
- Branch: `fix/smr-qa-candidate-0.1.1-qa.2`.
- Audited code HEAD: `064942eb57279651391308d308e459caee6915f8`.
- Working tree was clean at audit start. This audit changes documentation only.
- Local `origin/develop`: `d48c10f`; not fetched and not proof of remote currency.
- Current AGENTS.md identifies Jay as integration/release decision owner.
- Installed `0.1.1-qa.2` is Jay's report, not independently hashed staging bytes.
- No merge, push, deployment, staging changes, real sends/provider calls,
  credential changes, recovery enablement or manual cron invocation authorized
  or performed by this audit. No database runner was invoked in this audit.

Product acceptance: install-and-use on supported single-site WordPress, with
native WP-Cron scheduling and honest overdue/unconfirmed status. cPanel/server
cron setup is **not a prerequisite**. Low-traffic/host-dependent scheduling is a
limitation, not evidence of an engine repair failure. Custom SMTP is a sending
transport, not a scheduling fallback. Unsupported/unavailable observations must
not become a health/delivery guarantee.

## Fresh local execution

Evidence: `/home/jayryan_ai/Projects/scalyn-local-artifacts/release-readiness-2026-11-01/`.
`run-safe-checks.py` records exact commands; `check-results.json` records exits.

| Check | Result | Boundary |
|---|---|---|
| PHPUnit, extracted PHP 8.5.12, WordPress stubs | 1963 tests / 7711 assertions, exit 0 | Five existing PHP 8.5 reflection deprecations; output says "OK, but there were issues!", not issue-free release gate |
| PHP lint | 382 tracked PHP files passed | Does not prove minimum-PHP compatibility |
| Strict PHPUnit (`--fail-on-deprecation --fail-on-warning`) | exit 1, same 1963 tests / 7711 assertions / five deprecations | Explicit issue-free gate does not pass on PHP 8.5; `phpunit-strict.log` |
| Composer validate (`--no-check-publish --strict`, no plugins/scripts) | exit 0, composer.json valid | `composer-validate-result.json`; not dependency advisory checking |
| WPCS | exit 0, no output | Not a security proof |
| JavaScript tests | 52 passed, 0 failed | Node tests, not real browser acceptance |
| JavaScript syntax | Seven assets passed | No installed-site network/console/UI exercise |
| Git whitespace | exit 0 | Rechecked after documentation changes |
| QA ZIP SHA-256 and CRC | matched / no CRC error | Existing QA ZIP, not a new production package |
| QA archive hygiene/version | no unsafe/excluded paths in narrow scan; installed.json dev=false; header/package.json both 0.1.1-qa.2 | `package-hygiene.json`; not exhaustive secret/advisory scanning |
| Runtime package parity | 253 tracked runtime files matched current checkout | excludes vendor parity with current untracked development dependencies; five production packages inventoried |
| Current remote PR/CI | BLOCKED: `gh auth status` exit 1 | No login/config/token work attempted; historical green CI not reconfirmed |

Deprecations are the five pre-existing ReflectionMethod/ReflectionProperty
`setAccessible()` uses in DiagnosticsPageTest and WizardControllerTest. Do not
silently treat exit 0 as satisfying the existing issue-free gate. Recheck on the
supported PHP 8.2/8.3 CI matrix; if PHP 8.5 is promised, resolve its test tooling
compatibility as a scoped change rather than suppressing diagnostics.

## Staging evidence supplied by Jay — October 31

Images live under `/home/jayryan_ai/.hermes/profiles/dev-ai/images/`. They contain
browser session paths: retain locally, do not redistribute uncropped originals.
No authenticated session identifiers are reproduced in this record.

- `clip_20261031_054214_1.png`: siteurl/home identify
  `https://mailrelay.newwebsite.live`; stored schema is `0.15.0`.
- `clip_20261031_054632_4.png`: explicit-schema metadata query shows
  `wppf_scalyn_alerts` and `wppf_scalyn_alert_notifications` both InnoDB.
- `clip_20261031_055853_5.png`: one settings row reports webhook disabled
  marker. Recovery option absent from the submitted two-option query; repository
  code defaults an absent recovery policy to disabled. SQL marker inspection
  is not a full serialized-settings parser or WordPress option-cache test.
- `clip_20261031_062349_8.png`: status `completed`, `at=1793397435`, observed
  epoch `1793399018`: age 26m23s.
- `clip_20261031_063847_9.png`: same completion timestamp, observed
  `1793399913`: age 41m18s, no advance over 14m55s between these observations.
- `clip_20261031_082849_10.png`: status `completed`, later `at=1793404763`,
  observed `1793406521`: age 29m18s. A subsequent completion is demonstrated;
  five-minute cadence is not.
- `clip_20261031_083013_11.png`: cPanel says no current cron jobs. This does
  not exclude a separate external scheduler.
- Jay reports DISABLE_WP_CRON/ALTERNATE_WP_CRON absent in wp-config.php.
  This supports normal traffic-driven behavior but does not exclude runtime
  overrides, loopback failures, lock contention or another hosting constraint.
- Jay reports earlier test email sending; no new send was performed here.
  Ordinary outbound mail isolation remains unconfirmed.

**Narrow closure:** schema target, engine repair and advancing successful
execution accepted as observed. No fixed scheduling/delivery claim, no proof of
row preservation on staging, and no provider acceptance/inbox-receipt inference.
Do not repair the host or add a scheduler as part of closing this defect.

## Confirmed focused findings

### R1 — queue read failure may look like no work

`includes/Database/RecoveryQueueRepository.php:67–78` relies on `get_row()` plus
`last_error`. Unmodified WordPress 6.5 `wpdb::query()` can return false early when
`ready=false` without filling `last_error`; `get_row()` discards that direct
return and returns null. The repository then returns null instead of reporting
unavailable storage, contrary to its documented claim contract. This is a
source/API robustness defect, not proof it occurred on staging. The worker's
preceding sweep can detect a persistently unavailable connection; this audit does
not claim every unready state reaches claim or that mail was lost or duplicated.

### R2 — raw driver/query logging before safe exception

The same candidate SELECT does not suppress WordPress DB error output.
`wpdb::print_error()` logs driver text and SQL before claim checks `last_error`
and throws its fixed exception. Catching/replacing the exception cannot undo
that earlier log. The history reader already uses suppression/restoration; use
that existing pattern and check the direct query outcome in a focused fix.
This is an error-path information-exposure concern; no real secrets or customer
content were used or observed leaking in this audit.

Parent reproduction: `characterize-queue-read.php` and
`queue-read-characterization.log` in the evidence directory. Exit 0 means both
undesired behaviors were characterized, **not** that recovery passed. It uses
unmodified WordPress 6.5 get_row/query/print_error with a preparation shim, no DB
connection and a synthetic query-error subclass. Initial harness execution had
duplicate-constant warnings from overlapping bootstraps; the final harness
removes that overlap and runs without those warnings. The synthetic error log
contains only a fixed dummy marker. These are not actual MariaDB fault tests.

Treat R1/R2 as open fixes for a release including recovery. Reproduce with focused
failing repository tests before implementation; keep scope to safe failure
signaling and restore the caller's DB error-suppression state. Do not silently
enable recovery or claim these defects explain staging cron delays.

### R3 — server-cron wording conflicts with install-and-use expectation

`admin/MonitoringStatusPresenter.php:85–90` says "Check the server cron trigger"
on an overdue scheduled diagnostic. That advice can imply a required server
setup although the implementation supports ordinary WordPress cron. This is a
confirmed UX wording issue, not a scheduling implementation defect. Change the
copy and its test in a scoped follow-up to describe WordPress traffic/cron and
host restrictions without implying mandatory cPanel setup.

## Candidate acceptance gates

PASS = observed in the named context; LOCAL = automated/source evidence only;
OPEN = evidence or explicit release decision still owed. Historical real-DB
results below were read, not rerun in this audit.

| Gate | Status | Evidence / remaining acceptance |
|---|---|---|
| Alert legacy upgrade and fresh schema | PASS narrow staging / historical local DB | Current screenshots plus prior 14-check upgrade/12-check fresh harness; full current ZIP fresh WordPress activation remains open |
| Install-and-use, custom SMTP setup and settings persistence | OPEN | SMTP exists; complete current candidate wizard/UI/config persistence on disposable supported WordPress without cPanel changes |
| WordPress cron and overdue presentation | LOCAL + accepted limitation | AlertService schedules on activation/init; alerts view warns after 900s; no guaranteed cadence on quiet sites. Verify installed warning and duplicate-hook behavior |
| QA-03 route-specific From | LOCAL / OPEN installed | WordPressMailBridgeTest regression; named alternate-route selection and sender behavior need intercepted/synthetic installed-site exercise |
| QA-04 recovery queue read failure | OPEN defects R1/R2 | Existing unit tests pass but miss native early-false and raw DB logging paths; parent offline characterization reproduces both. Focused fix plus disposable real-wpdb fault checks owed |
| QA-05 AI secret-prose rejection | LOCAL / OPEN installed | AiAssistantTest; synthetic dummy markers only, intercept outbound HTTP, assert no vendor call. Heuristic screening is not full DLP |
| QA-06 history read failure warning | LOCAL / OPEN real wpdb fault | RecoveryHistoryReadTest; verify unavailable differs from empty and no driver/SQL leakage on disposable WordPress |
| QA-07 equal-timestamp cursor | LOCAL + historical isolated DB | Opt-in real-DB fixture checks 61 rows split 50/11; current UI exposes only first 50, not paginated history. No complete UI browsing claim |
| Security and privacy | LOCAL / OPEN runtime | Existing capability/nonce/redaction tests; HTTP unauthenticated/low-role denial, no mutation on invalid nonce, and safe error/log output still need current installed-site verification |
| Activation/deactivation/reactivation | LOCAL / OPEN installed | Lifecycle tests; verify schedules, capabilities, option cache and preservation on current packaged candidate |
| Retention and both uninstall modes | LOCAL / OPEN current candidate DB | Destructive verification only in disposable owned fixtures with explicit approval, never staging; do not reuse old six-table counts |
| Compatibility | OPEN | Declared WP6.5+/PHP8.2+/MySQL8+/MariaDB10.6+, single site; stub suite and MariaDB12.3.3 fixture are not complete supported matrix |
| UI/accessibility | OPEN | Current desktop/mobile layouts, keyboard tabs/focus, empty/error/loading states and browser console/network not rerun |
| Providers/diagnostics/reports/agency/AI scope | OPEN | Source presence and old screenshots are not current acceptance. Agree supported release features/providers and test or explicitly defer each unaccepted path |
| Dependencies | OPEN advisory check | Five locked production packages inventoried; fresh advisory audit not executed; no claim they are vulnerability-free |
| Packaging | PASS existing QA bytes / OPEN final release | QA archive hash/CRC/runtime parity verified; final production version, candidate-linked upload install, export hygiene and dependency/advisory recheck still required |
| Git integration and owner approval | OPEN | Fresh PR #77 head/base/checks/reviews and branch currency blocked by auth. No merge/tag/release performed |

## Next safe acceptance sequence

1. Agree the production feature/provider scope, supported environments and
   evidence gates with Jay. A broad "plugin complete" claim cannot stand in for
   those decisions. No cPanel configuration is required by this product decision.
2. Use a disposable supported WordPress instance with synthetic data, local mail
   interception and blocked/mocked provider/AI/webhook HTTP. Do not clone customer
   data or copy staging secrets. Approve any needed dependency downloads and
   disposable DB creation/deletion before provisioning.
3. Verify ZIP installation, wizard/custom SMTP persistence, activation hooks,
   empty/error states, role/nonce/REST denials and responsive keyboard flows.
   Exercise QA-03–07 there without live recovery or external requests.
4. Record exact installed WordPress/PHP/DB versions, package checksum, fixtures,
   commands and sanitized outcomes. Keep provider acceptance separate from inbox
   delivery; actual integration tests need separate scoped approval.
5. Recheck current PR/CI through an already authenticated user session or approved
   authentication workflow. Do not extract/reconfigure credentials for this audit.
6. Resolve confirmed blockers in focused TDD changes; do not silently waive gaps
   or turn accepted native-WP scheduling limitations into mandatory hosting work.
7. Only after the gates pass (or Jay deliberately accepts named limitations),
   choose production version, build/verify the final ZIP and request explicit
   integration/release approval. Local green tests alone do not authorize release.

## Migration and rollback safety

ALTER can rebuild/lock and implicitly commit. A code rollback may retain InnoDB
because the row/column/index contract is unchanged. Do not automatically reverse
engines, lower the schema option or overwrite newer activity with an old backup.
The older blanket "DB bump means restore" checklist sentence was corrected.

## Independent audit addendum

Both independent read-only source audits completed against the same code HEAD.
Their full summaries are retained locally in the evidence directory. Parent
confirmed the R1/R2 code paths and reproduced them offline, and confirmed R3 in
source. Reviewer test execution is self-reported (419 tests / 1735 assertions);
parent's separately executed full and strict suite results above remain the
primary fresh execution evidence.

One adjacent pre-existing reader also needs a focused regression check:
`SubmissionAttemptRepository::history()` at lines 97–107 only checks whether
`get_results()` returns an array, and does not suppress DB errors or inspect the
direct query return/last_error. `RecoveryPanel.php:31–34` calls it for selected
message history. Source confirms this limitation; an actual failure-path
reproduction for this reader was not executed in this audit. Do not describe
QA-06's queue-summary fix as protection for every history reader. Add this reader
to the scoped failure-signaling investigation before closing recovery acceptance.

Fresh-install scheduled diagnostics default to disabled; retention, alert
checks and recovery housekeeping have separate schedules. "Install-and-use"
must not be presented as automatic diagnostic monitoring without the user's
opt-in. Confirm the wizard/help text makes that distinction; no default setting
was changed here.

ADR 0034's stale Bernie review wording was corrected to Jay's integration review;
no architectural behavior or approval status changed.

## Audit limits

The source review is focused, not exhaustive penetration testing. No live-site
flows, supported-version matrix, current dependency advisories or remote CI were
executed. Stub failures are not equivalent to actual wpdb/HTTP behavior. Existing
independent review and DB evidence are historical unless explicitly rerun above.
