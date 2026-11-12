# Milestone 9 ticket 1 QA

Owner: Bernie. Branch: `feature/m9-t1-managed-access`.

## Implemented

- Additive site-bound Agency Manager, Support Engineer, Read-Only Auditor and
  Client Viewer profiles. WordPress roles/unrelated capabilities are unchanged.
- Administrator override, limited agency delegation, fresh managed-policy
  enforcement for SMTP/provider/critical settings and webhook lifecycle writes.
- Native-navigation Agency Settings page, nonce protection, labeled inputs,
  safe feedback and matching locked-control behaviour.
- Allowlisted actor/target/profile audit evidence, backward-compatible audit
  reads and explicit opt-in site-scoped uninstall cleanup. No schema change.

## Evidence and limitations

The pre-change combined worktree passed 1,543 PHP tests / 5,968 assertions.
Final PHP regression passed 1,582 tests / 6,103 assertions, including 38 focused
managed-access tests / 127 assertions. PHP syntax passed for all 307 repository
PHP files. Full WPCS passed after correcting formatting/docblock issues.
JavaScript syntax and 7/7 admin tests, Composer strict validation and diff checks
passed. These are local results, not a remote CI claim.

`php tests/manual/managed-access-smoke.php` passed using real WordPress capability
filters with a synthetic actor and in-memory policy/profile reads. No account or
policy writes and no mail sends were performed by the harness. This is backend
integration evidence, not a live role assignment or visual review.

Browser inventory failed twice before opening a surface: missing
`codex-windows-sandbox-setup.exe`. The computer-use skill requires stopping UI
automation after failed recovery. No staging deployment, visual pass, provider
test, remote CI or owner acceptance is claimed.

## Manual acceptance still required

1. As administrator, open Mail Relay > Agency Settings. Confirm native navigation,
   readable two-card layout, keyboard focus, labels and narrow-screen behaviour.
2. Assign profiles only to disposable site members; verify their WordPress roles
   are unchanged. Agency can manage policy, support can inspect/run diagnostics,
   auditor can read logs/audits, client cannot access operational write endpoints.
3. Enable each lock separately. A delegated user with underlying write permission
   must see managed feedback; direct POST and service calls must not save changes.
4. Verify administrator override and non-administrator agency delegation limits.
5. Review value-free policy audits and numeric-target/fixed-profile access audits.
6. Confirm missing/invalid nonces, malformed IDs/profiles and persistence errors
   leave settings unchanged. Revert temporary profiles/policy after testing.

Simplified client views, branding and aggregate client reports remain ticket 2;
configuration/routing/recovery remain subsequent tickets. Prior Milestone 8 work
is preserved and is not silently included in this ticket's completion claim.
