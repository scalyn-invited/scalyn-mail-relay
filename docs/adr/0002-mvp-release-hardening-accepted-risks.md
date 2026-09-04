# ADR-0002: MVP release hardening — accepted risks and deferred capabilities

**Status:** Accepted

**Date:** 2026-09-04

## Context

Final cross-module hardening before the 0.1.0 MVP release audited fresh install,
upgrade, uninstall, security/privacy, retention and packaging. The audit found
four items that are either deliberately out of scope for the release or are
deliberate design accepts. The release constraint was "no new product features",
so each was decided explicitly rather than silently implemented or silently left
broken.

This ADR records those decisions so they are visible to reviewers and to whoever
picks up the post-MVP work.

## Decision

### 1. Log retention is deferred; the dead cron event is unscheduled

`advanced.log_retention_days` (default 30) is defined and sanitized by
`SettingsRepository` but is surfaced in no admin screen, and
`Lifecycle::activate()` scheduled a daily
`scalyn_mail_relay_cleanup_logs` event. **No listener was ever registered for
that hook**, so retention never ran and mail logs, timeline rows, diagnostic
results and health scores grow without bound.

Implementing the purge means new production behaviour and new repository methods
in a module owned by Backend Platform, which is a feature, not hardening. It is
therefore deferred.

What we would not accept is shipping a WP-Cron event that fires daily and does
nothing: it is misleading in Site Health and in every cron inspector, and it
implies a guarantee the product does not honour. So `Lifecycle` now schedules no
recurring events at all, and clears the owned hook names on activation as well as
deactivation so events left behind by an earlier install are removed.

`Lifecycle::cron_hooks()` deliberately keeps the full historical list of hook
names — including the two no longer scheduled — because removing a name would
strand its events on an upgraded install.

**Consequence:** the retention setting is inert in 0.1.0. It is documented as
such in `README.md`, and retention is the first post-MVP ticket. Operators of
high-volume sites must prune tables manually until then.

### 2. `delete_data_on_uninstall` stays programmatic-only

The uninstall delete mode is gated on `advanced.delete_data_on_uninstall`, which
defaults to `false` and is exposed in no admin screen. Adding a destructive
checkbox plus its confirmation flow is UI work in a module owned by WordPress UI
and is a feature.

The release criterion — *data retained by default on uninstall unless explicitly
configured otherwise* — is satisfied: the default is retain, and the flag is a
real, honoured configuration path for programmatic/managed installs.

**Consequence:** in 0.1.0 the only way to opt into destructive uninstall is to
set the option programmatically (WP-CLI, `pre_option_` filter, or a deploy
script). `docs/UNINSTALL-POLICY.md` states this and gives the command. The
policy's requirement that the option "must require explicit administrator
confirmation" applies to the UI when it is built, and is not weakened by the
absence of that UI.

### 3. Uninstall is single-site only

`uninstall.php` operates on `$wpdb->prefix`, so on a multisite network it removes
data for the site that ran the uninstall and leaves every other site's tables and
options in place. Correct multisite uninstall requires iterating the network and
is meaningful scope.

The MVP is not certified for multisite, so rather than implement it we declare
the boundary honestly in `README.md`, `docs/UNINSTALL-POLICY.md` and the
`uninstall.php` header.

**Consequence:** multisite is unsupported in 0.1.0. A network install will leave
orphaned tables on secondary sites if uninstalled with delete mode enabled.

### 4. Diagnostic probes may reach private network ranges

`SmtpTlsCheck` and `SmtpProvider` validate the configured SMTP host by rejecting
control characters, embedded whitespace and URL scheme prefixes, but they
deliberately **do not** block private, loopback or link-local address ranges.

This is an accepted SSRF exposure, not an oversight. Self-hosted and
network-internal SMTP relays (`10.0.0.0/8`, `localhost`, a Docker service name)
are entirely legitimate mail configurations, and blocking them would break real
deployments to defend against an attacker who already holds the capability
required to change the setting.

The exposure is bounded by:

- **Capability.** Setting the host requires `MANAGE_SETTINGS`; running the probe
  requires `RUN_DIAGNOSTICS`. Both are administrator-level.
- **Protocol.** The probe speaks SMTP: it sends `EHLO` and optionally `STARTTLS`,
  and reads capability lines. It cannot be steered into issuing an HTTP request.
- **Time.** A 5-second connect timeout and a 5-second stream timeout.
- **Disclosure.** Results expose only capability flags and certificate
  subject/issuer/expiry — never response bodies, never credentials. The probe
  never authenticates.

**Consequence:** a user who already has administrator-level settings access can
use the diagnostic to learn whether a given host:port accepts TCP connections
from the server. We accept this. If a future tier grants `RUN_DIAGNOSTICS` to a
lower-privileged agency role **without** `MANAGE_SETTINGS`, this decision must be
revisited, because the probe target would then be attacker-controlled relative to
the probe runner.

## Consequences

The 0.1.0 release ships with three documented functional limitations (retention,
uninstall UI, multisite) and one documented accepted risk (probe reachability).
None of them block the release criteria. All four are recorded here rather than
in commit messages so that the post-MVP backlog has a single reference point.
