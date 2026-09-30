# Admin interface redesign

Owner: Bernie. Branch: `ui/admin-redesign`, based on merged `origin/develop`
at `bd04f3c`. Scope: approved mockup adapted to native WordPress navigation.

## Current PR scope

This file is a chronological QA record; later revisions supersede earlier scope
statements. The complete change includes all existing admin pages, the seven-step
wizard with inline SendGrid configuration, and approved optional log metadata and
search. Schema 0.3.0 and ADR-0019 apply to the complete PR. Capture is off by default;
disable it before a code rollback, leave additive columns in place, and retain the
stored schema version. Historical statements below about no migration describe
only the initial UI pass, not the final PR.

Pre-publication validation: 1,089 PHP tests / 3,362 assertions and seven JavaScript
tests passed. Final remote CI results are recorded on the pull request.

## Implemented

- Shared teal-accent design tokens, typography, controls, badges, card spacing,
  table presentation and responsive layouts scoped to plugin content.
- Dashboard actions first, real retained activity totals, configuration score
  with optional breakdown, monitoring alongside health, scope limitations below.
- Native diagnostic disclosures: passing checks collapsed; warning, critical and
  unknown findings expanded. All evidence remains server-rendered and accessible
  without JavaScript. Detailed coverage caveats remain available below the checks.
- Provider cards use the existing registered-provider read model. Saved settings
  are not described as a successful connection or delivery.
- Log timeline modal side panel, loaded only on request from the existing
  capability-gated admin page. Native modal keyboard/focus behavior, close/escape,
  20-second request bound, cancellation, safe error and full-page fallback.
- Settings, alerts, audit history, reports and wizard share the same controls and
  surfaces. Wide audit/incident tables have focusable horizontal-scroll regions.
- Existing calendar inputs, forms, nonces, server validation and permission gates
  preserved. Asset timestamps invalidate browser caches after UI updates.

## Security, data and rollback

No provider, repository, schema, service-container, public API, retention or cron
contract changes. No migrations or additional stored fields. No new third-party
assets or runtime dependencies. No mockup sample counts or sample provider errors
were copied into the plugin. Existing privacy/acceptance-versus-delivery wording
is retained. The drawer imports only the escaped detail view, not admin chrome or
scripts; it does not cache fetched pages and clears content when closed.

Rollback is a code revert; no database rollback is required. The unrelated local
release-readiness PDF is not part of this change.

## Automated verification

- PHP 8.2: 1,057 tests / 3,155 assertions passed after the provider-card follow-up.
- Six dependency-free Node tests passed: unsupported-browser fallback, modified
  clicks and cross-origin rejection, successful view loading, HTTP/login/error
  fallback, private exception suppression and pending-request cancellation.
- Full WordPress Coding Standards check passed.
- Composer strict validation passed.
- PHP syntax (182 files), JavaScript syntax and whitespace checks passed locally.
- CI now includes the Node syntax and drawer tests alongside its PHP matrix.
  Remote CI has not been run for this uncommitted branch.

## WordPress QA

- Dashboard: real retained counts, real score and monitoring state rendered.
- Logs: retained SMTP timeline loaded in panel; acceptance caveat preserved;
  Escape closed the panel and returned focus to the originating timeline link.
- Diagnostics: passing checks collapsed, DMARC warning expanded; keyboard Enter
  expanded the SPF evidence.
- At 320px browser width, dashboard, logs, diagnostics, providers, settings,
  reports, audit, alerts and wizard rendered without document-level horizontal
  overflow (305px content viewport including the browser scrollbar). Tables scroll
  within their regions. The loaded mobile timeline panel had equal client/scroll
  widths of 289px, with its error state hidden.
- Desktop dashboard and provider cards visually inspected at 1280px.
- No credentials edited, settings submitted, reports exported, diagnostics run,
  or test messages sent during this UI QA. Existing third-party update notices
  remain visible; they were not dismissed or changed.

Bernie's visual review is the remaining acceptance step. This is an adaptation
of the approved design to existing functionality, not new deliverability evidence,
AI, automatic failover, provider delivery webhooks or agency functionality.

## Provider-card follow-up

Dashboard now includes the registered active-provider label, known transport type
(SMTP/API; unreported for extensions), stored verification state and last successful
verification time in site time. This is not a latest-attempt or live-status claim.
Unknown dates are not fabricated. SendGrid's sandbox limitation remains explicit.
Manage/verify links require their destination's capabilities. No credential reads,
connection probes, new storage, or transport calls were added. Tests cover escaped
labels, absent/invalid dates, private fields, extension fallback and link gates.
Live WordPress confirmed SMTP (PHPMailer), SMTP transport, its stored successful
verification timestamp, and both management links.

## Setup Wizard follow-up

- Applied the approved teal/card styling to the existing six-step wizard, with
  explicit step progress, visible mobile step labels, provider selection cards,
  stacked form labels and contextual setup guidance.
- Adapted the UI/UX specification to current functionality: only registered
  providers are offered, and Diagnostics remains a separate next action rather
  than an invented automatic health-check step. Removed the welcome screen's
  unsupported promise to verify delivery and generate a score.
