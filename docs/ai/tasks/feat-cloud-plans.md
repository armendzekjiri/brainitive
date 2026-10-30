# Cloud plans: limits foundation (public app) + billing (private) + website (private)

**Branch:** feat/cloud-plans-foundation (release/v1.46.0)
**Status:** in progress — foundation committed, parallel tickets running
**Started:** 2026-10-30
**Board card:** [BRAI-160](https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-433)
**Builds on:** [chore-relicense-bsl.md](./chore-relicense-bsl.md), old pricing in [feat-open-source-cloud.md](./feat-open-source-cloud.md)

## Goal
Paid plans for Brainitive Cloud, billed per user through Paddle, with limits that only exist on
Cloud. Self-hosted installs keep everything unlimited and never see billing code.

## Plans (user, 2026-10-30)

| | Free | Starter | Team | Business |
|---|---|---|---|---|
| Price per user | €0 | €3 / month or €20 / year | €5 / month or €50 / year | €15 / month or €150 / year |
| Members per workspace | 3 | 25 | unlimited | unlimited |
| Projects per workspace | 5 | 30 | unlimited | unlimited |
| Content (docs, issues …) | 500 MB | 5 GB | 10 GB | unlimited |
| Files / attachments | 3 GB | 50 GB | 100 GB (+ extra storage: shown as "coming soon") | unlimited |
| Version history | 7 days | last 5 forever, older 30 days | forever | forever |
| Extra | — | — | — | longer backups / restore, SLA, priority support, DPA |

- **Per user, per workspace:** a Free workspace has up to 3 members. Inviting the 4th asks for an
  upgrade, and the workspace then pays for all 4. Seats follow the member count (Paddle prorates).
- **Free workspaces:** at most 3 Free workspaces per owner. Membership in other workspaces is unlimited.
- **Projects:** there is no project archive today, so the limit counts all projects. If an archive
  is added later: archived projects get their own limit equal to the project limit (Free: 5 + 5).
- **Usage** (content + files) is measured nightly; content is an approximation (the tables are
  shared). Soft enforcement: warning at 90 %, creating new things blocked at 100 %.
- **Over a limit** (downgrade, failed payment, launch): nothing is deleted for **90 days**; the
  workspace keeps working, only *adding* (projects, members, uploads) is blocked until it's under
  the limit or upgraded. Version pruning starts only after those 90 days.
- **Launch:** limits are built now but stay **off** (`enforced = false`) until the user launches paid
  plans — the homepage promises 30 days' notice.

## Architecture: three repos

| Repo | Visibility | Owns |
|---|---|---|
| `brainitive` (this) | public, BSL | Limit **contract** + checks at every entry point, usage measurement, version pruning, upgrade dialog, a "Plan & billing" nav slot. Default binding = unlimited. |
| `armendzekjiri/brainitive-cloud` | private | Laravel package `brainitive/cloud`: plan table, real `Entitlements`, Paddle (Cashier Paddle, Workspace = billable), checkout, webhooks, seat sync, Plan & billing page, admin "Customers" page, later licence keys + extra storage. |
| `armendzekjiri/brainitive-website` | private | www.brainitive.app marketing: home, pricing, features, blog (Astro, static). nginx serves its paths; everything else goes to the app (no URL changes). |

### How the private package gets into the Cloud build
- Binding order: package providers register **before** the app's, and `EntitlementsServiceProvider`
  uses `singletonIf`, so the package's `Entitlements` wins when it is installed.
- Inertia pages named `Cloud/…` resolve from `vendor/brainitive/cloud/resources/js/Pages/**`
  (`resources/js/app.tsx`; the glob is empty without the package). Tailwind scans that folder too.
- Composer install on the Cloud servers (path / VCS repository without touching the public
  `composer.json` / `composer.lock`): decided in the package ticket together with the deploy script.
- Private repo CI checks out the public app, adds the package, runs its own tests + e2e.

## Public app changes
Code name **Entitlements**, never "Plan" — `App\Models\Plan` is the Gantt plan.

**Foundation (committed on this branch):** `app/Support/Entitlements/` — `Entitlements`,
`Limits`, `VersionRetention`, `UnlimitedEntitlements` (default), `ConfigEntitlements`
(`ENTITLEMENTS_DRIVER=config`, limits only for slugs with `ENTITLEMENTS_ONLY_SLUG_PREFIX`; e2e),
`LimitGate`, `LimitReached` (402 JSON / `flash.limit_reached`), `EntitlementsPayload` (shared prop
`entitlements`), `WorkspaceMembershipChanged`; `EntitlementsServiceProvider`, `config/entitlements.php`,
`workspace_usages` + `WorkspaceUsage` + `Workspace::usage()`; TS types `WorkspaceEntitlements`,
`LimitReachedPayload`; test `tests/Feature/Entitlements/EntitlementsFoundationTest.php`.

**Original sketch (names as built above):**
- `app/Support/Entitlements/`:
  - `Entitlements` (interface): `limits(Workspace): PlanLimits`, `freeWorkspacesPerOwner(): ?int`,
    `billingUrl(Workspace): ?string`, `enforced(): bool`.
  - `PlanLimits` (value object, `null` = unlimited): `name`, `members`, `projects`, `contentBytes`,
    `fileBytes`, `VersionRetention` (`keepLatest`, `keepDays`), `graceUntil`.
  - `UnlimitedEntitlements` — the default binding (self-hosted, tests, e2e).
  - `PlanGate`: `ensureCanAddMembers`, `ensureCanCreateProject`, `ensureCanCreateWorkspace`,
    `ensureCanStoreFiles` → throws `PlanLimitReached` (rendered as 402 JSON / Inertia error with
    the limit, usage and billing URL).
  - `WorkspaceUsage` + `usage:measure` command (nightly): members, projects, content bytes, file bytes
    → `workspace_usages` table.
  - `versions:prune` (nightly): `PageVersion` / `PlanVersion` per `VersionRetention`, after grace.
  - Event `WorkspaceMembershipChanged` (the package syncs Paddle seats from it).
- Entry points that call `PlanGate`: workspace invite + `Workspace::addUser` (invitation accept,
  helpdesk / portal flows that add members), `WorkspaceController::store`,
  `ProjectController::store`, template instantiation, Trello / Jira / Notion imports, project
  archive restore, move project between workspaces; a middleware on upload routes for files.
- Frontend: shared prop `plan` (current workspace: name, limits, usage, billingUrl), `UpgradeDialog`
  opened on a 402, usage bars in workspace settings, "Plan & billing" nav item when `billingUrl`.
- Self-hosted `/`: login / dashboard instead of the marketing page (once the website repo is live).
- Privacy: Paddle.js loads only on the billing page after a click; Paddle goes into the privacy
  policy, sub-processors and `docs/legal/data-inventory.md` (Cloud only).

## Test plan
- PHPUnit: each gate with a fake `Entitlements` (allowed / blocked / grace / not enforced), usage
  measurement, version pruning, default binding = unlimited.
- e2e: Free workspace → inviting a 4th member opens the upgrade dialog (fake plan via an e2e-only
  binding); self-hosted shows no plan UI.

## Open questions
- Yearly prices for Team and Business (suggestion: 2 months free → €50 / €150 per user / year).
- Paddle account: the user signs up (sandbox for dev.brainitive.app, live for production).
- Lawyer: Terms for paid plans, Paddle as merchant of record.
