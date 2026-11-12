# Email log filter layout and page sizes

Status, Recipient contains, and Subject contains are the default visible filters. Records per page offers 10, 25 (default), 50, and 100. Advanced filters replaces the separate message lookup and contains dates, exact provider, source, and exact message UUID. All fields share one read-only GET form; applying filters resets pagination to page one. Active advanced filters reopen their disclosure. Existing `message_uuid` timeline links and legacy `search` links remain supported.

New internal query keys `subject` and `message_id` are validated and combined with AND. Subject uses escaped literal substring matching, UUID uses validated exact matching. Existing repository bounds and capabilities remain enforced; no schema change or new metadata capture occurs. Invalid page sizes fall back to 25. Pagination retains filters, status, and page size.

Local validation, 2026-10-13:

- PHPUnit: 1,711 tests / 6,665 assertions passed.
- JavaScript: 23 tests passed.
- Scoped WPCS and diff checks passed.
- Local WordPress: 10-record selection returned 10 rows and next-page URL retained `per_page=10`; exact UUID search returned one row and reopened Advanced filters.
- Desktop and 390 × 844 mobile QA: filter form fits viewport, no body overflow observed. Temporary viewport override reset and temporary tab closed.
- Screenshots: `2026-10-13-log-filters-desktop.jpg`, `2026-10-13-log-filters-mobile.jpg`.

No settings submitted, mail sent, staging deployment, or remote CI run. Owner acceptance remains pending. Rollback reverts presentation and internal filter additions; database rollback is unnecessary.

## Records-per-page follow-up

The selector now sits beside the record count when results exist. Changing it submits the associated GET filter form automatically, preserves its filters, returns to page one, and scrolls to the results. Empty states retain the selector in the filter form. Without JavaScript, an Update records button remains available. Status and page-size select padding now vertically centers their text.

- Local WordPress: changed 10 to 25 from page two; confirmed 25 rows, Page 1, and retained Accepted status. Changed back to 10 without using Filter; confirmed 10 rows.
- Desktop and 390 × 844 mobile layout checked; mobile body width 375 within viewport 390. Status computed height 40px and vertical padding 0 confirmed.
- PHPUnit: 1,712 tests / 6,670 assertions passed. JavaScript: 24 tests passed. Scoped view WPCS, PHP lint, and diff checks passed.
- Temporary browser tabs closed and viewport override reset. No staging or CI validation claimed.
