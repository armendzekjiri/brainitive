# Brainitive browser extension

Save the page you're on to Brainitive: an **issue** (with a screenshot), a **bookmark**, a
clipped **doc**, a **task** or a **snippet** — or just **save it to your inbox** and organize it later
in the app (Captures). Works in Chrome, Edge and Firefox, with Brainitive Cloud and
self-hosted servers.

| Feature | How |
|---|---|
| Popup | Toolbar button or <kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>B</kbd>: Issue / Bookmark / Doc / Task / Snippet / Inbox, prefilled from the page and your selection |
| Screenshots | "Visible area", or "Select area" / <kbd>Alt</kbd>+<kbd>Shift</kbd>+<kbd>S</kbd> to drag a rectangle; attached to the new issue |
| Doc clipping | Mozilla Readability + Turndown → Markdown (article, selection or just the link), draft or published |
| Right-click menu | Save link, create issue from selection / page, clip selection / page, screenshot an area |
| Address bar | Type `bt`, a space and a search |
| Side panel | My Work (open issues + tasks assigned to you) and search |

## Try it in Chrome

`npm install && npm run build`, then `chrome://extensions` → **Developer mode** → **Load unpacked**
→ choose `browser-extension/dist/chrome-mv3`. After a rebuild, click the reload icon on the card.

## Develop

```bash
cd browser-extension
npm install
npm run dev            # Chrome with the extension loaded, hot reload
npm run dev:firefox
```

Point it at a local Brainitive by pre-granting the host:
`BRAINITIVE_DEV_ORIGIN=http://127.0.0.1:8000 npm run dev`, then in the popup choose
**Self-hosted** and enter `127.0.0.1:8000`. The server needs Passport keys
(`php artisan passport:keys`).

```bash
npm run typecheck
npm test               # vitest + WXT's fake browser (tests/), also in CI
npm run build          # dist/chrome-mv3   (Chrome, Edge)
npm run build:firefox  # dist/firefox-mv3
npm run zip            # store uploads in dist/*.zip
npm run zip:firefox    # also writes the sources zip AMO asks for
```

## How it works

- **Sign-in:** OAuth 2.1 + PKCE in `identity.launchWebAuthFlow`. The extension registers
  itself on the server (`/oauth/register`) with the browser's redirect URL and asks for the
  `capture` scope. That token only works on `/api/ext/v1`: the server rejects it on `/mcp` and
  the CLI API. Tokens live in `storage.local`; refresh runs in the background worker, one
  at a time (refresh tokens are single use).
- **Permissions:** `activeTab` + `scripting` read the page only when you use the extension
  there. The hosted app's origin is a host permission. A self-hosted origin is requested at
  sign-in (`optional_host_permissions`). No content scripts run on every site.
- **API:** `lib/api.ts` ↔ `routes/api.php` (`ext.*`) → `app/Http/Controllers/Extension/`.

## Security notes

From the Codex CLI/extension review (v1.49.0). Tests in `tests/`.

- **Tokens stay in the background worker.** Popup, side panel and options page never get a
  token. They send `{type: 'api', method, args}` (`lib/client.ts`), and the background makes
  the call and returns the result. There is no `get-token` message. The background answers
  only this extension's own pages (`sender.id` plus the extension origin of `sender.url`,
  `lib/background-messages.ts`): content scripts and other extensions get "Not allowed".
  Pages read `account` (`{server}`) from storage, never `session`. Where the browser
  supports it, `storage.local` is limited to trusted contexts.
- **OAuth stays on the chosen origin.** The background checks the server itself (https, or
  plain http only on `localhost` / `127.0.0.1` / `[::1]`). The authorization, token and
  registration endpoints from discovery must be on exactly that origin (same scheme, host
  and port, no credentials in the URL). Otherwise sign-in stops before anything is sent.
  The stored token endpoint is checked again before every refresh.
- **No redirects with credentials.** Every request to the server uses `redirect: 'error'`.
  This covers discovery, registration, token, API and revoke requests, so a 307/308 can't
  replay a code, refresh token or `Authorization` elsewhere.
- **Sign-out revokes.** The session is cleared locally first, then
  `DELETE /api/ext/v1/logout` revokes the access token and its refresh token. If the server
  can't confirm (offline, or a server without that endpoint), the local sign-out still
  stands, and the sign-in screen says to remove the extension under *Profile → Connected
  apps*.
- **Refresh can't undo a sign-out.** Each sign-in or sign-out starts a new stored session
  generation, and session writes are serialized in the background. A refresh only stores
  its result if its generation is still current. Tokens it received too late are revoked.

## Publishing

| Store | Upload | Notes |
|---|---|---|
| Chrome Web Store | `brainitive-browser-extension-<v>-chrome.zip` | One-time $5 developer fee. Single purpose: "save web pages to your Brainitive workspace". Justify `activeTab`, `scripting`, `contextMenus`, `identity`, `storage`, `sidePanel` and the optional host permissions (self-hosted servers). Privacy policy: `https://www.brainitive.app/privacy` (section 9) |
| Edge Add-ons | the Chrome zip | Free |
| Firefox AMO | `…-firefox.zip` + `…-sources.zip` | Free. `data_collection_permissions` declares `websiteContent` (what you capture goes to your Brainitive server) |

Bump `version` in `package.json` for every store upload.

**Live:** [Chrome Web Store](https://chromewebstore.google.com/detail/brainitive/mnnkcbjdgphgiiapdfegpgdmjekofmjn)
(also installs in Edge). **Direct download (Firefox, until AMO lists it):** the Release workflow attaches
`brainitive-extension-chrome.zip` / `-firefox.zip` to the current GitHub release on every
push to `main`; the homepage (`#extension` in `Pages/Welcome.tsx`) links to
`releases/latest/download/…`.
