# Release preflight — 2026-11-07

Decision owner: Jay. Disposition: **QA5 repair acceptance closed; production
release is not yet cleared.** This preflight is local evidence reconciliation
and read-only remote inspection, not permission to commit, push, merge, publish,
change credentials/security settings or deploy.

## Closed evidence

- User screenshots show `0.1.1-qa.5` active on the dedicated test site and the
  corrected grey/pending recorded-SPF/DKIM/DMARC step, with diagnostics completion
  still green and prior SMTP WordPress password-reset activity visible. See
  [QA5 acceptance](dashboard-progress-qa5.md). No further sends/screenshots are
  owed for that scoped display repair. Remote installed-file hashes are not
  claimed from screenshots.
- Current distributed source matches all 257 QA5 source-manifest entries. The
  archive SHA-256 is
  `a31e03dccaeda2856af81ab70c2245b26a1b11af9690edd65eebd5cfd805476b`;
  all archive entry hashes and CRC were read back. No runtime source/package
  change was made in this preflight.
- Prior strict packaged-source suites passed 2,171 tests / 8,860 assertions on
  each of PHP 8.2.35, 8.3.36 and 8.5.12, plus lint/WPCS and independent review.
  These are actual earlier execution results, not rerun claims from this audit.
- Successful ordinary WordPress mail, inbox receipt, log/timeline and Dashboard
  correlation are observed on the dedicated site. Synthetic AUTH-rejection and
  no-fallback/no-resend behavior passed in isolated installed WordPress; neither
  establishes every format/provider/failure mode. See
  [core-purpose verification](core-purpose-2026-11-07.md).
- Fresh **Composer audit**, not just a hand-written advisory comparison, passed
  against an exact copied production lock: `--locked --no-dev --abandoned=fail`,
  no plugins/scripts/cache, no ignored unreachable repositories or severities.
  Exit 0; returned `advisories: []`, `abandoned: []`, `filter: []` for the five
  locked production packages. Checked UTC `2026-11-07T13:10:09.849310+00:00`.
  Lock SHA-256:
  `34e79d9c974ef825f4a6935544f310bfd6fe16d575873e2af628e1960009bd85`.
  Composer printed a root-version-detection notice because the isolated copy has
  no Git metadata; this is not an advisory or altered dependency version. No
  dependency bytes or versions changed. A clean advisory response is not proof
  of absence of unknown vulnerabilities.

## Fresh GitHub and integration state

Read-only project access works through the existing active account. The aggregate
`gh auth status` exit was nonzero because an inactive saved account is invalid;
no login/switch/token/helper operation was needed or performed.

- Existing draft PR: https://github.com/scalyn-invited/scalyn-mail-relay/pull/77
- Title still identifies QA2; state OPEN, draft true.
- Remote PR head and local HEAD:
  `064942eb57279651391308d308e459caee6915f8`.
- Current remote develop:
  `d48c10f5da89429ff4979d906dbb2937aca44759`.
- Current remote main:
  `fa5b32df21725aadbcdc8b9f2aca0a3fe2cc5875`.
- Local base is ten commits ahead of that develop SHA, zero commits behind.
  The committed work includes the QA2 sender/history/cursor/AI fixes and alert
  InnoDB repair. Later recovery-read, default-SMTP/key-protection, QA5 Dashboard,
  tests and documentation remain in the working tree, not in the remote PR head.
- Fresh `gh pr checks 77` reports PHP 8.2 and 8.3 SUCCESS, completed October 23.
  These checks belong to the old committed head, **not QA5**. The source/package
  manifest identity must be tied to the next actual integration commit and its
  new CI checks before treating QA5 as remotely validated.
- The remote reports the committed PR mergeable/clean. That does not certify
  uncommitted changes, review acceptance or production readiness.
- Current CI runs lint, WPCS, Composer tests and JS checks on PHP 8.2/8.3; it is
  not a real WordPress/database matrix. Its PHPUnit invocation does not itself
  enforce all the strict flags used by local QA. Read output or deliberately
  harden this in a separately reviewed integration change.
- Branch API reports `protected: false` for develop/main. Ruleset coverage was
  not inspected; no protection-setting changes are authorized or performed.

The final integration inventory has 79 changed paths: 60 tracked modifications
and 19 untracked files, with zero staged paths. Exclusive primary groups are:
9 recovery-read/native-cron paths, 51 default-SMTP/key-security QA4 paths,
5 Dashboard QA5 paths and 14 shared metadata/test/documentation paths. All source
paths were explained by these groups; this is not a new exhaustive security
review. `integration/final-path-inventory.json` records every path/status/hash.

