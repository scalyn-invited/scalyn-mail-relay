# Core-purpose follow-up — 2026-11-07

Scope authorized by Jay: repair misleading Dashboard authentication setup progress,
and verify safe failure handling for ordinary WordPress mail locally. No new
release package, live configuration change, provider request or deployment.

## Source boundary

Checkout: `/home/jayryan_ai/Projects/scalyn-mail-relay-local-build`.
Branch: `fix/smr-qa-candidate-0.1.1-qa.2`.
Base HEAD: `064942eb57279651391308d308e459caee6915f8` plus preserved QA4 working-tree
changes. This follow-up modifies `admin/Pages/DashboardPage.php`,
`admin/views/dashboard.php` and `tests/unit/DashboardPageTest.php`, plus
`docs/PROJECT_STATUS.md` and this evidence document. No version/schema change or
ZIP rebuild is included.

## Dashboard defect and repair

Observed on the dedicated test site: health score 89/100, DKIM Unknown because no
selector was configured, while Setup Progress marked "Verify SPF, DKIM and
DMARC" complete. Root cause: both the authentication step and the separate
run-diagnostics step used the mere existence of a correlated health snapshot.

The Dashboard now derives authentication-record progress from the existing
CurrentDiagnostics scoped results. Exactly one result for each `spf_record`,
`dkim_record` and `dmarc_policy` must have status `pass`. Unknown, warn, fail,
error, missing status, missing check and duplicate check cannot complete the
step. Existing current revision/provider/domain filtering remains authoritative;
no new SQL, transport requests, credentials, permissions or persistence contracts
are introduced.

Label: "Recorded SPF, DKIM and DMARC checks passed". This describes retained
published-record checks, not verified message authentication or a live guarantee.
Existing freshness and message-authentication limitations remain separate. The
run-diagnostics step can still complete when checks report unknown or failures.
No authentication-result failure is inferred solely from pending progress.

## Execution evidence

Evidence root:
`/home/jayryan_ai/Projects/scalyn-local-artifacts/core-purpose-2026-11-07/`.

- `dashboard-red.log`: focused 89/100 + unknown DKIM regression failed before
  implementation (exit 1); output includes the incorrect completed step.
- Focused Dashboard suite: 54 tests / 162 assertions, exit 0.
- `python3 .../core-purpose-2026-11-07/verify.py`: strict full suites on PHP
  8.2.35, 8.3.36 and 8.5.12 each passed 2,171 tests / 8,860 assertions.
  Warnings/notices/deprecations/risky tests fail the run.
- WPCS, JavaScript tests, seven JavaScript syntax checks, changed-file PHP lint
  on all three runtimes and `git diff --check` passed. Full commands and exits
  are in `checks/summary.json` with scoped source hashes.
- Checks execute with network isolated by bubblewrap. The initial WPCS command
  could not create a temporary report on the read-only root; rerunning with a
  private writable `/tmp` exposed one alignment warning, corrected before the
  final successful full WPCS run. No diagnostics were suppressed to pass.
- Independent static review passed with no security or logic concerns;
  `dashboard-review.json` hashes match the three tested source files.

## Installed WordPress verification

`failure/final/run.py` passed 22/22 assertions in real WordPress 6.5 / MariaDB
12.3.3 / PHP 8.5.12, using the frozen current working-tree runtime source, not
WordPress stubs. Network namespace evidence shows loopback only, no external
route and an outbound TCP attempt blocked. Database transport was Unix socket
only. A private synthetic SMTP server explicitly rejected AUTH; no MAIL/DATA
submission or external provider request occurred.

Observed ordinary `wp_mail` returned false without throwing, emitted one safe
`wp_mail_failed` hook and no success hook, never entered core PHPMailer fallback,
and persisted one correlated Failed SMTP WordPress log/timeline. The genuine
authenticated admin timeline rendered conservative actionable guidance. No
synthetic secret/raw-transcript marker appeared in checked hooks, log/timeline
storage, UI or debug output. Recovery/routing stayed disabled; a fresh request
and three recovery-worker ticks produced no queue entry or extra SMTP attempt.
Services were stopped and the owned DB socket removed. This is AUTH-rejection
acceptance, not certification of every SMTP/TLS/network/ambiguous failure mode.

`failure/dashboard-run.py` additionally passed 11/11 assertions against the real
installed Dashboard renderer and persisted, current-scoped synthetic diagnostics:
DKIM Unknown stays pending even with score 100; three passing record checks
complete it. The previously Failed mail remains visible without another send.
This seeds diagnostic evidence; it is not a real DNS assessment or visual browser
accessibility/responsive review.

The failure harness retains earlier non-authoritative attempts in
`failure/entry-commands-and-exits.json`: a DATA-stage rejection was conservatively
unconfirmed rather than Failed, an initial fixture query used a nonexistent
ordering column, and a longer Unix-socket path was rejected. The final corrected
run asserts query success and the full submission projection; earlier output is
not reused as acceptance. No plugin runtime change was made to manufacture a
failure-path pass.

Parent read-back: `verify-evidence.py` verifies review/test/current source hashes,
installed Dashboard parity, assertion counts and cleanup; `parent-verification.json`
records the result. Full per-phase commands, assertions, containment and cleanup
records live under `failure/final/evidence/` and `failure/dashboard-evidence/`.


## Installed-site observations supplied by Jay

Screenshots and user-confirmed inbox receipts establish SMTP wizard connection,
test-email receipt at two owned inboxes, and a WordPress password-reset email
received at an owned inbox. Email Logs, timeline and Dashboard show the matching
SMTP Accepted result with `wordpress / password_reset` attribution. This is a
successful ordinary WordPress flow, not exhaustive mail-format/provider coverage.
Displayed active version is 0.1.1-qa.4; screenshots do not establish file checksum.

The recipient-domain `myvirtual.services` trace showed local `:blackhole:` routing
through `virtual_aliases`, not a handoff to Google Workspace. No routing fix is
claimed. Screenshots containing browser session/reset identifiers are private;
this document does not reproduce those identifiers or reset links.

## Remaining boundaries

Packaging follow-up: Jay subsequently authorized the distinct QA5 candidate;
see [QA5 package identity and verification](dashboard-progress-qa5.md). This does
not retroactively change the source/package identities of the runs above.

The original QA4 ZIP and last observed installed site do not contain this
Dashboard patch. QA5 packaging is recorded separately; live replacement/read-back,
remote integration/CI, broader compatibility and production release remain
separate gates. The unexplained historical 66/100 operational component, DKIM
selector gap, submission-host reverse-DNS warning and earlier intermittent HTTP
500 are not claimed repaired by this scoped change.
