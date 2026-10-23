# Security hardening, batch 1

**Branch:** fix/security-hotfix-1
**Status:** done
**Started:** 2026-10-23

## Goal
First batch of fixes from the 2026-10-23 security audit (auth flows and request-trust settings).

## Changes
- Email verification links are temporary **signed** URLs (`signed:relative` — the signature covers
  path + query, so links keep working behind proxies / on another host). Opening a link never signs
  anyone in; a guest is sent to the login page.
- Password reset links are built from `APP_URL` (`ResetPassword::createUrlUsing`), never from the
  request host.
- Login: besides the email|ip limit, a per-account limit (20 failed attempts / 15 min, any IP),
  shared by the web login and the CLI token login (`AccountLoginThrottle`); a password reset lifts it.
- `TRUSTED_PROXIES`: the Docker example now trusts private networks instead of `*`; docs explain
  why `*` is only for setups where the app is reachable solely through the proxy.
- Removed the unused `/widget/helpdesks/{uuid}` page action.

## Tests
`tests/Feature/Auth/EmailVerificationTest.php`, `tests/Feature/Security/AuthHardeningTest.php`.
