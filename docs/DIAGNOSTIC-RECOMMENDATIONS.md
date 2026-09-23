# Milestone 6 ticket 3 — Prioritised diagnostic recommendations

Owner: Bernie. Branch: `feature/m6-t3-recommendations`.
Baseline: merged develop `4171d55` (PR #49).

The Diagnostics page includes **What to address next**, a read-only rule-based
panel using its already-loaded latest diagnostic run. No AI, DNS changes, mail
sends, new endpoints, schema, shared interfaces or container registrations.

## Rules and evidence

- Supported checks: SPF record, DKIM record, DMARC policy, MX records and SMTP/TLS.
- Fresh failures precede warnings, then evidence gaps. Within each group,
  critical/high/medium/low/unknown severity determines priority. Check ID provides
  deterministic tie-breaking. Guidance explains the impact and next steps.
- Each recommendation identifies its check, diagnostic run UUID and site-local
  timestamp. Missing checks explicitly say no retained result. Matching diagnostic
  cards supply the detailed observations. Raw strings, arbitrary stored actions,
  credentials, response bodies and message content are not copied into the panel.
- Passing checks generate no remedial item. Missing, unknown, error or unrecognised
  statuses generate evidence-acquisition guidance rather than claims of failure.
- No retained supported results, stale/future/invalid timestamps, invalid or mixed
  run UUIDs, duplicate supported checks or more than 250 input rows trigger a
  refresh-first notice and suppress configuration recommendations.
- Freshness matches monitoring: cadence plus five minutes, defaulting to 24 hours
  plus five minutes when disabled. Site-local timestamps use the current WordPress
  timezone, retaining the existing historical timezone/DST limitation.
- At most five recommendations. Unsupported extension checks are not interpreted;
  the panel explicitly lists its coverage. No recommendations is not a health or
  delivery guarantee. Database absence/failure that yields no repository rows is
  treated as missing evidence, not success.

SPF guidance never authorizes an IP automatically. DKIM guidance does not infer
unsigned mail from an unknown selector, and entering a selector does not enable
signing. DMARC guidance asks for sender alignment review before tighter enforcement.
MX concerns are explicitly inbound, not evidence of outbound failure. SMTP guidance
never recommends disabling certificate verification. Later bounce analysis and
message-level authentication remain outside this ticket.

## Access, rollback and validation

The existing RUN_DIAGNOSTICS capability gate precedes reads and rendering. Output
is escaped at the template boundary. No new privileged action or nonce is needed
because the panel does not mutate state. Revert the ticket to remove it; there is
no data migration, retention change or stored recommendation state.

PHPUnit: **918 tests, 2,101 assertions passed**. Full WPCS and diff checks passed.
Focused tests cover all five check actions, ordering, ties, unknown/error/missing
evidence, freshness boundaries, site timezone, invalid correlations and exclusion
of raw/private strings. Existing page capability tests remain in place.

PHP syntax: **160 files passed**. The isolated real WordPress Diagnostics page
rendered the panel successfully; synthetic fail/warn/unknown findings sorted as
expected and stale evidence required refresh. A browser preview of the actual
template confirmed ordered headings, readable guidance and evidence references.
This preview used synthetic data and is not full authenticated production QA.
No diagnostic network checks, external mail or settings changes were performed.

## Approved layout follow-up

Recommendations and detailed verification limitations now appear, in that order,
at the bottom of Diagnostics after the health/results sections. The score keeps a
short authentication/delivery disclaimer. Existing recent-failure findings remain
ahead of the guidance. With no configured provider, the guidance remains available
below setup instructions. Dashboard layout is unchanged. Directional guidance now
refers to the diagnostic cards above. A regression test checks order, uniqueness
and the score disclaimer. WordPress CLI rendering and changed-file syntax checks
passed; the live browser follow-up timed out, so full visual review of the revised
page remains outstanding.

The guidance-card stack uses a 16px top/between-card gutter. The Admin stylesheet
uses its file modification time as its enqueue version, so CSS updates are not
hidden by a previously cached plugin version. A browser measurement confirmed two
cards, 16px `row-gap`, 16px top margin and a 16px rendered gutter.

Bernie's review and remote CI remain outstanding. The unrelated PDF is untouched.
