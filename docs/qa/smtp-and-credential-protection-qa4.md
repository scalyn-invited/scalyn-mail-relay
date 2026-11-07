# QA4 SMTP and credential-protection verification

Date: 2026-11-05. Decision/release owner: Jay.
Status: locally verified QA candidate; NOT production approved.

## Exact final artifact

- Plugin: 0.1.1-qa.4; schema: 0.15.0 (unchanged).
- ZIP: `/home/jayryan_ai/Projects/scalyn-local-artifacts/smtp-security-fix/qa4-package/scalyn-mail-relay-0.1.1-qa.4-src-b0b002315111.zip`
- ZIP SHA-256: `f55543882b19a36aa3f9a9e8b73d9def8e21eeb34355fbce55ba6b3d43014ae0`
- Source snapshot: `b0b0023151119aef95e34be4c8b1c44c17676aac34d92192401d55455f0f7a38` (not a Git tree/commit).
- Base HEAD: `064942eb57279651391308d308e459caee6915f8` plus preserved uncommitted work.
- Archive: 583 safe unique entries; 468 packaged PHP files linted.
- Current source, archive entry hashes, CRC, production autoload, installed
  package identity and independent-review runtime hashes were verified.

## Achieved behavior

Selected Custom SMTP handles ordinary wp_mail synchronously with routing and
recovery off. Earlier pre_wp_mail decisions are preserved; SMTP failure never
falls through to PHP mail. The actual SMTP adapter retains the configured sender
display name. Native WordPress outcome hooks are emitted on this synchronous
path; observer exceptions do not turn an accepted result into failure or resend.

Fresh and explicit legacy SMTP saves protect login fields even without a named
configuration catalogue. Authorized credential writes can initialize a separate
private site key on supported hosts, without mandatory cPanel/server-key setup.
GET/status, sending, generic encryption and workers cannot initialize or replace
keys. Missing OpenSSL, missing/corrupt keys and uncertain database state fail
closed. Existing legacy credentials remain readable without implicit migration;
the next authorized credential save/import protects them or rejects the write.

First-use decisions read checked, authoritative option/marker rows rather than
trusting cache absence. Native option reads and marker writes suppress driver
diagnostics within a restored scope; exceptions are generic and unchained.
Source-bound external v1/local v2 envelopes preserve existing compatibility;
opt-in recovery can enqueue and consume v2 while retaining CAS/no-resend guards.

The private key is separately managed and retained across plugin replacement,
deactivation and uninstall (including opt-in database deletion). Backups/restores
need the matching key. No automatic key rotation, regeneration or destructive
key recovery is provided. See ADR 0042, README and uninstall policy. This protects
against database-only disclosure, not compromised PHP/WordPress/filesystem or a
server serving PHP source. Supported filesystem permissions/publication and
OpenSSL are prerequisites; an explicitly managed external key remains advanced.

## Final verification

- Strict PHPUnit: 2,168 tests / 8,833 assertions, exit 0 independently on PHP
  8.2.35, 8.3.36 and 8.5.12. Fail-on-deprecation/warning/notice/risky enabled.
  All five baseline PHP 8.5 test deprecations were fixed, not suppressed.
- WPCS: exit 0. JavaScript: 52 tests passed; seven syntax checks passed.
- PHP syntax: 394 nonvendor files on each runtime (1,182 checks), no failures.
- Native-core/offline recovery read harness: 32 checks per runtime.
- Native-core/offline authoritative option harness: 36 checks per runtime.
- Native-core/offline marker-write harness against extracted package: 16 checks
  per runtime, including stdout/log privacy and policy restoration.
- Four-process/eight-round filesystem-key creation stress passed per runtime:
  winners agree on the key and losing processes fail safely without replacement.
  These native harnesses explicitly shim driver/cache/marker boundaries; they
  are not SQL execution. Real SQL is covered by installed acceptance below.
- Final independent review passed with empty security/logic concern lists;
  all changed runtime hashes match the reviewed source.
- Exact-package full WordPress acceptance: 168/168 checks passed; all phases
  completed. WordPress 6.5 / MariaDB 12.3.3, main PHP 8.5.12 with genuine
  PHP 8.2.35 / 8.3.36 minimum-runtime smoke. Synthetic SMTP sink only, no egress.
  Covers fresh setup, native SMTP/hook/name regressions, private-key behavior,
  real option-cache/read faults, v2 recovery, roles/nonces, upgrade and lifecycle.
- Final cleanup independently checked: HTTP port closed, sockets removed and
  no owned acceptance service processes remaining.
- Current public Packagist advisory lookup evaluated all five locked production
  packages against returned constraints; none affected. This was not Composer
  audit. Production vendor bytes were reused from a checksum-verified QA2 donor;
  no new production dependency install or version change occurred.

## Reproduction and evidence

Artifact root: `/home/jayryan_ai/Projects/scalyn-local-artifacts/smtp-security-fix/`

- `verify-qa4.py`: actual local verification entrypoint; complete commands,
  runtime paths and exit codes in `final-verification/summary.json`.
- `independent-review.json`: final complete verdict and source hashes.
- `handoff-verification.json`: combined artifact/source/review/acceptance gate.
- `qa4-package/package-manifest.json`: archive and production dependency inventory.
- `acceptance-final-rerun/acceptance-summary.json` and `acceptance-report.md`:
  final exact-package installed checks; `evidence/accepted-checks.json` has all
  168 applicable assertions, with phase command/stdout/stderr evidence beside it.
- `final-verification/final-key-boundaries.json`, corrected key-native
  records, concurrency logs and review-fix artifacts retain native-boundary proof.
- `production-advisory-check.json`: source URL, timestamp and evaluated constraints.

Earlier failed reviews and snapshots remain evidence, not current blockers or
rollout candidates: first review caught four real defects; re-review caught the
marker-write diagnostic leak; separate fix contexts repaired them with observed
RED/GREEN tests. The preliminary 138-check snapshot is superseded. A later
75-check partial run failed because the lifecycle fixture rewrote only
single-quoted DB_NAME while its config used double quotes. Artifact-only parser
and owned-database guards were repaired and tested; the fresh final rerun above
completed. No application workaround or suppressed diagnostic manufactured a pass.

## Remaining release gates / authority boundaries

- Full minimum MySQL 8 / MariaDB 10.6 matrix is unverified; real SQL used 12.3.3.
- Current uncommitted work has no remote CI result; gh auth status exited 1.
- Real-provider authentication, internet/TLS behavior and inbox delivery remain
  unverified. SMTP sink acceptance is not inbox-delivery proof.
- Single-site only; no claim of multisite, every host/filesystem, mobile/browser
  accessibility matrix or every WordPress/PHP combination.
- No commit, push, merge, staging/production configuration change, deployment,
  real mail/provider request or live credential operation was performed.
- Next approval: authorized integration/CI and contained staging replacement.
  Do not install on customer-mail staging until outgoing-mail containment is
  confirmed. Back up database and matching key/files; replace, do not uninstall.
  Scheduling continues to rely on host WordPress; no cPanel cron change required.