Some files span groups by hunk: RecoveryQueueRepository combines checked claim
reads with v2-enqueue support; WizardControllerTest combines cipher fixtures and
PHP 8.5 tooling cleanup. Preserve those dependencies rather than blindly splitting
whole files or staging everything. The independent inventory's original 78-path
snapshot predates this new preflight document and the latest acceptance-doc edits;
its historical "not installed" wording is superseded by the closed gate above.
No `git add -A`, reset/stash or source rewrite was performed.

## Finite remaining release gates

1. **Integration and current-candidate CI — OPEN.** Review the explicit current
   path/hunk inventory, form a coherent QA5 follow-up on the existing candidate
   branch, and obtain Jay's authorization before committing/pushing or editing
   the remote PR. Recheck base/head, pushed source/package parity and actual new
   CI results. Do not merge or enable auto-merge under that authorization.
2. **Supported environments and support scope — OPEN.** Current declared floors
   are WordPress 6.5, PHP 8.2, MySQL 8 or MariaDB 10.6, single site. Recent complete
   isolated installed acceptance used WP 6.5 / MariaDB 12.3.3 with PHP 8.5 main and
   PHP 8.2/8.3 smoke; strict PHP suites and the live site walkthrough are different
   evidence boundaries. Current minimum MySQL 8/MariaDB 10.6 installed acceptance
   remains unverified. Docker exists but the current process cannot access its
   daemon; no sudo/group/security change was attempted. Portable isolated database
   runtimes are an alternative for an authorized compatibility pass, not a reason
   to silently narrow support or demand a server change from customers. Agree a
   representative minimum/current matrix before execution; this is not a demand
   to test every patch release or the full WordPress/PHP/database cross-product.
3. **Production feature/limitation decision — OPEN.** Recommended first supported
   scope: single-site Custom SMTP, normal WordPress mail, metadata logs/timelines,
   Dashboard and manual diagnostics, with normal WordPress scheduling limitations.
   This remains a recommendation, not an inferred product decision. Advanced API
   providers/webhooks/routing/recovery/agency/AI surfaces exist in the shipped
   candidate; excluding them from acceptance does not remove them from the ZIP.
   Either explicitly document/accept the non-core feature boundaries or separately
   authorize any gating/removal work. Do not silently expand this preflight into
   a rewrite or require every later roadmap feature for an SMTP-first release.
4. **Final release identity and rollout — OPEN.** After the preceding gates and
   Jay's decision, choose a production version and produce/check its exact artifact
   with matched evidence, backup/private-key restore and rollback instructions.
   QA5 is a QA label. Production publication, merge and deployment require their
   own explicit authorization. Existing working SMTP settings remain unchanged.

## Follow-ups not silently promoted to blockers or fixes

The Postmark partial-suite fixture-order issue remains documented: same three
named failures on QA4/QA5 in an unanchored mixed-class filter, while isolated exact
class and full strict suites pass. No test was weakened. Missing DKIM selector,
submission-host reverse DNS, the old operational-reliability score, recipient-
domain local blackhole routing and the earlier intermittent HTTP 500 are not
claimed repaired. Authentication checks are configuration evidence, not proof of
message authentication or universal inbox delivery.

README still describes the QA4 foundation, and some historical documents retain
superseded ownership/status wording. Final release documentation should identify
the new version/support scope and label historical provenance rather than claim
that every old checkbox is current. Keep credential-key backup requirements and
unsupported multisite/host filesystem boundaries explicit.

## Evidence and next approval

Artifacts:
`/home/jayryan_ai/Projects/scalyn-local-artifacts/release-preflight-2026-11-07/`.

`collect.py` records read-only GitHub/Git commands and a credential-isolated
Composer audit. `verify.py` checks archive/current-source parity, exact audited
lock, production dependency inventory, index state and current remote-head scope;
`verified-preflight.json` is the machine-readable current result. Independent
integration/compatibility inventory is under `integration/`; parent-verified final
path/status/hash inventory is `integration/final-path-inventory.json`.
`release-gates.json` records five closed evidence gates and four open release
gates. `proposed-pr77-body.md` is a local draft only, not a GitHub edit.

Repository documentation changed in this preflight: `docs/PROJECT_STATUS.md`,
`docs/RELEASE-CHECKLIST.md`, `docs/qa/dashboard-progress-qa5.md`, and this new
record. Runtime code, tests, package bytes, index and branches were not changed.
Git whitespace and all referenced local Markdown links were checked after edits.

Next requested authority: commit the reviewed candidate changes and push to the
existing draft PR #77 for fresh CI, with no merge/release/deployment. Compatibility
and product-scope approval still remain before production. This document itself
does not grant that authority.
