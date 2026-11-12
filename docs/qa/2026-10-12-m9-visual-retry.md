# Milestone 9 visual QA retry — 2026-10-12

Result: browser access restored; local administrator visual checks completed.
This is not full ticket acceptance or staging verification.

## Environment and boundary

Existing signed-in WordPress administrator session at `http://localhost/bernz/`.
Used the in-app browser through the computer-use skill. No installation/security
changes were needed in this run. No settings, branding, roles or credentials were
saved; no diagnostic refresh, export, mail send, upload or plugin update ran.
The previous helper-discovery failure did not recur. Its root cause is not proven.

Checked desktop viewport 1159 x 668 and mobile override 390 x 844. Scrollbar
content widths were 1144 and 375 respectively. Restored the original viewport
and returned the user's tab to Diagnostics before finishing.

## Verified visually

- Diagnostics, Agency Settings and the administrator dashboard rendered at both
  widths with no document horizontal overflow or internally overflowing cards.
- Agency policy/access cards used two columns on desktop and one on mobile;
  branding fields and buttons remained usable. Default branding was visible.
- The native Media Library dialog opened with the expected title/tabs, disabled
  selection action before selection, and closed without selecting or saving.
  This does not verify a saved logo or actual branding changes.
- Lower Diagnostics guidance and authentication cards were separated. Sampled
  mobile section boundaries had 16 px gaps; screenshots confirmed the adjacent
  recommendation and disclosure cards also had visible separation.
- Keyboard Tab navigation on the administrator dashboard moved to View Logs
  with `:focus-visible` and a solid outline. This is a sampled focus check, not
  a full accessibility audit.
- The Deliverability Score showed Not assessed with only four submissions,
  separate from the configuration score; no inbox-delivery guarantee was shown.

Screenshot evidence: [Diagnostics desktop](2026-10-12-diagnostics-visual.jpg).

## Findings

1. Diagnostics category copy still says “System and deliverability assessments
   are not implemented here” although the Deliverability Score card is displayed
   lower on the same page. This is misleading copy and needs a follow-up fix.
2. Existing Jet Elements/Jet Tabs update notices consume vertical space. These
   belong to other plugins; they were not dismissed or updated.

## Pending acceptance

- Client Viewer dashboard/summary reports and locked delegated-user forms need
  a separate already-authenticated non-admin session. Requested this from Bernie;
  did not create users, change access or impersonate a real account.
- Saved custom branding, support-link behavior, invalid-input feedback and CSV
  download still need controlled manual acceptance. Earlier unit/CLI evidence is
  not a substitute for these browser scenarios.
- No HTTPS staging deployment/check or remote CI run occurred in this retry.
- Milestone 9 tickets 3–7 remain outside this visual retry and incomplete.
