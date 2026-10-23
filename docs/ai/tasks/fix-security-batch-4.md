# Security hardening, batch 4: auth, OAuth, tokens (+ batch 2/3 review follow-ups)

**Branch:** fix/security-batch-4 (stacked on batch 2 and 3)
**Status:** done
**Started:** 2026-10-23

## Changes
- Social login: Google needs a verified email; linking to a never-verified local account resets
  it first (password, sessions, tokens). Sign-up no longer auto-joins invited workspaces — that
  happens on email verification (or through the invitation link).
- OAuth: client registration throttled + https redirect hosts allowlisted (`MCP_REDIRECT_HOSTS`
  to extend); consent page shows the redirect host; unverified users can't authorize apps or get
  CLI tokens.
- Tokens: password change / reset ends other sessions and revokes all tokens; Profile →
  Connected apps lists and disconnects apps.
- Web push endpoints limited to push services; OutboundUrl refuses NAT64 / 6to4 / Teredo; the
  saved AI key only goes to the saved endpoint.
- Forgot-password answers the same for unknown emails; register / forgot-password throttled;
  pages can't be framed by other sites; session cookie Secure on https installs; visitor ticket
  confirmations capped per address.
- Review follow-ups: project viewers are read-only whatever their workspace role; mention links
  reject control characters; ticket watchers like board watchers; former members can be
  unwatched and get no status mails; task-list / plan / MCP assignees must be project members;
  bookmark links http(s) only (web, MCP, render); Caddy sandboxes every non-media upload and the
  upload audit flags non-allowlisted extensions; MCP hrefs reject control characters; legacy
  project icon names keep working; reply attachments share the per-ticket cap; remaining ticket
  mails use plain visitor text; the portal hides attachments when the helpdesk turned them off.

## Tests
`tests/Feature/Security/AuthOAuthHardeningTest.php`, additions in `SecurityRegressionTest`,
`AccessControlTest`, `AdminSettingsTest`, `PlanIssueLinkTest`.
