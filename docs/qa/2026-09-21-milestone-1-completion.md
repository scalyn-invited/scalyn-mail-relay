# Milestone 1 — baseline completion record

Prepared 2026-09-21 for Bernie, sole owner/developer/reviewer.

## Gate decision

All seven baseline checklist activities are recorded. This closes evidence
collection and documentation, **not release approval or owner sign-off**.
No release, merge, tag, production deployment, or new feature is authorized by
this record. Bernie must review the open gates and decide their disposition.

Source: `7a9699404e86c5184c8f91b04024380abf42e583`, existing
`fix/diagnostics-qa-followups` checkout. Local `origin/develop` history includes
`768fe9d`. Remote freshness and current CI were not checked. Existing uncommitted
documentation was preserved and extended; no commits or PRs were created.

## Evidence index

| Ticket | Evidence | Result and boundary |
| --- | --- | --- |
| 1 Automated baseline | [September 16](2026-09-16-baseline-verification.md) | Local checks passed; not current remote CI proof |
| 2 Clean installation | [Clean install](2026-09-17-clean-install-verification.md) | Six tables, capabilities and server-rendered screens; source copy, not release ZIP |
| 3 SMTP workflow | [SMTP](2026-09-17-smtp-verification.md) | Existing configuration connected; one accepted message; Bernie confirmed Inbox receipt |
| 4 Diagnostics/scoring/history | [Diagnostics](2026-09-17-diagnostics-verification.md) | Five persisted results, score recalculation, matching message UUID; coverage/provenance gaps recorded |
| 5 Uninstall | [Uninstall](2026-09-17-uninstall-verification.md) | Real isolated retain/delete/reactivation; synthetic fixtures only; no file-removal UI test |
| 6 Status reconciliation | Project Status, Development Handoff, README, Release Checklist | Historical foundation claims labelled superseded; current features and boundaries explicit |
| 7 Completion record | This document | Evidence, limitations, unresolved release gates and next work consolidated |

Live evidence dates remain September 16–17. No live checks were repeated on
September 21 and no new email was sent. Local WordPress was 7.1, PHP 8.2.12,
MariaDB 10.4.32; the database is below the supported minimum. Older September 8
release evidence is preserved with its original provenance, not recertified.

## Unresolved release gates

Bernie owns each gate. These must be resolved or explicitly dispositioned before
release; completed baseline checkboxes do not waive them.

- Verify the exact candidate on supported database versions and minimum/current
  WordPress/PHP configurations. Local MariaDB evidence is not compatibility proof.
- Build the production package with production dependencies; inspect exclusions
  and secret-file hygiene; install it through WordPress on a clean site.
- Complete live unauthorized/underprivileged, nonce, error-redaction and privacy
  checks across REST, Admin, database output and debug logs. Unit coverage is not
  a substitute for this live review.
- Complete real safe-failure provider/diagnostic paths and candidate accessibility
  and UI review. Do not deliberately break the working site's configuration.
- Re-run candidate checks and verify remote CI; obtain Bernie's review, record
  rollback notes and separately authorize integration/release steps.

## Product gaps requiring explicit owner decisions

| Gap | Required next decision or work |
| --- | --- |
| 100/100 with unknown DKIM; shallow authentication evidence | Prioritize coverage messaging and outbound-IP/alignment validation; do not claim the earlier Gmail rejection is fixed |
| No score-to-run link, frozen mail counts or policy version | Design historical provenance with reviewed contracts/migrations |
| Latest-run reads and non-atomic diagnostic persistence | Scope concurrent/partial-run isolation tests and hardening before unattended monitoring |
| Only terminal lifecycle events; blank wizard source | Define real observable stage instrumentation and fix source mapping without fabricating events |
| Retention inert | Milestone 2: expiry rules, bounded repository cleanup, scheduling and settings |
| Destructive uninstall has no confirmation UI | Milestone 2; preserve explicit boolean opt-in and safe default |
| Multisite, audit/alerts/reporting/API/agency/cloud/AI absent | Keep as later roadmap scope; tables/placeholders are not implemented features |

Accepted risks and deferrals in ADR-0002 remain unchanged. This report does not
silently accept newly identified score/concurrency gaps on Bernie's behalf.
Health scores are not deliverability scores; Accepted is not Delivered.

## Documentation reconciliation scope

Project Status now leads with implemented services and verified limitations;
August and September 8 snapshots are explicitly historical. Handoff directs
readers to current evidence. README separates existing functionality from the
vision. Release Checklist keeps earlier evidence dates, links newer local QA,
and separates acceptance from receipt. No architecture, schema, runtime behavior,
dependencies, ownership assignments or release permissions changed.

Next implementation ticket: milestone 2, ticket 1 — define expiry boundaries
and repository-owned deletion contracts. This record does not start that work.

## Validation

September 17: 673 tests / 1347 assertions, production/test PHP lint, WPCS and
diff checks passed (see individual reports). September 21 rerun results are
recorded below; no live environment or external-source verification is implied.

- Full PHPUnit: **673 tests / 1347 assertions passed** on PHP 8.2.12.
  Safe persistence-error lines are expected fault-test output, not suite failures.
- WPCS: passed, exit 0.
- PHP lint: **102 files, zero failures** (includes, admin, tests, bootstrap, uninstall).
- Local Markdown links in the six changed documents: zero missing targets.
- `git diff --check`: passed.
