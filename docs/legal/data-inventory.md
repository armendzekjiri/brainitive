# Personal-data inventory (Records of processing — working draft)

> **Status:** engineering inventory written from the code on 2026-10-26 (BRAI-156, release v1.44.0).
> It is the factual basis for the privacy policy, the sub-processor list and the DPA. It is **not**
> legal advice; a qualified lawyer must confirm the legal assessments (see [README](README.md)).
> **Rule:** every new integration, processor, cookie/storage key or retention job → update this file,
> `resources/js/Pages/Privacy.tsx`, `Legal/Subprocessors.tsx` and `Cookies.tsx` in the same PR.

Roles: **C** = we (the operator of Brainitive Cloud) are controller; **P** = we are processor for the
customer (workspace owner), who is controller (Art. 28 GDPR, `/legal/dpa`). Legal bases refer to
Art. 6 (1) GDPR: (b) contract, (c) legal obligation, (f) legitimate interest, (a) consent.

Brainitive Cloud facts given by the operator (2026-10-26): run by an individual (sole proprietor),
name/address not published; contact hello@brainitive.app; app server, backups (separate server) and
Umami (separate server, self-managed) at **OVHcloud**; e-mail delivery via **MXroute**. Data-centre
locations, log/backup/analytics retention: unknown → `config/legal.php` / `LEGAL_*` env.

## 1. Accounts and authentication — C, (b) / (f)

| Data | Where | Retention / deletion |
|---|---|---|
| name, email, password (bcrypt, `hashed` cast), avatar, bio, phone, timezone, preferences, `email_verified_at`, `last_login_at`, `suspended_at`, `terms_accepted_at` + `terms_version` (new) | `users` | until account deletion (hard delete) |
| provider ids `google_id`, `github_id`, `microsoft_id` (`{tid}:{oid}`) — no provider tokens or avatars | `users` | with the account |
| session: `user_id`, `ip_address`, `user_agent`, payload, last activity | `sessions` (driver `database`; Docker: redis) | lifetime 120 min, GC lottery |
| remember-me token | `users.remember_token`, cookie `remember_web_*` | framework default ≈ 400 days; social logins always "remember" |
| password reset tokens | `password_reset_tokens` | 60 min; **new:** `auth:clear-resets` daily |
| rate-limit keys (`email|ip`, `oauth-register:<ip>`) | cache store | minutes |
| admin audit log incl. `ip_address` | `admin_audit_logs` | **no automatic deletion** (open item) |
| MCP request log: IP + UA per `/mcp` request (middleware marked TEMPORARY) | Laravel log | log file not rotated by default (open item) |

- No 2FA. Email verification on by default (`require_email_verification`).
- OAuth scopes: Google `openid profile email` (Socialite default); GitHub `read:user user:email`;
  Microsoft OIDC + PKCE `openid profile email User.Read` (id_token only, Graph never called).
- Sign-up: the Terms notice (no checkbox, nothing pre-ticked) on Register and Login (OAuth); the
  accepted version is stored (`LegalInfo::termsAcceptance()`). **Gap:** the homepage sign-in modal
  (Welcome.tsx) has OAuth buttons without the notice — out of this ticket's scope.

## 2. Workspace content — P (towards the account holder: C for the account, (b))

Workspaces, projects, boards/issues (+ history `activity_logs`, no IP, no prune), pages + versions,
whiteboards (Excalidraw; `page_collab_states` Yjs), comments, attachments (public disk, UUID names —
reachable by URL without login), snippets, bookmarks (server fetches page metadata with UA
`Brainitive/1.0`), captures (no prune), plans, task lists, time entries, billing (clients: name,
email, address, VAT ID, notes; billing settings incl. IBAN/BIC; frozen report snapshots), workload,
search index (`search_entries`, Postgres FTS — no external search service).
Soft-deleted rows are **never force-deleted** (open item: purge job / documented trash period).

## 3. Calendars and meetings — P/C (user's own data: C, (b))

`calendar_connections` (encrypted `access_token`, `refresh_token`, `credentials` incl. CalDAV app
passwords and ICS URLs; disconnect nulls tokens + soft-deletes). Google scopes `calendar`,
`calendar.events`, `tasks` (offline); Outlook `offline_access User.Read Calendars.ReadWrite`;
iCloud/CalDAV; ICS fetched server-side (SSRF-guarded). Calendar feed: `token_hash` + encrypted token,
fetch count, no IP. Meetings: attendees `{email,name,user_id}`; invitations sent **by the provider**.
Recipients: Google / Microsoft / Apple (only when connected).

## 4. Web push — C, (b)

`push_subscriptions`: endpoint, keys, `user_agent`, `device_label`, success/error timestamps.
Allowed services: FCM (Google), Mozilla, Apple, Windows (WNS). Payloads encrypted (Web Push).
Expired subscriptions pruned 30 days after `expired_at`.

## 5. Email — C (service mails) / P (helpdesk, lead and portal mails)

Outgoing via SMTP (Cloud: MXroute; config also supports ses/postmark/resend; admin-console SMTP
password encrypted). Inbound helpdesk: Postmark / Mailgun forwarding webhooks, or IMAP polling every
minute (mailbox passwords encrypted). `helpdesk_inbound_emails` pruned after 180 days.

## 6. AI — P (workspace's instruction)

No platform AI provider and no platform key. Per workspace: OpenAI-compatible endpoint + encrypted key
(`workspaces.settings`). Sent: selected editor/card text (≤50k chars), project name/description,
project health snapshot (titles, keys, milestones; assignee only as boolean), meeting notes text.
MCP (Passport): tokens 30 d access / 60 d refresh / 1 y PAT; scopes `mcp:use`, `capture`;
`mcp_requests` pruned after 7 days; **new:** `passport:purge` daily.