- Step markers describe navigation, not proof that a test email was delivered.
  SendGrid's incomplete configuration has a visible disabled Continue button.
  The completion-screen Diagnostics link is capability-gated.
- Existing POST handling, nonce fields, credentials, provider selection and
  verification gates are unchanged. No service, schema or transport changes.
- Six focused view tests cover all steps, escaping, form fields, empty password,
  SendGrid readiness and the Diagnostics link's capability requirement.
- Full validation: 1,063 PHP tests / 3,213 assertions, six JavaScript tests,
  WPCS, PHP syntax (184 files) and whitespace checks passed.
- WordPress QA: all six SMTP-flow screens inspected without submitting forms;
  provider cards and the SMTP form checked at 320px with no page overflow.
  All six progress labels remain visible. Desktop and mobile layouts reviewed;
  viewport restored afterward. SendGrid variants covered by automated view tests,
  without changing the site's active provider or sending a message.
- Screenshot: `scalyn-wizard-redesign.png` in the task visualization folder.
  Bernie's visual acceptance is pending; these changes remain uncommitted.

## Seven-step wizard revision (supersedes six-step adaptation above)

At Bernie's request, the wizard now follows Welcome, Choose Provider, Configure
Provider, Verify Connection, Send Test Email, Health Check, and Completion.
Step 6 runs the existing nonce- and capability-protected diagnostics endpoint
through the shared admin script, then refreshes the same step. It reads the latest
persisted health snapshot through HealthScoreRepository and the shared presenter;
it does not calculate a separate score. Step 7 reviews the selected provider,
recorded health evidence and links to findings/recommendations. Unknown health
remains unknown, and existing evidence is timestamped with freshness guidance.

The existing verified-provider navigation gate remains. Test email and health
checks are explicit actions, not mandatory completion flags: continuing without
a health run is permitted and explicitly disclosed. No schema, REST contract,
credential storage or mail transport changes were introduced. Diagnostics
capability is required both for score visibility and the run action.

Validation: 1,063 PHP tests / 3,224 assertions; seven JavaScript tests including
wizard POST/nonce/same-step refresh coverage; WPCS, changed PHP syntax and diff
checks passed. Live WordPress Health Check generated a new snapshot at site time
2026-09-30 08:15:30, refreshed step 6, and Continue reached step 7. No email sent
or provider settings changed. Seven progress steps fit a 320px viewport without
horizontal page overflow; viewport reset. Desktop screenshot saved as
`scalyn-wizard-seven-steps.png`. Remote CI and Bernie's visual acceptance remain
pending; no commit or PR created.

## Inline SendGrid configuration

Step 3 now embeds the shared SendGridSettingsForm instead of linking out to
Providers. Sender and key changes post back to wizard step 3, with explicit save
feedback. Continue to verification follows the form and uses saved settings.
SMTP retains its existing inline form. Providers retains its standalone form.
No additional providers or provider contracts were introduced.

The same MANAGE_MAIL permission, nonce validation, encrypted repository writes,
key replacement/removal confirmation and credential-free error feedback are reused.
Wizard access additionally requires MANAGE_SETTINGS. The API key input is always
empty. Automated tests exercise encrypted saving in wizard context and continued
navigation. Browser QA confirmed the inline fields, empty key input, step-3 form
target and step-4 Continue target without saving live credentials or sending mail.

## Providers page refinement

Added an active sending-route summary, descriptive provider cards, transport and
recorded verification details, capability-gated wizard actions, an in-page
SendGrid settings shortcut and switching guidance. Details explain that saved
settings and historical verification are not live health or delivery evidence.
Only registered providers appear; no unsupported failover/disconnect controls,
new transports, schema changes or credential behavior changes were introduced.

Tests cover configured/unconfigured and verified/unverified states, restricted
wizard actions, secret exclusion and absence of connection probes during page
rendering. Full suite: 1,067 PHP tests / 3,254 assertions; seven JavaScript tests;
WPCS, changed-file PHP syntax and diff checks passed. WordPress desktop cards and
320px mobile layout visually inspected with no page overflow. The SendGrid edit
shortcut reaches the in-page form, whose key field remains empty. No settings
submitted or mail sent. Viewport restored. Screenshot: scalyn-providers-redesign.png.

### Wizard-only configuration revision

Removed the Providers-page SendGrid form and its POST handler. Provider settings
are now accessed through the wizard only. The redundant SendGrid edit shortcut
is removed: active-provider Configure opens step 3; inactive-provider Select
opens step 2 before configuration. These links do not change provider selection.
The credential-safe shared form remains in wizard step 3.

Validation: 1,068 PHP tests / 3,261 assertions; WPCS, changed-file syntax and diff
checks passed. Tests also confirm Providers no longer handles credential POSTs.
Browser QA confirmed no settings form and SendGrid's Select link opens step 2.
No provider selection, credential or email changes were made during QA.

## Email Logs page refinement

