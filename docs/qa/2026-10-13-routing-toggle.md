# Disabled routing controls

When explicit routing is unchecked, source rule fieldsets are disabled and an explanation identifies the site-default configuration as the sending route. The enable checkbox and Save routing remain available. Toggling the checkbox updates controls immediately without saving or discarding drafts.

Disabled browsers do not submit routing selections. The routing repository therefore preserves stored rules when disabling, with the existing capability, managed lock, policy token and audit checks intact. Re-enabling validates targets again. Without JavaScript, enabling and saving restores the editable rules on reload; existing saved rules are retained when those fields are absent.

Local validation on 2026-10-13:

- PHPUnit: 1,703 tests / 6,615 assertions passed.
- JavaScript: 21 tests passed; syntax check passed.
- WPCS and diff checks passed.
- Logged-in localhost browser: all 12 route selectors disabled initially; enabling made them editable and hid the explanation; disabling retained an unsaved test selection. Reload discarded the test draft and confirmed saved routing remained disabled. No form submission or mail sending occurred.
- Screenshot: `2026-10-13-routing-disabled.jpg`.

No staging deployment or remote CI run. Owner review remains pending. Existing unrelated working-tree changes were preserved.
