# End-to-end tests (Playwright, BRAI-96)

Real-browser journeys on top of PHPUnit, with several users at once (owner, member, newly
invited people). They run in CI on every push (Chromium, against a fresh Postgres app) and nightly
in Firefox and WebKit. A crawler also clicks every button.

```bash
npx playwright test -c e2e --project=chromium # the journeys in Chromium (what CI runs per push)
npx playwright test -c e2e --project=crawl    # + every button / command (runs the journeys first)
npx playwright test -c e2e tests/boards.spec.ts --project=chromium
npm run e2e:ui                                # watch it live: browser, timeline, every click (like Cypress)
npx playwright test -c e2e --project=chromium --headed --workers=1   # a real browser window
npx playwright test -c e2e tests/boards.spec.ts --debug              # step through click by click
npx playwright show-report e2e/playwright-report/8010                # last run's report (traces, videos)
E2E_PORT=8021 npx playwright test -c e2e …    # a second run in parallel (own server + DB)
```

## How it works
- **Database: PostgreSQL**, as in production. Every local run starts its own app (`php artisan
  serve` on `E2E_PORT`, default 8010) on its own database, `brainitive_e2e_<port>`, on the local
  Postgres. Global setup creates it, connecting over the Unix socket when there is one. CI uses
  its Postgres service. `php artisan e2e:reset` wipes and seeds that database first. It refuses
  anything but `APP_ENV=e2e` plus a database whose name contains `e2e`, so your `.env` database
  is never touched.
- **Seed** (`database/seeders/E2eSeeder.php`):
  - users: *Erin Owner* `e2e-owner@example.test` (workspace + project admin) and *Max Member*
    `e2e-member@example.test` (workspace member, project editor), both with password
    `e2e-password`;
  - workspace `e2e-workspace`, project `website` (key `WEB`), board `main-board`;
  - issues WEB-1 "Homepage hero", WEB-2 "Pricing page copy" (assigned to Max), WEB-3
    "Checkout bug" (In Progress, assigned to Erin).
- **Sessions.** Every worker signs both users in over HTTP for itself (worker fixtures in
  `fixtures.ts`). Laravel's one-time flash data belongs to a session, so sharing one across workers
  lost validation errors and toasts. Specs run as the owner; `memberPage` is a second session as
  the member. `tests/auth.setup.ts` still saves a shared login for the crawler.
- **Mail.** Local runs write outgoing mail to `storage/e2e/mail-<port>.log`. `mailLink(address,
  /pattern/)` (`mail.ts`) returns a link from the newest mail to that address, used to follow
  invitations and verification mails.
- **Every button** (`crawler.ts`, `tests/crawl.spec.ts`, project `crawl`). It visits every page
  reachable through links and clicks every control, plus each menu item and dialog button behind
  it (form dialogs get test values and are submitted), and every command-palette command. Any
  page error, 5xx or unclickable control fails it. Deleting, signing out and revoking are
  answered with a reload instead of reaching the server, so the data stays. It writes
  `button-crawl-*.json` per run.
- **Isolation.** Tests run fully in parallel on one database. Create your own data with `uid()`
  names, and never rename, re-password or delete the seeded users, workspace, project or board.
- **Page errors.** An uncaught page error fails the test (the auto fixture in `fixtures.ts`).
- **Remote.** `E2E_BASE_URL=https://dev.brainitive.app E2E_HTTP_USER=… E2E_HTTP_PASSWORD=…`
  runs against a deployed server, with no local server and no reset.

## Writing tests
- Import `test`, `expect`, `uid` and `urls` from `../fixtures`.
- Prefer roles and labels (`getByRole`, `getByLabel`, `getByText`) over CSS classes.
- A journey that finds a real app bug gets `test.fixme()` plus a comment and a board card. The
  suite stays green, and the bug stays visible.
- On failure, the trace, screenshot and video land in `e2e/test-results/`
  (`npx playwright show-trace …`). CI uploads them as an artifact.
