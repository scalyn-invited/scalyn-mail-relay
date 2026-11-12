# Optional branded menu icon — 2026-10-12

Approved follow-up on `fix/m9-brand-menu-label`, dependent on local M9 branding
and the uncommitted menu-label follow-up. Existing M8 changes are preserved.
Refreshed origin/develop remains 71a12bd. No commit, push, PR or deployment here.

Agency Settings → Branding and support now offers a separate menu icon Media
Library picker and integer media ID. Save branding, then reload to see the icon.
Use a square transparent PNG/WebP readable at 20px; JPEG also remains supported.
0, deleted attachments, invalid stored IDs and active formats use the envelope.
The header logo is independent. Existing saved options need no migration.
Native menu hooks, links, capability, submenus and other plugins stay unchanged.
Existing capability/nonce protections, value-free audit and uninstall retention
apply to the new field. No new dependency, schema or transport behavior.

Local automated verification: PHPUnit 1,695 tests / 6,547 assertions; JavaScript
11 tests; PHP syntax 347 files; diff check passed. Full and focused coding-standard
checks passed with zero errors/warnings after repairing one alignment warning.
Native WordPress CLI smoke verified custom/default label, existing raster media
as a process-only custom icon, fallback, stable hooks and submenus; saved options
were not modified and no email was sent.

Local authenticated browser: new field defaults to 0 alongside the existing
header logo; native icon-specific Media Library dialog opens; envelope button
updates only the draft icon field with live feedback. Screenshot shows these
controls. No branding form submitted; existing user brand preserved. Actual
owner-selected icon appearance after save, mobile, staging and remote CI remain
separate acceptance checks, not claimed here.

Rollback: return menu icon to 0 and save, or revert this follow-up; stored optional
field becomes inert. No mail, provider or account setting changes are required.

## Automatic sizing follow-up

Owner screenshot exposed an oversized raster spilling beyond the sidebar.
Added fixed CSS to the native admin-menu stylesheet on every admin screen,
scoped only to this plugin's menu image. Width/height and maximum dimensions are
20px; object-fit contain preserves aspect ratio, no cropping. Padding is reset
and the image centered. No attachment file or saved branding is modified.
Setting help recommends a 40 × 40 px square transparent PNG/WebP.
Local browser measured the existing 644 × 472 raster in a 20 × 20 box with
object-fit contain; screenshot records the corrected sidebar. No form submitted.
PHPUnit passed 1,696 tests / 6,555 assertions; changed PHP syntax, full WPCS
(zero errors/warnings), and diff check passed. Remote CI not run.