Added a distinct filter panel, optional message UUID lookup, visible-page record
count, site-time context, accessible table caption/scroll region, semantic
pagination and privacy/retention disclosure. The existing timeline drawer and
full-page fallback remain available. Tablet layouts retain all columns through
horizontal scrolling; mobile rows expose field labels, including an override
for WordPress's competing responsive table styles.

Only existing allowlisted metadata is shown. No recipients, subjects, bodies,
credentials, unsupported filters or invented delivery statistics were added.
Provider acceptance is explicitly distinguished from recipient delivery.
No persistence, permission, transport or schema contracts changed.

Validation: full PHP suite passed (1,069 tests / 3,268 assertions); final focused
LogsPage tests passed (54 tests / 81 assertions). WPCS, PHP syntax, seven
JavaScript tests and diff checks passed. Browser QA verified desktop layout,
320px labelled mobile cards, the Failed filter's empty state, UUID lookup and
timeline drawer content. Viewport restored; no settings saved or emails sent.
Screenshot: scalyn-logs-redesign.png.

### Approved UI/UX specification expansion

Supersedes the metadata-only limitations of the preceding UI pass. Bernie
approved optional recipient/subject capture with explicit consent and retention.
Implemented the specified Timestamp, Recipient, Subject, Provider, Source,
Status and Actions column order; search; calendar date range; provider, source,
recipient and status filters; and drawer guidance. Existing attachment metadata
remains in the drawer. Raw headers and provider transcripts remain excluded.

Settings now offers off-by-default capture of future To addresses and subjects.
No historic backfill. Schema 0.3.0 adds nullable fields to the existing mail
aggregate so retention and uninstall policies continue to apply. ADR-0019
documents privacy, boundaries, truncation, migration and rollback behavior.

Validation: 1,082 PHP tests / 3,305 assertions; seven JavaScript tests; WPCS and
diff checks pass. Focused tests cover consent, disabled capture, schema readiness,
no backfill on updates, recipient validation, bounded subjects, absence of bodies
and BCC headers, prepared wildcard searches, invalid dates, output escaping,
pagination filters, migration verification failure and repeat migration guards.
Local WordPress verified the 0.3.0 schema and capture remains off. Browser QA
verified combined date/provider/source filtering, unchanged legacy records,
drawer guidance and 320px mobile cards without page overflow. Viewport restored.
No settings submitted, test messages created or emails sent during browser QA.
Screenshot: scalyn-logs-spec-aligned.png.

## Diagnostics page refinement

Added evidence-backed category summaries for DNS routing, DNS authentication
records and the combined SMTP/TLS/certificate check. Each category distinguishes
recorded warnings/failures from inconclusive checks and links to the results.
Each check card now exposes recorded issue count and last-result site timestamp,
with documentation links and clearly labelled recommended actions. Passing
details remain collapsed; warnings and unknown findings remain expanded.
Recommendations and verification limitations remain below Overall Email Health.

The UI explicitly describes the limits: system and deliverability assessments
are not implemented here, SMTP/TLS is not complete provider health, and supported
checks rerun together. The existing nonce-protected POST action now uses a native
button instead of a GET-capable link. No check engines, scoring, persistence,
permissions, privacy policy, retention or shared service contracts changed.

Validation: 1,085 PHP tests / 3,336 assertions; seven JavaScript tests; PHP syntax,
WPCS and diff checks pass. Added tests for warning/failure counts, incomplete
evidence, timestamp escaping and native run control. Browser QA inspected desktop
and 320px mobile layout without horizontal overflow; category and documentation
links resolve correctly. No live diagnostics or email sending triggered during
QA. Viewport restored. Screenshot: scalyn-diagnostics-redesign.png.

## Remaining existing admin pages

Completed the UI pass for Audit History, Alerts & Recovery, Reports and Settings.
Audit History adds a bounded record count, interpretation guidance and a
keyboard-focusable horizontal table region with readable timestamp/outcome
columns. Alerts adds notification preference, configuration readiness and
recovery-behavior overview cards without claiming verified webhook delivery or
automatic email repair. Reports groups the existing form into period, provider
scope, and format/privacy fieldsets with a contextual guide. Settings adds
section navigation and visual grouping while preserving separate DKIM and data
control saves, consent text, retention warnings and uninstall confirmation.

No new report templates, alert destinations, white-label controls, delivery
testing pages, backend actions or schema changes are introduced by this pass.
Native WordPress navigation, nonces, capability checks and current input names
are preserved. Existing unavailable capabilities are described explicitly.

Validation: 1,089 PHP tests / 3,362 assertions; seven JavaScript tests; changed
view syntax, WPCS and diff checks pass. Focused tests cover bounded record labels,
section targets, form counts, report steps, scope limitations and recovery copy.
Desktop and 320px mobile browser QA completed for all four pages. Long tables
scroll inside their regions without page overflow; Settings anchor navigation
works and still has two independent forms. Viewport restored. No settings,
notifications or report downloads were submitted during QA.
Screenshots: scalyn-audit-redesign.png, scalyn-alerts-redesign.png,
scalyn-reports-redesign.png and scalyn-settings-redesign.png.
