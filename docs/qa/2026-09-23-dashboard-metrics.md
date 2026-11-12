# Milestone 6 ticket 2 — Dashboard activity metrics

Owner: Bernie. Branch: `feature/m6-t2-dashboard-metrics`.
Baseline: merged `origin/develop` at `f108dcc` (PR #48).

Dashboard now shows seven days of retained activity across all providers using
ticket 1's repository API. The half-open site-local period ends at page-load time;
Accepted, Failed, other statuses and total counts are distinct. Read-as-of time
and refresh guidance describe freshness without implying live monitoring. Existing
health-snapshot freshness and scheduled-execution status remain separate.

Accepted is explicitly provider submission acknowledgement, not inbox delivery.
Later bounces are not included automatically. Counts use creation time/current
status and may be incomplete due to retention. Zero activity does not establish
health. Failed reads show unavailable rather than fabricated zeroes and never
render exception details. The existing dashboard capability gate runs first.

Changes are confined to Admin presentation and tests. No schema, shared contracts,
REST, dependencies, credentials, configuration, mail sends or retention changes.
Reverting the ticket needs no data migration. Date/provider filter controls,
recommendations and exports are outside this ticket.

Validation:

- PHPUnit: **895 tests, 2,053 assertions passed**.
- Full WPCS and `git diff --check`: passed. PHP syntax: **157 files passed**.
- Isolated real WordPress dashboard render includes counts and separate health
  freshness. Browser reviewed a synthetic rendering of the actual card in populated,
  empty and unavailable states; heading/definition-list semantics and readable
  content verified. This is not full authenticated production-browser QA.
- The preview helper initially stopped after the dashboard check due to its local
  include path; using the plugin path constant fixed the harness. Rerun generated
  all three states successfully. No production settings were submitted.

Bernie's review and remote PR/CI remain outstanding. Unrelated PDF untouched.
