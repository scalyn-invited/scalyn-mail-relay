# ADR 0032: Scoped branding and client-safe summaries

Owner: Bernie. Implements the approved Milestone 9 plan, ticket 2.
Status: local implementation; visual and owner acceptance pending.

## Decision

Keep native WordPress navigation. Apply a compact agency identity/support strip
and scoped primary/heading colors to plugin screens only. Identity is stored in
a separate non-autoloaded site option, with fixed keys and no arbitrary HTML,
CSS, scripts or remote logo input. Logos reference raster Media attachments
(PNG/JPEG/WebP); deleted or unsupported attachments are omitted. Six-digit colors
must have at least 4.5:1 contrast against white. Support links require HTTPS and
exclude credentials, query strings and fragments. Saved values are validated
again before display and escaped at output. Branding requires agency authority
or the administrator override, and changes audit field names only, not values.

Approved follow-up: the plugin's top-level WordPress menu label follows the
validated saved brand name. The default identity keeps the short "Mail Relay"
label. Escape the name when applying the visible label after registration;
preserve WordPress's registered page hooks as well as the menu slug, icon,
submenus, capability gates, links and all unrelated WordPress navigation.

Client Viewer receives `VIEW_CLIENT_REPORTS`, not `EXPORT_REPORTS`. Its dashboard
is replaced before operational components are instantiated. The summary page
contains current-revision configuration health, seven-day site-wide retained
activity counts, fixed next-step guidance, and a CSV summary. It has no settings,
diagnostic execution, message logs, raw evidence, recipient data, message content,
domains, configuration IDs or provider responses. Administrators and agency
managers keep the operational dashboard even if assigned a client profile.

The CSV is a separate capability/nonce-protected admin-post boundary, not a
reduced permission on the technical export controller. It uses the same fixed
read model, excludes operational references and neutralizes formula prefixes.
It captures no persistent report or message data. Audit records only successful
preparation, not receipt or download completion. Existing technical CSV/JSON/PDF
exports retain their existing permissions and contracts; they are not exposed
to clients. No additional client PDF renderer is introduced in this ticket.

## Evidence semantics

Current health is supplied by `CurrentDiagnostics`; superseded revisions remain
excluded. Freshness uses the configured cadence plus five minutes of grace;
disabled cadence uses the existing daily reference. Stale evidence has no current
score. Invalid, missing or future timestamps remain Unknown. Activity counts are
explicitly site-wide, not provider-health calculations. Read failures are
Unavailable, never zero. Accepted remains provider acknowledgement, never inbox
delivery. The configuration score is not the ADR-0026 Deliverability Score.

## Lifecycle and validation

Approved menu-icon follow-up: a separate optional `menu_icon_id` raster attachment
labels the native sidebar with a compact symbol; the header logo stays independent.
Existing options default to 0 without migration. Missing, deleted or invalid images
restore `dashicons-email-alt2`. Media selection uses existing upload permission;
saving uses the same agency capability and nonce. Audit stores the changed field
name only. Only the owned menu image slot changes after native registration;
hooks, slugs, capabilities and submenus remain unchanged. No SVG or arbitrary URL
input is accepted. Choose a square transparent image readable at 20px.

No database migration, provider, dependency or scheduled action is added. Branding
is retained by default and removed only under explicit uninstall deletion.
Rollback makes the new profile capability/branding option inert; administrators
remain the recovery path. See the ticket QA record for executed checks and gaps.
