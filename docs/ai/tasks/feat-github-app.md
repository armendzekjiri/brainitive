# GitHub App connect + merge transitions (BRAI-61)

**Branch:** feat/github-app (from release/v1.42.0)
**Status:** done (on feat/github-app, waiting for the release merge)
**Started:** 2026-10-26
**Board card:** BRAI-61 (board item 163) — contract: [release-v1.42.0.md](./release-v1.42.0.md) "Ticket 4"

## Problem / goal
Connecting GitHub meant creating a connection, then pasting a webhook URL + secret into every
repository by hand. The user's wish: "login automatically with github and get from there info and
not with webhooks manually". Goal: **Connect GitHub → GitHub's install screen → pick repositories →
done**, plus an optional per-board "primary PR merged → move the card" transition.

## Approach
- **One GitHub App per instance.** Instance admin → GitHub App → "Create GitHub App" posts GitHub's
  *App manifest* (permissions + events + URLs) to github.com; GitHub redirects back with a code that
  is converted (`POST /app-manifests/{code}/conversions`) into app id, slug, client id/secret,
  private key and webhook secret, stored encrypted in `github_apps` (one row). `GITHUB_APP_*` env vars
  override the stored app (`GithubAppConfig`).
- **App auth without a new dependency:** RS256 JWT signed with `openssl_sign` → installation access
  token (`POST /app/installations/{id}/access_tokens`), cached encrypted until 5 min before expiry.
- **Connect:** project admin → "Connect GitHub" → state (random, in the session, bound to project +
  user) → `github.com/apps/{slug}/installations/new?state=` → the app has *request user authorization
  during installation* on, so GitHub returns to `/github/app/setup` with `code`, `installation_id`,
  `state`. We exchange the code for a user token (never stored) and check `GET /user/installations`
  contains the installation — a pasted foreign `installation_id` is refused. The installation's
  repositories are cached on `github_installations`; the project then ticks the repositories it
  follows (`github_repositories`, `repo_id` unique → a repository maps to one project; one
  installation can serve several projects).
- **One app webhook** `POST /api/webhooks/github-app`: HMAC-SHA256 over the raw body with the app's
  webhook secret, 5 MB cap, dedupe by `X-GitHub-Delivery` (`github_app_deliveries`, unique), queued
  `ProcessGithubAppDelivery`. Installation events update `github_installations` (deleted / suspended /
  unsuspended, repositories added / removed); everything else is routed by installation id +
  repository id to the project's connection and handed to the existing `DevelopmentSync`.
  Losing access marks the connection (`last_error`, `access_lost_at`) and notifies
  `github_sync_failed` to the project admins once per loss.
- **Refresh** (project admin): queued `RefreshGithubConnection` re-reads the installation's
  repositories and the open linked PRs (≤ 50) through the installation token. A rate limit
  (403/429 with `x-ratelimit-remaining: 0` or `retry-after`) stores `rate_limited_until`; refresh is
  refused until then. Tokens never leave the server.
