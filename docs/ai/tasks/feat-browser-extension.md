# Browser extension (Chrome, Edge, Firefox)

**Branch:** feat/browser-extension
**Status:** done
**Started:** 2026-10-22
**Board card:** BRAI-52 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-151

## Problem / goal
People find work in the browser (a bug, an article, a link, a screenshot) and have to switch
to Brainitive to save it. The extension captures from any page into Brainitive in two clicks.
It is a capture tool, not a second app.

## Approach
### Auth: OAuth 2.1 + PKCE, the same server the MCP connector uses
- The extension registers itself on the chosen instance with Dynamic Client Registration
  (`POST /oauth/register`, from `Mcp::oauthRoutes()`), redirect URI = the browser's
  `identity.getRedirectURL()` (`https://<id>.chromiumapp.org/`, Firefox
  `https://<uuid>.extensions.allizom.org/`). It works on any self-hosted instance with no setup.
- Sign-in runs in `identity.launchWebAuthFlow` → Passport `/oauth/authorize` (our consent
  view) → `/oauth/token`. The extension never sees a password.
- **New `capture` scope.** Extension tokens may only use `/api/ext/v1/*`. A middleware rejects
  capture tokens on `/mcp` and `/api/cli/*`, so a leaked extension token can't read
  documents through MCP.
- Tokens live in `storage.local` (never `storage.sync`); refresh with the refresh token.

### Backend: `/api/ext/v1/*` (`auth:api` + `capture` scope, throttled)
| Endpoint | Purpose |
|---|---|
| `GET me` | User + instance name, app version |
| `GET destinations` | Workspaces → projects → boards (+ columns) / task lists / can create pages, bookmark groups; only what the user may write to |
| `POST bookmarks/{bookmark:uuid}/items` | Save a link (fetches title/icon async, like the UI) |
| `POST boards/{board}/items` | Create an issue (Markdown description, column, assign to me, optional screenshot) |
| `POST projects/{project}/pages` | Clip to a doc (Markdown → editor format, draft by default) |
| `POST task-lists/{taskList}/items` | Quick task |
| `GET search?q=` | Address-bar search (same results as the in-app search) |
| `GET my-work` | Open issues + tasks assigned to me (side panel) |

Ids are numeric in the API (the extension stores picks by id), resolved with the same
access checks as the UI: `hasWorkspaceAccess` / `hasProjectAccess` + the model policies.
Issue creation reuses `ProjectIssues::create()` (backlog on Scrum boards, like plans and
whiteboards) and notifies an assignee like the board UI.

### Extension: `browser-extension/` (WXT + React + TypeScript)
- **Popup** with four modes: Issue, Bookmark, Doc (clip), Task. Prefilled from the tab (title,
  URL, selected text). Remembers the last destination per mode.
- **Context menu**: "Save link", "Create issue from selection", "Clip selection to a doc",
  "Save page as bookmark", "Screenshot an area".
- **Screenshot**: visible tab or drag-to-select an area (overlay injected on demand), cropped
  in the worker, attached to a new issue.
- **Doc clipping**: Mozilla Readability + Turndown → Markdown, preview before saving.
- **Omnibox** keyword `bt` → live search suggestions, Enter opens the result.
- **Side panel** (Chrome/Edge side panel, Firefox sidebar): My Work + quick capture.
- **Shortcut** Alt+Shift+B opens the popup.
- Permissions: `activeTab`, `contextMenus`, `storage`, `identity`, `scripting`, `sidePanel`;
  host permission for the hosted app only; self-hosted origins via `optional_host_permissions`
  requested at sign-in. No `<all_urls>` content scripts.

### Publishing
`npm run zip` / `zip:firefox` in `browser-extension/` produce store uploads. Privacy page
gets a "Browser extension" section (stores require it).

## Changes
- Migrations: none.
- Models: `User::accessibleProjectIds()` (shared with My Work).
- Middleware: `RejectCaptureTokens` on `/mcp` and `/api/cli/*`.
- Passport: `capture` scope in `AppServiceProvider`; consent view text per scope.
- Controllers: `app/Http/Controllers/Extension/{ExtensionController,CaptureController}.php`;
  routes in `routes/api.php` (`ext.*`).
- `ProjectIssues::create()` takes an optional column + description.
- Frontend (app): Privacy page section; Help changelog v1.16.0.
- New: `browser-extension/` (own `package.json`, not part of the app build).

## Test plan
Feature tests: capture scope required on `/api/ext/v1` (no token 401, MCP-scoped token 403);
capture token rejected on `/mcp` + `/api/cli`; destinations only list writable targets;
create issue / bookmark item / page / task with policy denials (viewer, outsider); screenshot
attachment stored; search + my-work scoped to the user. Extension: `tsc`, build for Chrome +
Firefox, load unpacked in Chromium with Playwright and run sign-in → create issue against a
local server.

## Docs to update
ARCHITECTURE (new entry point + module), CONVENTIONS (scopes), KNOWLEDGE (redirect URIs,
capture scope, Firefox differences), self-hosting.md (extension works with your instance),
browser-extension/README.md, HelpModal changelog v1.16.0.

## Result
- Gate: `pint --dirty` ✓, `tsc` ✓, `php artisan test` ✓ (167, 11 new in
  `tests/Feature/Extension/ExtensionApiTest.php`), `npm run build` ✓. Extension:
  `npm ci` + `typecheck` + Chrome/Firefox zips ✓, `npm audit` 0.
- End-to-end in Chrome 155 (unpacked over CDP, local server, throwaway SQLite): self-hosted
  sign-in → consent screen (capture wording) → issue with selection quote + visible
  screenshot (attachment stored, assigned) → drag-to-select area (400×260 crop, toolbar popup
  opened by `openPopup()`) → bookmark → article clip (Readability, byline, draft) → task
  with due date → side panel My Work (overdue group) + search → options → sign-out clears
  tokens and cached destinations. Token row has `["capture"]` only.
- Not run: Firefox (not installed here). The Firefox build is MV3 with `sidebar_action` and an
  event-page background. Self-hosted sign-in on Firefox continues in the options tab (the popup
  closes on the permission prompt).
- Also changed: Privacy page section 9 "Browser extension", `.dockerignore` excludes
  `browser-extension/`, CI workflow `.github/workflows/browser-extension.yml` (only runs
  when `browser-extension/**` changes).

## Follow-ups
- Store listings (Chrome Web Store $5 account, Edge, AMO), with screenshots and the
  permission justifications in `browser-extension/README.md`.
- "Connected apps" page in profile settings to see and revoke OAuth clients/tokens (MCP +
  extension). Today, revoking means deleting tokens in the database or suspending the user.
- In-app search (`SearchController`) matches board items in every project of the user's
  workspaces, not only projects they can open. The extension reuses it, so fix it there.
