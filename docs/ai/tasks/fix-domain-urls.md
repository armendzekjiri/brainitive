# Fix: wrong domain in docs, legal pages and extension (BRAI-88)

**Branch:** fix/domain-urls
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-88

## Finding
Links and contact addresses used `brainitive.com`, which is **not ours** (other server
88.99.61.88 with its own mail server) — security reports, privacy/GDPR requests and billing
mails would have gone to a stranger. Ours: `https://www.brainitive.app` (MX via mxrouting).

## Change
All `brainitive.com` web links → `https://www.brainitive.app` (README, self-hosting docs,
extension `homepage_url` + store README, homepage mock URL, privacy/terms text); all
`*@brainitive.com` → `*@brainitive.app` (hello, security, privacy, billing, legal).
Extension 1.1.1 so the rebuilt release zips carry the new homepage.

## Follow-up for the owner
Make sure hello@, security@, privacy@, billing@ and legal@brainitive.app exist (or a catch-all).
