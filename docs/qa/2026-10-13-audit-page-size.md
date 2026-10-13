# Audit History page size

Adds 10, 25, 50 and 100 records-per-page options beside the current page count. Default remains 50. Read-only GET navigation automatically refreshes on change and omits the keyset cursor to restart at newest records. Older/Newest links preserve the selected size. Without JavaScript, Update records submits the form.

The repository accepts an optional size argument with a maximum of 100 and retains existing safe-field normalization and keyset ordering. No schema, retention, permission or audit-write behavior changes.

Local verification:

- PHPUnit: 1,715 tests / 6,714 assertions passed.
- JavaScript: 29 tests passed; scoped WPCS, PHP lint and diff checks passed.
- WordPress: selected 10, observed 10 records and pagination retaining `per_page=10`. Followed Older events; changing to 25 removed `before` and displayed 25 newest records.
- Desktop appearance checked; mobile body width 375 within viewport 390. Viewport reset and temporary tab closed.

No settings saved, messages sent, staging deployment or CI run. Owner acceptance pending. Rollback restores fixed page size and removes the selector; no data migration required.
