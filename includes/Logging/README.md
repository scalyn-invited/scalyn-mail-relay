# includes/Logging

This module owns mail outcome persistence and append-only operational timelines.

Retention cleanup is exposed only through `MailRetentionRepository`. The
mail-log row is the aggregate root and its `created_at` value determines expiry;
related timeline rows are deleted transactionally before the parent. See
[`ADR-0003`](../../docs/adr/0003-mail-retention-boundaries.md) for the exact
cutoff, batching and failure contract. Scheduling and settings orchestration are
not part of the repository.
