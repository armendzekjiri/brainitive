# Secure sharing for docs and issues (BRAI-15)

**Branch:** feat/secure-sharing
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-15 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-17

## Decisions
Docs (single page, no sub-pages) and issues. Plans already had share links; bookmarks and snippets have public visibility. Whiteboards come later.

## Changes
`share_links` (morph, token, expiry, revoke, view count) + `ShareLink` + `ShareLinkController` (index/store/destroy need `update` on the item; public `show` builds a whitelisted payload, `X-Robots-Tag: noindex`, 410 for revoked / expired / deleted). `Pages/Share/Show.tsx` (standalone, sanitized HTML). `components/share/ShareButton.tsx` (hidden without edit rights; "Shared" state) on docs and in the issue panel.

## Tests
`tests/Feature/Pages/ShareLinkTest.php`. Browser: share issue → public view → revoke → 410.
