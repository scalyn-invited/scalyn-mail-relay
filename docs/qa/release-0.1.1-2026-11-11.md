# Version 0.1.1 local release preparation — 2026-11-11

Decision owner: Jay. Scope approved: SMTP-first, latest stable WordPress local
compatibility and exact production-version ZIP preparation/testing, optional
features unchanged. No commit, push, merge, publication, deployment, live settings,
credentials or real-email sends are authorized by this record.

## Artifact and source

- Base HEAD: `6f1291cd6e4a74b253955200eeab77a51f172c90` on
  `fix/smr-qa-candidate-0.1.1-qa.2`.
- Version `0.1.1`; database schema unchanged at `0.15.0`.
- ZIP: `/home/jayryan_ai/Projects/scalyn-local-artifacts/release-011-2026-11-11/scalyn-mail-relay-0.1.1-src-491928ecba73.zip`.
- SHA-256: `04a957dc546650d308eae3e419b8e45802c6789fc5d0c564e6e9e687f9bf913e`.
- Distribution source fingerprint:
  `491928ecba73a9168632e1dd0355ca2d2b9c05ebb093af5e00eed09d4f7008b7`.
  This is a sorted path/hash manifest fingerprint, not a commit or Git tree.
- All 257 distribution source paths and 583 archive files verified, including
  CRC, unique safe paths, extraction hashes and production-only vendor inventory.
- Compared with pinned QA5, the only distributed changes are README, CHANGELOG
  and two version literals in `scalyn-mail-relay.php`. All other executable
  PHP/JavaScript and vendor bytes are unchanged. Production vendor reused from
  checksum-verified QA5, not freshly installed; five locked package versions match.

## Source/test changes

`scalyn-mail-relay.php`, `package.json`, `tests/bootstrap.php` and
`tests/unit/LifecycleTest.php` align version 0.1.1. README states SMTP-first scope,
retained advanced surfaces and limitations. CHANGELOG adds the pending-publication
version and preserves QA history. This record and the Project Status pointer are
non-distributed evidence updates, not runtime changes.

Preparation history: metadata contract test first observed three expected failures
on QA5, then all four checks passed after the version/docs edits. The RED output
is in the preparation conversation, not a retained artifact log or an independently
recreated review result; the reviewer independently reran the four GREEN checks. The helper is outside the ZIP at
`release-011-2026-11-11/test_release_identity.py` under the artifact root below.

## Executed checks

Artifact root: `/home/jayryan_ai/Projects/scalyn-local-artifacts/`.

- `python3 release-011-2026-11-11/build.py`: exit 0; exact extracted runtime mounted
  read-only over source paths in a no-egress bubblewrap namespace, retaining
  development tests/tooling outside the package.
- Strict PHPUnit on PHP 8.2.35, 8.3.36 and 8.5.12: each exit 0,
  **2,171 tests / 8,860 assertions**. Flags: `--do-not-cache-result
  --fail-on-deprecation --fail-on-warning --fail-on-notice --fail-on-risky`.
  Intentional fault-injection application stderr remains recorded; this is not
  a claim of empty logs. No PHPUnit warning/deprecation/notice failure.
- All 468 packaged PHP syntax checks and packaged WPCS passed; production autoload
  reflection smoke resolved runtime/dependency classes inside exact extraction.
- `python3 release-011-2026-11-11/check-js.py`: exit 0; seven packaged script syntax
  checks and **52 Node tests passed**, with extracted assets mounted read-only.
- `python3 release-011-2026-11-11/audit.py`: exit 0; isolated manifest-only Composer
  `--no-plugins --no-scripts --no-cache audit --locked --no-dev --format=json
  --abandoned=fail --no-interaction`. Empty advisories/abandoned/filter, checked
  UTC `2026-11-11T00:13:28.920468+00:00`. Lock SHA-256
  `34e79d9c974ef825f4a6935544f310bfd6fe16d575873e2af628e1960009bd85`.
  Root-version-detection notice retained, not an advisory. No dependencies changed.
- Review-session focused lifecycle regression on PHP 8.2.35, no-egress/read-only
  sandbox: `vendor/bin/phpunit --do-not-cache-result --filter LifecycleTest
  --fail-on-deprecation --fail-on-warning --fail-on-notice --fail-on-risky`:
  exit 0, **23 tests / 184 assertions**. Four metadata contract checks also
  reran successfully; saved installed results, Dashboard correlation and aggregates
  were revalidated without starting services or sending mail.
