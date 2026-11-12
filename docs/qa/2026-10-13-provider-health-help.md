# Provider-health contextual help

The current provider-health badge now has a question-mark popover beside it. Existing findings and recommended action remain unchanged and escaped, but are hidden until hover, keyboard focus, or click. Click pins the popover; repeat click, Escape, and outside click dismiss it. Native details/summary retains click and keyboard access without JavaScript. Help floats above the page without expanding the card. Enhanced positioning clamps it within the viewport and flips above the control when needed; scrolling and resizing update its position.

Only provider-health badge explanations are changed. Other helper text remains unchanged pending owner direction. The component and enhancement are reusable for future explicit placements. No transport, scoring, evidence, permissions, or persistence behavior changed.

Local checks: focused Providers rendering and escaping tests; all 23 JavaScript tests; scoped WPCS, PHP/JavaScript syntax and diff checks. Logged-in localhost click/open and Escape/close were verified. Hover and focus behavior are covered by the JavaScript tests. No settings were submitted or mail sent. Screenshot: `2026-10-13-provider-health-help.jpg`.

No staging deployment or remote CI run. Owner visual acceptance remains pending.

Popover follow-up: focused JavaScript tests verify viewport clamping and placement above the control; syntax and diff checks passed. Local desktop and 390 × 844 mobile browser checks confirmed fixed positioning and that the complete popover fits the viewport. Temporary viewport override was reset. Updated screenshot: `2026-10-13-provider-health-popover.jpg`.
