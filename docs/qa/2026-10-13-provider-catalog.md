# Provider catalogue and details drawer

Follow-up: at the owner's request, search, configuration filtering and the filtered-result count were removed from the current UI. All provider tiles remain visible; drawer behavior is unchanged. The earlier filter QA below describes the initial implementation, not current functionality.

Replaces the enhanced Providers tab's full-card list with compact provider tiles, local vector wordmarks, search and configured/not-configured filtering. The active sending route remains above the catalogue. Future registered providers receive an envelope fallback and their escaped provider label without requiring a new transport contract.

The native modal dialog moves the original credential-free card into the right-hand drawer and restores it on close. Opening details never selects a provider or performs network requests. Existing wizard links, permissions and evidence interpretation are unchanged. Escape, Close and backdrop dismiss the panel; focus returns to the opening tile. Mobile uses a full-screen panel. Browsers without supported dialogs or JavaScript retain all original full cards. Bundled provider marks are simplified visual identifiers, not a new external logo service.

Local verification:

- Full PHPUnit suite: 1,713 tests / 6,686 assertions passed before the final logo-only adjustment; final focused Providers tests: 8 tests / 91 assertions passed.
- JavaScript: 28 tests passed, including combined filters, empty results, original-card restoration, focus return, backdrop dismissal, hash navigation and unsupported-dialog fallback.
- Scoped WPCS, PHP lint, JavaScript syntax and diff checks passed.
- Local WordPress: case-insensitive provider search, configured/unconfigured filtering and empty result message confirmed. Brevo retains its recorded connection, Unknown health and disabled delivery collection. SMTP remains unassessed; opening either card does not change the route.
- Keyboard Enter opens a tile; Escape closes and restores tile focus. Tab moves to controls inside the native modal.
- Desktop right-hand drawer and 390 x 844 mobile full-screen layout reviewed. Mobile content width remains within its viewport. Temporary viewport overrides reset and QA tabs closed.

No email sent, settings saved, schema changes, staging deployment or remote CI run. Owner acceptance remains pending. Rollback reverts the view, script, enqueue, local marks and scoped CSS; no database rollback required. Existing unrelated working-tree changes were preserved.
