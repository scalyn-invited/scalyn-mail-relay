# Local recovery read safety and WordPress cron guidance fix

Status: locally implemented/tested, not deployed or approved for production.
Jay authorized local implementation after the release-readiness audit. This
record supersedes the open local-code disposition of R1/R2/R3 and the adjacent
submission-history reader in [the audit](release-readiness-2026-11-01.md), not its
remaining installed-site/release gates.

## Source and scope

Repository `/home/jayryan_ai/Projects/scalyn-mail-relay-local-build`, branch
`fix/smr-qa-candidate-0.1.1-qa.2`, base HEAD
`064942eb57279651391308d308e459caee6915f8`. Existing uncommitted audit documentation
was preserved. No new commit, fetch, push, PR change or merge was performed.

Runtime changes:

- `RecoveryQueueRepository::claim()` inspects SELECT's direct return and result
  buffer before treating a candidate as available. Early false cannot become an
  empty queue or reuse stale rows. The same prepared SELECT, eligibility, ordering,
  LIMIT 1 and compare-and-set ownership query remain; no retry is introduced.
- Queue candidate reads temporarily suppress WordPress DB error output/logging,
  normalize failures to `Recovery queue read is unavailable.` without retaining
  private exception chains, and restore the previous suppression policy in
  `finally`. The suppression covers the candidate read, not unrelated writes.
- `SubmissionAttemptRepository::history()` applies that same checked-read/error
  boundary. Invalid UUID still returns an empty array without querying; successful
  history remains the same bounded, ordered metadata-only projection (LIMIT 3).
- Overdue diagnostic guidance describes WordPress traffic-triggered WP-Cron,
  configuration/locks and host restrictions. It conditionally mentions verifying
  an already configured external trigger if WP-Cron is disabled; it does not
  require cPanel configuration. No schedule logic or monitoring default changed.

No schema/migration, provider interface, transport, recovery consent/default,
capability, nonce, retention/uninstall or credential storage contract changes.
The initial implementation handoff had no package/version bump. Jay subsequently
requested an installable ZIP: plugin header, constant, package.json and test
bootstrap now identify **0.1.1-qa.3**; database version remains **0.15.0**.
QA ZIP: `/home/jayryan_ai/Projects/scalyn-local-artifacts/recovery-read-fix/qa3-package/scalyn-mail-relay-0.1.1-qa.3-src-8e04e44a778d.zip`.
SHA-256: `26f940ef63ee29cc2af00155060237b9529ae5f9201d0c576e5244c38bcc288e`.
Source snapshot SHA-256: `8e04e44a778d4335163039c0604404efd253000fa758e7b461472ba48643ed39`
(a manifest fingerprint, not a commit or Git tree). No new commit was created.

The QA3 archive contains 581 files; all archive bytes/CRC/paths and source parity
were verified, 466 packaged PHP files passed lint and production autoload passed.
All 32 native-wpdb checks passed against both build and separately extracted ZIP
source. Locked production vendor bytes were reused from the checksum-verified
QA2 archive; no dependency install/network or advisory refresh was performed.
After relabeling, full PHPUnit again passed 1990/7927 with the same five existing
PHP8.5 deprecations; WPCS and version/lifecycle regression passed. Exact package
manifest/check logs are in sibling artifact directory `qa3-package/`.

**The older qa.2 ZIP does not contain these fixes.** No staging installation was
performed by the agent, and no installed QA3 identity is claimed. Backup and
outbound-mail safeguards must precede user installation: replacing an active
plugin can allow overdue WordPress work to run. Replace the existing plugin;
do not uninstall/delete it as an upgrade step.

## RED → GREEN and compatibility evidence

Directory: `/home/jayryan_ai/Projects/scalyn-local-artifacts/recovery-read-fix/`.

- `claim-early-red.log`: one expected failure, missing RuntimeException; green
  afterward in `claim-early-green.log` (one test, two assertions).
- `claim-privacy-red.log`: three expected failures exposing SQL output or private
  thrown text; green after safe boundary (`claim-privacy-green.log`).
- `submission-history-red.log`: six expected failures (early false, SQL output /
  false-as-empty, private exception); all pass after history fix.
