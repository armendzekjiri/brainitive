# Security review fixes

**Branch:** fix/security-review
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-53 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-155

## Problem / goal
An external review (ChatGPT, checkout `ff693f2`) reported 3 high findings and 3 leads. We
verified each one in the code, looked for the same patterns nearby, and fixed everything
confirmed. Each fix has a regression test that fails on the old code
(`tests/Feature/Security/SecurityRegressionTest.php`: 14 tests, all failing against `main`
before the fix).

## Findings and fixes
| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | **Critical** | Uploads kept the client's file name. Docker/FrankenPHP **executes `*.php` under `/storage`**, verified with the stock image: `evil.php` → "PWNED". Public helpdesk attachments are reachable by anyone who opens a ticket, so this was unauthenticated RCE. Board/ticket attachments and image icons were affected too. | `App\Support\Uploads` (server names; active extensions → `.bin`, images by content) at every upload site; `docker/Caddyfile` (404 for PHP under `/storage`, sandbox CSP for HTML/SVG); `uploads:audit` command; nginx/Apache snippets in self-hosting.md |
| 2 | High | Public ticket submission rotated and returned a **contact-wide** token (contacts are global by email → other workspaces' tickets too). | Per-ticket HMAC token (`Ticket::publicAccessToken()`), no rotation; old contact tokens cleared (migration) |
| 3 | High | Public ticket HTML rendered raw (`dangerouslySetInnerHTML`, embed `innerHTML`); also comments, project descriptions, AI output. Plain-text helpers used `div.innerHTML` (fires `onerror`). | `SafeHtml` (symfony/html-sanitizer) on ticket description/reply write + read; DOMPurify `safeHtmlProp()` on every React sink; `DOMParser` for text extraction; widget errors as text |
| 4 | High | CLI `PATCH/DELETE /cli/lists/{list}/items/{item}` didn't check the item's list → cross-workspace edit/delete. | `scopeBindings()` on the CLI group + explicit check |
| 5 | High | Any project admin could rename / disable **global** item types for every workspace. | Per-project `disabled_item_types`; global types not editable from projects (UI hides edit) |
| 6 | Medium | SSRF: bookmark link previews (and AI endpoints, whose errors echo the body) fetched any URL incl. 169.254.169.254, localhost, private networks; redirects followed. | `App\Support\OutboundUrl` (public IPs, pinned, manual redirects, 2 MB cap); `AI_ALLOW_PRIVATE_ENDPOINTS` opt-in for local models |
| 7 | Medium | Search returned issues/doc titles from projects the user isn't in and others' private bookmark groups; MCP `fetch` returned full issue / project / private bookmark content by workspace membership only. | Search scoped to `accessibleProjectIds()` + bookmark `view` policy; MCP fetch runs `view` policies |
| 8 | Medium | Issue links accepted any item id (revealed other workspaces' issue keys/titles); card/ticket create & move accepted other boards' / helpdesks' columns; move-to-board needed only `view`. | Scoped `Rule::exists`, `view` check on link target, `createItems` on target board |
| 9 | Low | CLI login: no suspended check, no throttle; token APIs didn't re-check suspension. | Check + `throttle:5,1`; `EnsureUserIsNotSuspended` on MCP / CLI / extension groups (JSON 403) |
| 10 | Low | Bookmark temp icon move accepted `bookmark-icons/temp/../../…`. | Exact generated-name pattern |
| — | Bug | Unlinking issues returned 500 (`scopeBindings` → missing `Item::links()`). | `withoutScopedBindings()` on that route |

Not changed: `braces` (build-time only, no patched release; see fix-dependabot-alerts.md).

## Result
- Gate: `pint --dirty` ✓, `tsc` ✓, `php artisan test` ✓, `npm run build` ✓, `composer audit` ✓.
- Caddyfile verified in the real FrankenPHP image: `/storage/**.php|.PHTML|.php/x` → 404,
  SVG served with `sandbox` CSP, app and images unaffected.
- New dependencies (MIT): `symfony/html-sanitizer`, `dompurify` (was already in the tree via mermaid).

## Follow-ups
- Production isn't Docker (deploy.yml uses SSH): check that server's web config and run
  `php artisan uploads:audit` there.
- Private ticket / item attachments are still public URLs (unguessable names now). Moving
  them behind authorized downloads is a larger change.
- Ticket links sent before this release stop working (old tokens were the vulnerability).
