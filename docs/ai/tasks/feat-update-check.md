# Self-hosted: "new version available" notice for instance admins

**Branch:** feat/update-check (from release/v1.48.0)
**Status:** done
**Started:** 2026-10-31
**Board card:** BRAI-433

## Problem / goal
Self-hosters don't notice new releases. Tell instance admins when a newer Brainitive release is out —
awareness only, no self-update. Regular users never see it; Brainitive Cloud and dev.brainitive.app don't
check at all.

## Approach
- **One source of truth for the running version: the newest `CHANGELOG` entry in
  `resources/js/components/HelpModal.tsx`.** It already is the app version by convention (the release
  workflow tags it via `.github/scripts/changelog-release.mjs`). `App\Support\AppVersion::current()`
  reads that file's first `version: 'vX.Y.Z'` after `const CHANGELOG = [` (first 64 KB only, memoised per
  process). The file ships in the Docker image (`COPY . .` in the vendor stage, `resources/` is never
  removed) and in every manual (git) install, so no build step, generated file or second version
  string is needed — nothing for the release workflow to keep in sync. `config('app.version')` (no env
  var; already read by `ProjectArchive::appVersion()`) stays as a runtime override, used by tests.
  `ProjectArchive::appVersion()` now uses `AppVersion` too (its hard-coded `1.41.0` was stale).
- **Check:** `App\Support\Updates\UpdateCheck::run()` — GET
  `https://api.github.com/repos/armendzekjiri/brainitive/releases/latest` (fixed URL: plain `Http`
  like `GithubStars`; `OutboundUrl` is for user-supplied URLs), 5 s timeout, `User-Agent: Brainitive`,
  `Accept: application/vnd.github+json`, no token, no instance data. `releases/latest` already skips
  drafts and pre-releases; we also refuse `draft` / `prerelease` true and any tag that isn't plain
  `vX.Y.Z` (no `-rc`). Failures (offline, 403 rate limit, 5xx, bad JSON) → `Log::info`, keep the last
  good values, record `failed_at`. Never in e2e (`APP_ENV=e2e` refuses to fetch).
- **Schedule:** `brainitive:check-update` daily at 05:05 in `routes/console.php` (no request-time HTTP).
  The command prints the result; `--clear` forgets the stored result; hidden `--fake-latest=X.Y.Z`
  records a release without calling GitHub, honoured only with `APP_ENV=e2e`.
- **Storage:** `instance_settings.settings.update_check` = `{latest_version, release_url, published_at,
  checked_at, failed_at}` (existing singleton row, durable — a Redis cache flush would hide the notice
  until the next day; `SettingsController::update` merges, so admin saves keep it).
- **Who sees what:**
  - Admin console → Overview: an "Updates" card (running version, latest release, published, last
    check, links to the release notes and the upgrade docs Docker / manual) and, when newer, a
    non-dismissible "Brainitive vX available" alert at the top.
  - Every app page for instance admins: a thin dismissible strip under the top bar
    (`UpdateNoticeBanner`, shared prop `updateNotice`, null for everyone else). Dismissed per version in
    `users.onboarding_state.update_notice_dismissed` via `POST admin/update-notice/dismiss`
    (`instance.admin`); a newer release shows it again.
- **Settings:** `BRAINITIVE_UPDATE_CHECK` (`config('app.update_check')`): empty = on for self-hosted, off
  on Brainitive Cloud / dev (`LegalInfo::isCloud()`: `LEGAL_CLOUD` or an `APP_URL` on a brainitive.app
  host); `true` / `false` force it. Off in PHPUnit (phpunit.xml, tests turn it on); e2e sets `true`.
- Upgrade docs: `docs-site/self-hosting/upgrading.md` exists → linked (`#docker`, `#manual-install`).

## Changes
- Migrations: none (JSON columns)
- Backend: `app/Support/AppVersion.php`, `app/Support/Updates/UpdateCheck.php`,
  `app/Console/Commands/CheckForUpdate.php`, `app/Http/Controllers/Admin/UpdateNoticeController.php`,
  `Admin/OverviewController` (`updates` prop), `HandleInertiaRequests` (`updateNotice`), `routes/web.php`,
  `routes/console.php`, `config/app.php`, `ProjectArchive::appVersion()`, `phpunit.xml`
- Frontend: `components/admin/UpdateNoticeBanner.tsx`, `Layouts/AppLayout.tsx`, `Pages/Admin/Overview.tsx`,
  `types/index.d.ts`
- Docs: `.env.example`, `.env.docker.example`, `docs/self-hosting.md`, `docs-site/self-hosting/configuration.md`,
  ARCHITECTURE (admin console, self-hosting), KNOWLEDGE

## Tests
- PHPUnit `tests/Feature/Admin/UpdateCheckTest.php`: newer / same / older, pre-release + draft + `-rc`
  tag ignored, GitHub down / 403 → silent + keeps last value, disabled → no HTTP, Cloud default off,
  scheduled in console routes, command output, AppVersion reads HelpModal, non-admins get no data,
  dismiss per version.
- e2e `e2e/tests/update-check.spec.ts`: a fresh account promoted to instance admin sees
  "Brainitive v… available" in the admin console + the strip; dismiss persists across reloads until a
  newer release is recorded; the member sees neither.

## Open questions / follow-ups
- dev.brainitive.app / brainitive.app: off automatically (cloud hosts); nothing to set.