- R3 red/green logs were produced by the isolated implementation agent. Parent
  inspected the exact runtime/test diff and reran the broader focused suite.
- Initial compatibility failures came from the old fixture intercepting get_row
  rather than query. It now models SELECT's result buffer and counts SELECT
  separately from writes; CAS/race assertions and the no-send provider mock are
  retained, not removed to hide a regression.
- `RecoveryReadSafetyTest`: early/SQL/thrown/malformed result across both prior
  suppression states; no output/log/private exception chain; successful empty and
  populated rows; existing-policy restoration; invalid UUID no query; bounded
  metadata history and prepared identifiers.
- `RecoveryQueueRepositoryTest`: safe worker failure/no provider send on early
  false, existing SQL/thrown failures, silent empty queue, stale errors, row/CAS
  success and lost race without retry. Only original bounded sweep writes occur
  before worker failure; candidate read adds no claim or submission write.
- `RecoveryHistoryReadTest`: selected attempt-history failure reaches the existing
  unavailable UI instead of an empty-history claim, including early/SQL/thrown
  failure. Tests render synthetic PHP views, not a browser or installed site.

## Final executed checks

Exact commands/exits: `check-results.json`.
PHP: extracted 8.5.12; PHPUnit 10.5.64. No global PHP/dependencies were installed.

| Check | Result |
|---|---|
| Focused Recovery/Submission/MonitoringStatusPresenter PHPUnit | 113 tests, 588 assertions, exit 0 |
| Full PHPUnit | 1990 tests, 7927 assertions, exit 0; same five pre-existing PHP 8.5 reflection deprecations |
| Strict PHPUnit (`--fail-on-deprecation --fail-on-warning`) | exit 1, same five deprecations; release issue-free gate remains open |
| WPCS | exit 0, no output after fixing assignment alignment |
| PHP syntax | 384 tracked/new PHP files passed |
| JavaScript | 52 passed, 0 failed; seven assets passed node --check |
| Git diff --check | passed |
| Offline native-wpdb regression | 32 checks passed; baseline HEAD failed 22 combinations as expected |

The native runner is `tests/integration/recovery-read-wpdb.php`. It loads
unmodified WordPress 6.5 wpdb (SHA-256
`4f8e67d9773fb32615f29b6556e548cde7b74c62e88c1b0892cdebd56513500f`), with explicit
preparation/driver-outcome and minimal display-helper shims. Both show_errors
states and prior suppression states are covered, including native print_error
logging/display suppression. It never connects to a database or executes SQL;
this is not a live MySQL/MariaDB failure test or full WordPress installation.
See [integration instructions](../../tests/integration/README.md).

Independent review: the first reviewer reported no blocking security/logic
findings and suggested adding native show_errors=true coverage; that coverage
was added and the 32-case matrix rerun against current/baseline. The final
`independent-review.json` verdict is `passed=true`, with empty security concerns,
logic errors and suggestions. The final reviewer separately reported 56 focused
tests / 388 assertions and 32 native-wpdb checks passed, covering the supplemental
worker/panel tests and both display policies. Parent also independently executed
the broader/full checks above. This closes local code review, not live acceptance.

## Remaining gates and safety boundaries

- Full disposable installed-WordPress acceptance, real SQL-driver faults,
  supported-version matrix and current remote PR/CI remain open. Earlier GitHub
  auth preflight failed; no credential work was attempted.
- Existing PHP 8.5 test deprecations were not silently suppressed or fixed as
  unrelated work. Local exit 0 does not close the issue-free release gate.
- Error suppression is intentionally local to these reads. It is not a claim
  that all database write/cleanup paths are hardened against raw diagnostics.
- Successful unit/native-fixture tests do not prove mail/provider acceptance or
  inbox delivery. No real send, provider/AI/webhook request, recovery enablement,
  manual cron tick, staging/hosting mutation or destructive data action occurred.
- No permissions or auth model changes; SQL stays repository-owned/prepared and
  history excludes payload/content. No new DB reads or unbounded loop in production.
- A future verified package/installation and runtime acceptance need separate
  approval. Jay remains the integration/release decision owner.
