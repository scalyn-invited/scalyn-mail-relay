# Diagnostics, health scoring, and timeline verification — 2026-09-17

## Outcome

Milestone 1 ticket 4 verification completed against the current local baseline.
The authenticated Admin diagnostic action persisted five grouped results and a
health snapshot. A separate PHP process read the repositories and recalculated
the same score. The prior authorized test email's log and timeline share a UUID.
This verifies existing behavior, not completeness of the long-term specification.
Bernie owns review and all follow-up decisions.

## Scope and environment

- Checkout: `7a9699404e86c5184c8f91b04024380abf42e583`, existing
  `fix/diagnostics-qa-followups` branch; no production code changes.
- Inspected local `origin/develop` merge history through `768fe9d`; no remote
  fetch was performed, so this is not a claim of remote freshness.
- Existing WordPress 7.1 / PHP 8.2.12 / MariaDB 10.4.32 site.
  Supported-database release verification remains outstanding.
- Reviewed REST orchestration, diagnostic and health repositories, scoring policy,
  nearby tests, and ADR-0002's diagnostic probe risk boundaries.
- Computer-use skill guided live Admin verification; repository reads independently
  confirmed persistence rather than relying only on the UI.
- One diagnostic run was requested; no email, credential change, DNS change,
  deletion, migration, commit, push, or merge was performed.

## Live and persisted evidence

Run UUID: `b7c0d6a1-89ed-4d97-a101-7c25ebf05fd5`.
All five rows have site timestamp `2026-09-17 00:47:35`.

| Check | Persisted status | UI observation |
| --- | --- | --- |
| SPF | pass | Record found |
| MX | pass | One record found |
| DKIM | unknown | No selector configured; remediation requests the provider's selector |
| DMARC | pass | Enforcing quarantine policy found |
| SMTP/TLS | pass | Matching, valid certificate found |

The UI's last-run time advanced from 00:07 to 00:47. The post-run page displayed
the same statuses as the repository. Individual check score columns were null;
the current HealthScorer derives category points from statuses, not those columns.

Persisted score UUID: `057298b9-9434-4941-8e00-4929990515d0`, at the same site timestamp.
Overall, DNS, provider, and operational scores were each 100. Security and
deliverability scores were null. Recent seven-day mail counts were three accepted
and no failed rows. Recalculation matched every persisted component:
`(100 * 30 + 100 * 25 + 100 * 25) / 80 = 100`.
Unknown DKIM is excluded from the DNS average, not treated as a pass.

The prior test email UUID `8ae78e94-e4c9-4cb8-85b0-29d6bb5088dc` exists in both
repositories with status `accepted`, timestamp `2026-09-17 00:19:57`.
Its one timeline event has internal type `mail_sent` and status `accepted`;
the UI correctly labels this provider acceptance. Prior ticket 3 records the
live timeline view and user-confirmed receipt. No extra test message was sent.

The CLI-only, Git-ignored helper `build/verify-diagnostics-ticket4.php` performs
repository reads and prints only allowlisted IDs, statuses, timestamps and counts.
It does not print credentials, raw diagnostic data, subjects, or recipients.

## Follow-up gaps, not silently fixed

1. **Score coverage:** 100/100 can coexist with unknown DKIM. SPF record presence
   does not establish authorization of the actual outbound IP; DMARC policy
   presence does not prove message alignment. This does not resolve the earlier
   Gmail rejection. Improve evidence coverage messaging and authentication depth.
2. **Historical score provenance:** health snapshots have their own UUID but no
   diagnostic-run foreign key, scoring-policy version, or frozen mail counts.
   Today's recalculation is verified; historical reproducibility is not guaranteed.
3. **Concurrent/partial runs:** the endpoint persists rows individually then reads
   `find_latest_run()` rather than its own generated run UUID for scoring. Concurrent
   runs or partial writes need dedicated hardening and tests; not exercised live.
4. **Lifecycle depth:** current subscribers record terminal Accepted/Failed events,
   not all intermediate stages. The single acceptance event matches current tests;
   it is not a complete Generated-through-Accepted timeline.
5. **Source attribution:** the wizard test row displays no source. Follow up the
   context-to-source mapping; do not infer provenance from missing metadata.

Score/schema and lifecycle changes require deliberate owner review and, where
applicable, an ADR and versioned migration. They are not introduced incidentally
inside this baseline-verification ticket. Carry these into the milestone's final
gap/release decision rather than treating the checkbox as full product readiness.

## Validation

- Full PHPUnit: **673 tests, 1347 assertions passed**. Expected safe persistence
  failure messages came from intentional failure-path tests.
- PHP lint: **102 files passed** (includes, admin, tests, bootstrap and uninstall).
- WPCS: `php vendor/bin/phpcs --standard=phpcs.xml.dist` passed, exit 0.
- `git diff --check` passed; the local verification helper is confirmed Git-ignored.
- Existing tests cover unknown/error exclusion, weighted scoring, null evidence,
  credential-free context, endpoint permission checks, log privacy, terminal-event
  correlation, and simulated send/failure chains. These are not live failure tests.
- Live failure injection, concurrent runs, browser authorization-negative tests,
  and historical score reconstruction were not performed.
