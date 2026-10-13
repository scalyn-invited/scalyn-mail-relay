# ADR 0037 — Recovery operations, privacy and lifecycle

Status: implementation of the approved Milestone 9 boundaries; Bernie's final review pending.
Owner: Bernie. Date: 2026-10-12. Depends on ADRs 0031–0036 and schema 0.13.0.

## Decision

Alerts & Recovery separates incident resolution from opt-in email recovery.
Administrators/operators with manage-settings permission may change recovery
policy or cancel pending work. Every mutation requires a POST, WordPress nonce,
capability and managed critical-settings checks. Client summaries do not expose
these controls. Storage consent is explicit and never preselected.

The public read model shows only recent 50 logical identifiers, operational
state, submission count and UTC dates. Attempt history is bounded to three and
links to existing submission timelines. Recipients, subjects, bodies, headers,
attachment names/bytes, sender plans and encrypted envelopes are excluded.
Unreadable storage is unavailable, not an empty successful history. Later
submissions record Retried with logical UUID/attempt number; Accepted remains
provider acknowledgement, never inferred delivery.

Cancel uses a claim-safe update and erases encrypted payloads only before the
irreversible submitting boundary, or for review. It cannot recall an in-flight
email. There is no manual resend endpoint, worker execution endpoint, or retry
button for ambiguous outcomes. Provider activity must be reviewed externally.

Recovery registers a minute cron recurrence, reconciled on boot/activation.
Deactivation stops every owned hook, retaining data. Cleanup runs with recovery
off, erasing expired payloads and converting abandoned claims to review, never
pending. Metadata follows existing log retention. Opt-in uninstall deletion
includes both owned tables and routing/recovery options/locks. Otherwise data
is retained. Physical deletion depends on WordPress/system cron; expiry is also
enforced before decrypt/submission. No wall-clock deletion SLA is implied.

Routing belongs to ordinary WordPress mail. Direct wizard tests remain
synchronous against the selected default, ignoring source routes and queue
ownership. Changing a default does not rewrite existing pinned queue plans.

## Rollback and integration

Disable recovery and stop workers before rollback. Never replay a database
snapshot containing a possibly submitted claim. Additive schema may remain;
do not downgrade version markers or delete historical attribution. Migrations
must preserve the local Milestone 8 schema 0.10/0.11 dependencies, then apply
0.12/0.13 idempotently. Checkpoint commits alone do not include all shared
integration edits; prepare focused PR snapshots after preserving that work.

## Validation boundaries

Automated and disposable WordPress/database tests are local evidence, not live
provider failover or remote CI evidence. Desktop/mobile administrator layouts
are sampled separately. Saved branding, client-role browser acceptance,
populated owner workflows and controlled HTTPS provider recovery remain gates
before production readiness. No additional providers are implemented here.