- **Merge transitions:** `boards.settings.github.merge_column_id` (off by default), set in the
  project's GitHub card per board. The primary PR of an issue = the PR link marked `primary`, else the
  first linked PR. When the primary PR is processed as merged: move once (marker in the link's meta),
  never out of a done column (never reopens), only within the issue's board, at the bottom of the
  column — a normal model save, so history (`ItemObserver`), outgoing webhooks and automation rules
  see it. History says "GitHub" (`ActivityLog::via()` adds `metadata.via = github`).
- **Legacy:** webhook-secret connections keep working unchanged (`mode = webhook`); the card shows
  "Manual webhooks (legacy)" and "Switch to the GitHub App" (same connect flow; the connection and its
  links are kept, the old secret is rotated so the manual hooks stop).
- **e2e:** `GITHUB_APP_FAKE=true` (honoured only with `APP_ENV=e2e`) configures a fake app and
  `Http::fake`s github.com / api.github.com, and "Connect GitHub" jumps straight to the setup callback.

## Changes
- Migration `2026_10_31_140000_create_github_app_tables`: `github_apps`, `github_installations`,
  `github_repositories`, `github_app_deliveries`; `github_connections` + `mode`,
  `github_installation_id`, `access_lost_at`, `rate_limited_until`, `last_refreshed_at`;
  `item_dev_links` + `primary`.
- Models: `GithubApp`, `GithubInstallation`, `GithubRepository`, `GithubAppDelivery`; updates to
  `GithubConnection`, `ItemDevLink`.
- `app/Support/Github/`: `GithubAppConfig`, `GithubApi`, `GithubRateLimited`, `AppManifest`,
  `AppDeliveryProcessor`, `ConnectionAccess`, `MergeTransitions`, `FakeGithub` (e2e);
  `DevelopmentSync` keeps link meta and runs merge transitions; `ItemDevelopment` marks the primary PR.
- Jobs `ProcessGithubAppDelivery`, `RefreshGithubConnection`; notification `GithubSyncFailedNotification`.
- Controllers: `Admin\GithubAppController`, `GithubAppWebhookController`, `ProjectGithubController`
  (connect / setup / repositories / transitions / refresh / primary PR).
- `ActivityLog::via()`; `ItemDetail` history shows "GitHub".
- Frontend: `Pages/Admin/GithubApp.tsx` (+ AdminShell tab), `GithubIntegrationCard.tsx` rework,
  `DevelopmentPanel.tsx` primary PR, types section at the end of `types/index.d.ts`.

## Test plan
PHPUnit `tests/Feature/Github/GithubAppTest.php` (+ existing `GithubDevelopmentTest`): manifest
callback, setup callback state + ownership, foreign installation / repository rejected, cross-project
mapping refused, app webhook signature + dedupe + routing, uninstall / suspend / repository removal +
notification, merge transition (once, close without merge, secondary PR, never reopens, history),
refresh + rate-limit backoff, legacy connections. e2e `e2e/tests/github-app.spec.ts`.

## Routes
- `GET admin/github-app` (`admin.github-app.show`), `POST admin/github-app/manifest`
  (`admin.github-app.manifest`, JSON), `GET admin/github-app/callback` (`admin.github-app.callback`),
  `DELETE admin/github-app` (`admin.github-app.destroy`) — instance admins.
- `GET /github/app/setup` (`github.app.setup`, auth) — GitHub's setup + OAuth callback URL.
- `POST /api/webhooks/github-app` (`webhooks.github.app`, stateless, HMAC) — the app's webhook.
- Project (admins): `POST …/github/app/connect`, `PUT …/github/repositories`, `PUT …/github/transitions`,
  `POST …/github/refresh`; issue: `PATCH …/boards/{board}/items/{item}/development/primary`.

## Env vars (all optional)
`GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`,
`GITHUB_APP_PRIVATE_KEY` (PEM, `\n` escapes allowed, or a path to the .pem file),
`GITHUB_APP_WEBHOOK_SECRET` — when `GITHUB_APP_ID` is set they replace the app created in the admin
console. `GITHUB_APP_FAKE` is for e2e only (ignored unless `APP_ENV=e2e`).

## Self-hosting / production setup (once per instance)
1. `APP_URL` must be the public https URL (GitHub calls the webhook and sends people back to it),
   and a queue worker must run (deliveries and refreshes are queued jobs).
2. Sign in as an instance admin → **Admin → GitHub App**.
3. App name: must be unique on all of GitHub (e.g. "Brainitive" or "Brainitive <your company>").
   Organization: leave empty for your personal account, or the org that should own the app.
   Keep **Any GitHub account can install it** on when users connect repositories of their own
   accounts (Brainitive Cloud); a single-company instance can turn it off.
4. **Create GitHub App on GitHub** → GitHub shows the prefilled app → **Create GitHub App** → you land
   back on Admin → GitHub App ("GitHub App … created"). The key and secrets are stored encrypted.
5. Optional on GitHub (app settings): upload a logo. Check that *Request user authorization (OAuth)
   during installation* is on, the callback URL is `<APP_URL>/github/app/setup` and the webhook URL is
   `<APP_URL>/api/webhooks/github-app` (active).
6. Project admins: project settings → Integrations → GitHub → **Connect GitHub** → pick the account and
   repositories on GitHub → back in Brainitive tick the repositories the project follows. Optional:
   Merge transitions per board. Projects on manual webhooks see **Switch to the GitHub App** (links are
   kept; the old secret is rotated, so delete the old repository webhooks).

Each instance (production, dev.brainitive.app, every self-hosted one) needs its own app, because the
URLs differ. Alternative to step 2–4: create the app by hand on GitHub with the permissions / events /
URLs shown on Admin → GitHub App, then set the `GITHUB_APP_*` env vars (and `php artisan config:cache`).

## Notes for the docs (lead)
- KNOWLEDGE: two GitHub modes. Manual webhooks (`/api/webhooks/github/{uuid}`, per-project secret) vs
  the GitHub App (`/api/webhooks/github-app`, app secret, routed by installation + repository id;
  `github_repositories.repo_id` unique = one project per repository). Installation ownership is proven
  by OAuth during install + `GET /user/installations`; the setup state lives in the session.
  The GitHub App is separate from GitHub social login (`services.github`): never widen login scopes.
  Installation tokens are cached encrypted (`github-app:installation-token:*`), never shown.
  `ActivityLog::via('github', fn)` marks history entries made by an integration (`metadata.via`,
  `user_id` null) — the history shows "GitHub".
- ARCHITECTURE: `app/Support/Github/` now also has `GithubAppConfig`, `GithubApi`, `AppDeliveries`,
  `ConnectionAccess`, `ConnectionRefresh`, `MergeTransitions`, `AppManifest`, `FakeGithub` (e2e);
  `Admin\GithubAppController` + `Pages/Admin/GithubApp.tsx`; `GithubAppWebhookController`;
  jobs `ProcessGithubAppDelivery`, `RefreshGithubConnection`.
- CONVENTIONS (boards): merge transitions live in `boards.settings.github.merge_column_id`.
