# Saved brand name in the WordPress sidebar

Scope: approved follow-up on `fix/m9-brand-menu-label`, based on the local
Milestone 9 stack. Existing Milestone 8/integration changes are preserved.

The top-level plugin label follows the validated saved brand name. Default or
invalid branding displays Mail Relay. The label is escaped after registration,
so WordPress's page hook identities, slug, icon, submenus and capabilities do
not change. No schema, credentials, provider, recipient or send behavior changes.
Agency Settings now explains the menu-label behavior. Existing branding save
permissions, nonce and audit handling are reused without a new settings field.

Validation:

- PHPUnit: 1,690 tests / 6,531 assertions passed, including default/reset,
  malformed stored values, HTML escaping and isolation of the owned menu item.
- Full WPCS passed without errors/warnings; PHP syntax and diff checks passed.
- CLI native WordPress menu registration test passed custom/default/invalid
  branding and unchanged submenu/page-hook/capability/slug/icon assertions.
  Synthetic branding was process-only; no branding was saved or email sent.
- Computer-use visual check passed on authenticated local WordPress: the
  existing saved Alpha Support name appears in the sidebar and identity strip;
  the sidebar still links to `admin.php?page=scalyn-mail-relay`. No branding,
  managed-policy or provider form was submitted. This is local visual evidence,
  not staging, CI, or owner acceptance.

![Local saved-brand menu](2026-10-12-brand-menu.jpg)

To verify another name: Agency Settings > Branding and support > Brand name >
Save branding, then reload. Restoring the default Scalyn Mail Relay identity
restores the short Mail Relay menu label. Rollback simply stops applying the
custom label; saved branding and existing links remain intact.