- `python3 release-011-2026-11-11/verify-artifact.py`: exit 0; independently rereads
  package/current-source/log/audit evidence, metadata equivalence and added-line
  security scan. `git diff --check`: exit 0.

### Genuine installed WordPress acceptance

`wp714-acceptance-2026-11-11/runs/m2` directly installed and exercised this final
exact ZIP on **WordPress 7.1.4 / PHP 8.2.35 / MySQL 8.0.46-0ubuntu0.24.04.4**.
All **162/162 checks**, all three phase commands, and eight supplemental Dashboard
correlation checks passed. Saved aggregate windows reconciled. All 3,782 core files
matched official checksums; installed core and 583 plugin files rechecked.

Scope: genuine core upgrader, capabilities/nonces, first-save protected key,
ordinary SMTP success/auth rejection with routing/recovery off, no fallback/resend,
logs/timelines/actionable UI, safe read faults, populated upgrade, migration
idempotence, retention listener and separate retain/delete lifecycle clones.
This is synthetic namespace-local SMTP, not external delivery evidence. QA4 was
the actual upgrade donor; QA5 has a separate earlier complete current-WP run.

Private no-route network namespace, socket-only database and disabled native
mail/process fallbacks contained execution. Zero plugin diagnostics; 12 expected
core update warnings from blocked outbound requests and disposable DB startup
warnings retained. Owned services stopped; DB exit 0/shutdown log, removed socket
and PID file, closed HTTP/SMTP and no remaining owned processes verified.

Full provenance, checks, warning classification and reproducibility:
`wp714-acceptance-2026-11-11/REPORT.md` and `verified-results.json`.
Earlier WP6.5/PHP8.2 MySQL8.0/MariaDB10.6 installed runs retain their QA5 identity
in `compatibility-qa5-2026-11-07/REPORT.md`; they are not relabeled as final-ZIP runs.

## Independent review

Final independent review **PASSED** after Jay authorized resuming the previously
blocked review. `release-011-2026-11-11/review.json` records empty security-concern
and logic-error lists and no blocking finite-scope evidence gaps. The reviewer
independently checked archive entry types/encryption/safe paths, every vendor byte
against pinned QA5, distributed-source parity, installed-file parity and original
phase evidence; it reran strict suites on all three PHP runtimes, syntax/WPCS,
production autoload, metadata and JavaScript checks. This is bounded release-delta
and evidence review, not an exhaustive whole-codebase security certification.

Parent `python3 release-011-2026-11-11/verify-review.py` exited 0, binding the actual
verdict to every pinned reviewed-file hash and the unchanged ZIP/current source.
`verified-review.json` retains the readback. Final documentation disposition edits
are non-distributed and do not alter the reviewed package.

Two nonblocking suggestions remain recorded: strengthen the standalone reusable
artifact verifier with entry-type/encryption and full donor-byte checks (directly
performed independently for this exact ZIP), and retain future RED-test output as
a local log. The historical RED observation above is explicitly preparation
history, not independently verified review evidence. No product defect was found
and no executable fix or package rebuild was required.

## Support and remaining authority

Supported first-release workflow: single-site Custom SMTP, ordinary WordPress
mail, metadata logs/timelines, Dashboard and manual diagnostics. Optional API
providers/webhooks/routing/recovery/agency/AI remain present and unchanged, outside
this acceptance scope. Multisite, all provider/TLS failure modes, the full version
cross-product and complete visual/mobile/keyboard accessibility are not certified.
WordPress scheduling remains traffic/host dependent; no cPanel requirement added.
Provider acceptance is not inbox delivery. No new external inbox test was performed.

After a passing review, the next approval is explicit commit/push of the reviewed
source to the existing draft PR for exact-head CI; no merge or publication implied.
Production rollout remains a separate decision. Before any installation, retain a
restorable database/files backup including the matching
`wp-content/scalyn-mail-relay-private/` key directory and any external v1 key.
Replace the plugin, do not uninstall. Missing/corrupt keys must not be regenerated
as a workaround. Reverting plugin code does not undo a completed engine migration;
coordinate database/key restoration rather than overwriting newer live activity.
