# Release v1.42.0 — Work with clients (BRAI-60, BRAI-73, BRAI-80, BRAI-61)

## Goal (the user's choice, 2026-10-26)
"Work with clients (Recommended)": a public feedback portal with voting, a client portal with
version-specific approvals, internal document review, and GitHub connected with one login instead of
hand-made webhooks (the user's comment on BRAI-61: "maybe login automatically with github and get from
there info and not with webhooks manually").

Process rule: agents run **focused tests only** (their own + directly affected PHPUnit classes and e2e
specs, plus pint, tsc, build). The lead runs the full gate once, after asking the user.

## Tickets, branches, ownership

| # | Card | Branch | Owns |
|---|---|---|---|
| 1 | BRAI-60 Public feedback portal | `feat/feedback-portal` | `feedback_*` tables, `app/Support/Feedback/*`, `Feedback*Controller`, `Pages/Feedback/*` (public) + `Pages/Projects/Feedback*` (team), its section of `routes/portal.php` + its team routes file `routes/feedback.php` |
| 2 | BRAI-73 Client portal | `feat/client-portal` | `client_portal*` / `client_deliverable*` tables, `app/Support/ClientPortal/*`, `ClientPortal*Controller`, `Pages/ClientPortal/*` (client) + `Pages/Projects/ClientPortal*` (team), its section of `routes/portal.php` + `routes/client-portal.php` |
| 3 | BRAI-80 Document review | `feat/document-review` | `page_reviews`, `page_review_reviewers`, `page_comments`, `pages.review_required`, `app/Support/PageReviews/*`, review UI in `Pages/Pages/Show.tsx` + `components/page-review/*`, publish enforcement in `PageController`/`Page` |
| 4 | BRAI-61 GitHub App connect | `feat/github-app` | `app/Support/Github/*` (App auth, installation API), `ProjectGithubController` + `GithubWebhookController` changes, `components/projects/GithubIntegrationCard.tsx`, instance-admin GitHub App setup, merge transitions per board |

Shared files: `types/index.d.ts` is append-only (one section per ticket at the end).
`Pages/Projects/Show.tsx` `quickLinks`: tickets 1 and 2 add ONE line each. `NotificationCatalog` types
are already reserved (below) — don't add categories. `docs/ai/*`, HelpModal changelog and Welcome are
merged by the lead; write your doc notes into your report instead.

## Foundation (on `release/v1.42.0`; build on it, don't redefine it)

**Guest identities — people outside the workspace (tickets 1 and 2):**
- Migration `2026_10_31_100000_create_portal_identities_tables`:
  - `portal_identities`: uuid, email (unique, lower-case), name, user_id (nullable, unique), email_verified_at, last_seen_at.
  - `portal_login_tokens`: sha256 `token_hash`, `return_to`, `expires_at`, `used_at`. Pruned daily.
- `App\Models\PortalIdentity` (Notifiable — **mail only**; `displayName()`, `toPortalArray()`, `findByEmail()`).
- `App\Support\Portal\PortalAuth`:
  - `current($request): ?PortalIdentity` — a verified Brainitive user is always themselves (identity linked
    by email, created on first use); otherwise the guest signed in through an emailed link (session key).
  - `sendLink($email, $name, $returnTo)` — one-time link (30 min), no enumeration, 5 per address / 15 min.
    Ticket 2 uses it for invitations too (return_to = the client portal path).
  - `safeReturnTo()` — only paths under `RETURN_PREFIXES` (`/feedback/`, `/client/`). Your public pages must
    live under these prefixes.
  - `shared($request)` — the `identity` prop for `PortalLayout`.
- Middleware alias **`portal.identity`**: needs a signed-in identity; puts it on
  `$request->attributes->get('portalIdentity')`; pages redirect to sign-in and come back; JSON → 401.
  It only means "this email is theirs" — **each portal checks its own access on top** (portal enabled,
  post visible, client invitation active, …).
- Routes `routes/portal.php` (required from web.php **outside** the auth group, before `/invite`):
  `guest.login` (form), `guest.login.store`, `guest.login.show` (confirm page — opening a link never spends it),
  `guest.login.consume`, `guest.logout`. **Never use `/portal/*`** — that prefix is the stateless,
  CSRF-exempt helpdesk ticket portal.
- UI: `Layouts/PortalLayout.tsx` (header with brand + viewer / "Sign in", noindex, footer) and
  `Pages/Portal/Login.tsx`. Type `PortalIdentityData`.
- e2e: `e2e/helpers/portal.ts` — `newGuest()`, `portalSignIn(page, guest, returnTo)` (follows the real mail
  through `e2e/mail.ts`). Use a context without a session (`guestContext(browser)`).
- Test: `tests/Feature/Portal/PortalAuthTest.php`.

**Notifications** — categories `reviews` ("Reviews & approvals") and `feedback` exist; reserved types:
`feedback_submitted` (1), `client_decision`, `client_commented` (2), `review_requested`, `review_decided`,
`review_commented` (3), `github_sync_failed` (4). Write `CatalogNotification` subclasses (see
`docs/ai/skills/add-notification.md`) and add each to `CatalogNotificationContractTest::notifications()`.
Mails to guests (`PortalIdentity`) are plain mail-only `Notification`s outside the catalog, like
`TicketContact` mail.

## Ticket 1 — BRAI-60 Public feedback portal (`feat/feedback-portal`)
Read the card (board item 162). MVP:
- Per project (project admins): enable, public slug (unique, `[a-z0-9-]`), title, intro. Off by default.
- Public `/feedback/{slug}`: list approved posts — sort Top / New, filter by status, search; post page
  `/feedback/{slug}/{number}`; roadmap view (Planned / Building / Released columns). Anonymous read.
- Signed-in identities (`portal.identity`): submit (title + plain-text body; similar posts shown while
  typing, from the search), vote / unvote (unique per identity + post; cached `votes_count` updated in the same
  transaction), follow / unfollow. Authors follow their own post automatically.
- Moderation (team page, project admins): new posts wait (`pending`) → approve / reject; set public status
  Open, Planned, Building, Released, Declined with an optional public note; merge a duplicate into another
  post (votes and followers are unioned without double counting, the old URL redirects); "Create card" /
  "Link card" — idempotent, same project only; the public side never shows card fields, comments,
  assignees.
- Notifications: `feedback_submitted` to project admins; a status change mails followers once (mail-only,
  deduplicated, with an unfollow link that works without signing in — signed URL).
- Moderator actions audited (who/when/what), throttles on submit/vote, input sanitized.
- Entry points: `quickLinks` line "Feedback" on the project page → the team page; the team page shows the
  public link + copy button.
- e2e: admin enables the portal → guest signs in, submits → admin approves, sets Planned → guest votes,
  sees the roadmap; a second guest's vote counts once; disabled portal → 404.

## Ticket 2 — BRAI-73 Client portal (`feat/client-portal`)
Read the card (board item 175). MVP:
- Per project (project admins): enable, slug, title, welcome text. Invite clients by email (name optional) →
  membership (`invited_by`, optional `expires_at`, `revoked_at`) + an invitation mail with a sign-in link
  (`PortalAuth::sendLink` with return_to `/client/{slug}`, plus a friendlier invitation mail). Revoke / re-invite.
  Optionally prefill the email from the project's billing `Client` (`projects.client_id` → `clients.email`).
- Deliverables = an explicit allowlist the team publishes: a **published page version** (`page_versions` row,
  rendered with `PageContentConverter::pageContentToHtml`, internal links to unshared content stripped or made
  plain text) or a **plan snapshot** (frozen JSON of the plan at publish time, hidden columns removed). Never
  drafts, never the whole project.
- Client side `/client/{slug}` (portal.identity + active membership for THIS portal): deliverable list with
  status; deliverable page with the content, Approve / Request changes (+ optional explanation), a comment
  thread. Each decision stores identity, time and the exact version. Approving twice is idempotent.
- Publishing a new version of the same page supersedes the previous deliverable: its decision is kept as
  history, the new one starts "Awaiting review" — approvals never carry over.
- Team page (`/workspaces/{w}/projects/{p}/client-portal`): settings, members, deliverables with decision
  history and comments; team replies are visible to the client only when sent as a reply (explicit button);
  internal notes stay internal.
- Notifications: `client_decision`, `client_commented` to the publisher + project admins; mail to the
  portal's clients when a deliverable is published or the team replies (mail-only).
- Revoked / expired membership → 403 on pages and attachments; another portal's ids → 404.
- Entry point: `quickLinks` line "Client portal".
- e2e: admin enables, publishes a page version, invites a guest → guest follows the mail, approves → admin
  sees the decision; admin publishes a new version → the approval is superseded; revoked guest is denied.

## Ticket 3 — BRAI-80 Document review (`feat/document-review`)
Read the card (board item 182). Pages have ONE draft (`draft_content` / `edit_revision`), versions only on
publish. MVP:
- "Request review" on a page's draft: pick reviewers (project members who can view the project), optional
  message → `page_reviews` row with an **immutable snapshot** of the draft (name + content + sha256 hash +
  `edit_revision`). Reviewers see exactly that snapshot (they may not otherwise see unpublished pages —
  grant view of the snapshot through the review, not the whole draft).
- Reviewer decisions: Approve / Request changes (+ comment). Review status = changes_requested if any,
  approved when all approved. Editing the draft after a request marks the review **stale** (hash differs) —
  shown clearly; the author can request a new review. Author can cancel.
- Inline comments on the snapshot: select text → comment; threads with replies, Resolve / Reopen.
  Anchor by **text quote** (exact quote + a few chars of prefix/suffix) so saved page HTML never changes;
  when the quote no longer matches the snapshot/draft, the thread shows as "outdated" (never attaches to
  wrong text). Page-level (unanchored) comments too.
- Project admins can set **"Review required before publish"** per page: publish / publish changes are
  refused server-side unless an approved, non-stale review matches the current draft hash (race-safe: lock
  the page row).
- Notifications: `review_requested` (reviewers, once), `review_decided` (author), `review_commented`
  (thread participants + author, deduplicated, no self-notify).
- Reviewer removed from the project → their pending decision drops out (status recomputed); deleted user →
  keeps the name on history.
- e2e: author requests review from a member → member comments on a selection and requests changes →
  author edits (review stale) → new review approved → publish allowed; with "review required", publish
  without approval is refused.

## Ticket 4 — BRAI-61 GitHub App connect (`feat/github-app`)
Read the card (board item 163) and the user's comment above. Today the admin creates a connection, then
pastes a webhook URL + secret into every repository by hand (`ProjectGithubController`,
`GithubWebhookController`, `app/Support/Github/DevelopmentSync.php`, `GithubIntegrationCard.tsx`). Goal:
**"Connect GitHub" → GitHub's install screen → pick repositories → done.** MVP:
- One **GitHub App per instance**. Instance admin console: "Create GitHub App" using GitHub's **App
  manifest flow** (one click; GitHub returns app id, slug, client id/secret, private key, webhook secret —
  store encrypted). Env vars (`GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_PRIVATE_KEY`,
  `GITHUB_APP_WEBHOOK_SECRET`, `GITHUB_APP_CLIENT_ID/SECRET`) override the stored app. Permissions:
  metadata, contents (read, for commits), issues, pull requests, deployments read-only; events push,
  create, delete, pull_request, deployment_status, installation, installation_repositories.
- Project admin → Integrations → "Connect GitHub": state bound to the session + project → GitHub install /
  select repositories → setup callback with `installation_id`; **verify the installation belongs to the
  user** (the app's "request user authorization during installation": exchange the code, check
  `GET /user/installations`). Then the project picks which of the installation's repositories it follows
  (one installation can serve several projects; a repository maps to one project).
- One app webhook endpoint: verify `X-Hub-Signature-256` with the app secret on the raw body, dedupe
  `X-GitHub-Delivery`, route by installation + repository to the project connection, reuse `DevelopmentSync`.
  Handle installation deleted / suspended / repositories removed → mark the connection, notify
  `github_sync_failed` to project admins.
- Installation tokens: JWT (RS256 via openssl, no new dependency unless needed) → cached installation token.
  "Refresh" re-fetches linked PR states (backoff on rate limits; never exposes tokens).
- **Merge transitions:** per board, optional (off by default) "When the primary linked PR is merged → move
  to column X". Primary PR = the first linked PR unless the user picks another on the card. Closed without
  merge does nothing; never reopen; item history records "moved by GitHub". Use the board's existing move
  action so positions/automations stay correct.
- Existing webhook-secret connections keep working; the card shows "Manual webhooks (legacy)" with a
  "Switch to the GitHub App" button.
- No outbound writes to GitHub, no GitHub Enterprise.
- Tests with signed fixture payloads + `Http::fake` for the GitHub API (JWT, installation token, repos,
  user installations); e2e: the card shows Connect GitHub when an app is configured (fake app in e2e env),
  the setup callback with a faked GitHub API connects repositories, a signed merge delivery moves the card.

## Lead checklist (after merging)
Changelog v1.42.0 + Welcome spotlight; KNOWLEDGE (guest identities vs members; /portal vs /guest),
ARCHITECTURE (new modules); production: create the GitHub App once (instance admin → GitHub App).
