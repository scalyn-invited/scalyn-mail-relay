# ADR 0031: Site-local managed access

Owner: Bernie. Policy approved through the Milestone 9 plan on 2026-10-12.
Ticket 1 implementation is local; live visual acceptance remains pending.

## Decision

Use additive plugin access profiles in site-bound user metadata, never replacing
WordPress roles or granting unrelated capabilities. Administrators
(`manage_options`) always override profiles and managed locks, as selected by
Bernie. Agency Managers manage locks and delegated access; only administrators
may grant, demote or change agency authority.

Support Engineers inspect logs, run diagnostics and view audit history. Auditors
inspect logs and audits without running operations. Client Viewers have dashboard
access only; client-safe reports and simplified views belong to ticket 2. The
`none` profile restores underlying capabilities; invalid profiles grant no owned
capabilities. Administrator access is never reduced.

Managed mode has independent SMTP, provider and critical-setting locks. Missing
policy defaults to unmanaged; malformed saved policy fails closed. Repositories
read locks at mutation boundaries. UI restrictions are not the security boundary.
Existing capability/nonce checks remain mandatory. Background sending,
verification evidence and diagnostic execution are not configuration edits.

## Contracts and lifecycle

`AgencyRepository` owns a non-autoloaded boolean-policy option and site-bound
profile metadata. `ManagedAccess` projects only owned capabilities through
`user_has_cap`, without recursive capability checks. `VIEW_AUDIT` allows
read-only audit access; existing settings-capability access remains compatible.

Policy audits contain field names, actor, time and correlation, not values.
Access assignment uses audit metadata version 4: target numeric user ID and fixed
profile identifier. Versions 1-3 remain readable. No names, addresses, secrets,
roles or arbitrary context are added. Existing audit retention applies.

No schema migration, new provider, cron hook or dependency is introduced.
Site-bound metadata avoids cross-site grants but does not declare multisite
support. Deactivation/default uninstall retain policy and profiles. Explicit
uninstall deletion removes only current-site owned metadata/options; other sites
and unrelated roles/capabilities remain intact. Rollback leaves retained data
inert; WordPress administrators remain the recovery path.

Tests cover profile restrictions, overrides, direct writes, fresh policy reads,
malformed input, failed persistence, delegation, membership, nonce checks, audit
projection and scoped uninstall. Actual QA evidence is recorded separately.