## 7. GitHub App / webhooks — P

Encrypted app secrets/keys; installations, repositories, dev links (author, title, url, state); raw
deliveries pruned after 14 days. Host api.github.com.

## 8. Customers' public pages — P (customer = controller)

| Surface | Data | Notes |
|---|---|---|
| Helpdesk widget, `/support/{slug}`, `/portal/tickets/{uuid}` | contact email + name, subject, message, attachments, field values, email metadata | no IP/UA stored; operator line + privacy line (new) |
| Lead forms `/f/{slug}` | answers, mapped name/email/phone/company, utm, referrer, **consent_at + consent_text (new)** | analytics events `lead_form_events` (session id, type, step; no IP/UA) pruned after 91 days; the visit id is now **memory-only** (was sessionStorage) |
| Feedback portal `/feedback/{slug}`, client portal `/client/{slug}` | guest identity (email, name, last seen), posts, votes, follows, decisions (name+email kept for audit), comments | guest login tokens hashed, 30 min, pruned 1 day after expiry; identities not pruned |
| Share links `/s/`, plans `/share/plans/`, release notes `/r/`, `/changelog/` | read-only content; `view_count`, `last_viewed_at` | no visitor data |
| Outgoing webhooks / lead webhooks | event payloads to customer-chosen URLs | deliveries pruned after 30 days |

Guest sign-in (`/guest/login`) is platform-level (one identity across portals) → **C**, (b).

## 9. Realtime / live

Realtime server (Hocuspocus + live hub) keeps presence `{id,name,avatar}` in memory only; logs only a
startup line. Yjs state persisted via the app. nginx forwards X-Forwarded-For (not stored by the
realtime server; nginx access logs: see logs).

## 10. Device storage (§ 25 TDDDG) — all strictly necessary (§ 25 (2) no. 2)

Cookies: `<slug(APP_NAME)>-session` (120 min), `XSRF-TOKEN`, `remember_web_*`, `sidebar_state` (7 d).
localStorage / sessionStorage / IndexedDB / Cache Storage: full list on `/cookies` (Cookies.tsx).
New keys: `brainitive-embeds-allowed` (two-click embeds), `umami.disabled` (analytics opt-out).
The old cookie banner and its `brainitive_cookie_consent` key were removed (they asked consent for
analytics/marketing cookies that never existed and named Google Analytics, which isn't used).

## 11. Analytics — C, (f)

Self-hosted Umami at stats.novabrainhub.de (operator's own OVH server). Loaded only on
brainitive.app/www (`App\Support\Legal\Analytics`), **new:** never on customer pages or secret-bearing
paths (`/f`, `/support`, `/widget`, `/portal`, `/feedback`, `/client`, `/guest`, `/s`, `/r`,
`/changelog`, `/share`, `/invite`, `/reset-password`, `/verify-email`, `/calendar/feed`, `/oauth`,
`/offline`), checked server-side and in a `data-before-send` filter for client-side navigation;
`data-do-not-track`, `data-exclude-search`, `data-exclude-hash`; opt-out on `/cookies`.
Umami by design: no cookies, no IP stored, daily-salted session hash, geo from IP. **To verify on the
running instance:** Umami version (before-send support), web-server log settings, data retention.

## 12. Logs, errors, backups, hosting

- Laravel log: `single` (no rotation) by default; Docker `stderr`. No Sentry/Bugsnag/Flare.
- `failed_jobs` (payloads): **new** `queue:prune-failed --hours=720`.
- nginx access logs: retention unknown → `LEGAL_SERVER_LOG_RETENTION`.
- Backups: separate OVH server (operator); frequency/retention unknown → `LEGAL_BACKUP_RETENTION`.
  README claims "backups included" for Cloud — matches the operator's statement, policy unverifiable
  from the repo.
- Production + staging (dev.brainitive.app) on the same OVH server (162.19.229.57 per CI docs).
- Self-hosted (Docker): no telemetry; the only default outbound call is the hourly GitHub star count
  (`GITHUB_STARS=true`, server → api.github.com, no user data). Self-hosted installs never load our
  Umami and show their own operator's legal data only.

## 13. Third-party requests from the browser

| Before | Now (BRAI-156) |
|---|---|
| Google Fonts (Reenie Beanie) on every page | self-hosted TTF (OFL, `resources/fonts/reenie-beanie/`) |
| esm.sh: Excalidraw fonts | copied into `public/build/excalidraw-assets` at build, `EXCALIDRAW_ASSET_PATH` set |
| jsDelivr KaTeX CSS | bundled `katex/dist/katex.min.css` |
| cdn.quilljs.com (helpdesk-embed.blade.php) | file was unused → deleted |
| Google s2 favicon fallback for bookmarks | no fallback; generic icon |
| YouTube-nocookie, Figma, X (platform.twitter.com) embeds | two-click: load only after "Load content" |
| Remaining (by user choice): bookmark favicons/OG images from the bookmarked site; lead-form logo/cover from an https URL the customer enters | documented |

## 14. Data-subject rights — what exists

- Rectification: profile, workspace settings. Erasure: Profile → Delete account (**fixed**: no longer
  cascades other people's work; blocked while owning a shared workspace; uses `UserDeletion`).
  OAuth-only accounts must reset a password first to delete (open item).
- Export: project export ZIP (excludes members, tickets, leads, time entries, history). **No
  account-level "download my data"** → requests by e-mail (open item).
