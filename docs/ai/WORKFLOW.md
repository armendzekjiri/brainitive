# Workflow — How We Work

The shared playbook for humans and AI agents. Following it is what keeps four different
agents producing consistent, locatable work.

## Map of the knowledge base

| Doc | Owns |
|---|---|
| [`ARCHITECTURE.md`](./ARCHITECTURE.md) | Where code lives (module map + file pointers) |
| [`CONVENTIONS.md`](./CONVENTIONS.md) | How to write code (rules & patterns) |
| [`KNOWLEDGE.md`](./KNOWLEDGE.md) | Decisions & gotchas (the "why" + traps) |
| [`skills/`](./skills) | Step-by-step recipes for common tasks |
| [`tasks/`](./tasks) | One plan file per branch |

---

## The loop for every feature / fix

```
1. Branch        2. Plan + track      3. Build            4. Update docs       5. Gate & PR
   off        ─▶  tasks/<branch>.md ─▶ follow a skill  ─▶ ARCH/KNOW/CONV  ─▶  green + PR
   pre-release    + Brainitive card                       (+ move card → Testing)  into pre-release
```

### Releases: 3–4 tickets per release branch (since 2026-10-24)
We work in releases, not single PRs:

1. Pick 3–4 board tickets. Create **`release/vX.Y.Z`** from **`pre-release`**, named after the next
   version in the HelpModal changelog (new features → minor bump, fixes only → patch).
2. Create one branch per ticket **from the release branch** (`feat/…`, `fix/…`), each with its own
   `docs/ai/tasks/<branch>.md` and card, as below.
3. Merge the ticket branches into the release branch **locally**. Run the gate on the release
   branch, add the changelog entry (one entry for the release), and **push only the release branch**.
4. Open one PR `release/vX.Y.Z` → `pre-release`. Its checks run, then the merge → dev deploy →
   live e2e on dev → the automatic release PR to `main`.

**Every feature or change ships with e2e tests:** journeys in `e2e/tests/` for what a user can now
do, or what was broken, next to PHPUnit. See `e2e/README.md`; use `npm run e2e:ui` to watch them.
Parallel checkouts (worktrees) use their own databases: `TEST_DB_SUFFIX=<name>` for PHPUnit
(`brainitive_test_<name>`) and `E2E_PORT=<port>` for e2e. A worktree needs a **real copy of
`vendor`**: a symlinked `vendor` runs the main checkout's code (KNOWLEDGE).

### Release flow: pre-release → main (BRAI-95)
**Nothing goes straight to `main`.** `main` is production: every push to it deploys.

```
feature branch ──PR──▶ pre-release ──(green)──PR──▶ main ──▶ production deploy
  Tests workflow        Pre-release workflow         Deploy workflow
  (every push)          (tests → deploy to            (tests again, then deploys
                         dev.brainitive.app;           exactly the tested commit)
                         e2e: BRAI-96)
```

- **Tests** (`.github/workflows/tests.yml`) runs on every push to a feature branch, and is
  called by the other two workflows. It runs Pint, tsc, the build, PHPUnit on **PostgreSQL 16**
  (as production), and the Playwright e2e journeys in Chromium. There is no SQLite in testing:
  SQLite hid case-sensitive `LIKE` and aborted transactions.
- **Pre-release** (`pre-release.yml`): the gate on the integration branch, then a deploy to
  **dev.brainitive.app**. That deploy runs plain `migrate` on dev's existing data, the same thing
  production will run, then runs **all e2e journeys live against the deployed dev server** (the
  previous run's test data is pruned first with `e2e:prune`). When all of that is green, the
  workflow **opens or updates the PR "Release: pre-release → main"** itself, listing the PRs
  that would reach production. A person merges it, and merging deploys. The repo setting "Allow
  GitHub Actions to create pull requests" is on for this. Check the change on dev; the
  Brainitive card goes to **Testing** once it's live there.
- **Deploy** (`deploy.yml`): `needs: tests`, then fast-forwards the server to `github.sha`,
  never to a newer commit nobody tested.
- **Branch protection:**
  - `pre-release` takes PRs only, with **Pint, Frontend (types + build), PHPUnit (PostgreSQL)
    and E2E (Chromium)** green.
  - `main` takes PRs only, with **Ready for main** green (the release PR the workflow opens).
  - Neither can be force-pushed or deleted.
  - Admins can override in an emergency (`enforce_admins` is off).
  - Renaming a CI job means updating the required check names
    (`gh api repos/{owner}/{repo}/branches/<b>/protection`).
- **Hotfix** straight into `main` (emergencies only): merge `main` back into `pre-release`
  right away.

> **Tracking lives in Brainitive.** When the Brainitive MCP server is connected, the project
> board on Brainitive.app is the source of truth for *status*: every task gets a card, and
> it's moved to **Testing** when done. The local `tasks/<branch>.md` is the *detailed plan*;
> the card is the *tracker*. Full steps: [skills/track-work-in-brainitive.md](./skills/track-work-in-brainitive.md).
> `BRAI-*` keys refer to Brainitive board cards for this project, not Jira issues. Do not
> check Jira/Atlassian for Brainitive work unless the user explicitly asks for it.
> If MCP is **not** connected, skip the card and use the local plan only — don't fabricate
> board updates.

### 1 — Branch off `pre-release`
Never commit to `main` or `pre-release` directly. Use the existing prefixes: `feat/...`, `fix/...`, `chore/...`
(matches git history, e.g. `feat/mcp-server`).

### 2 — Write the plan: `docs/ai/tasks/<branch>.md`
**Before writing code**, create a plan file named after the branch (slashes → dashes):
`feat/calendar-sync` → `docs/ai/tasks/feat-calendar-sync.md`. Use the template in
[`tasks/README.md`](./tasks/README.md). The plan states the goal, the approach, the concrete
changes (migrations/models/policies/controllers/routes/frontend/types), and the test plan.

For a trivial one-liner fix you can skip the file — but anything touching >2 files, a
migration, a data model, or a new page/endpoint gets a plan.

**Track it on the Brainitive board (when MCP connected).** Ensure a card exists for the task
on the Brainitive project board; if none exists, create one in **In Progress**, and link it
to `tasks/<branch>.md` (both ways). See
[skills/track-work-in-brainitive.md](./skills/track-work-in-brainitive.md).

### 3 — Build
Check [`skills/`](./skills) for a recipe first — it encodes the project's way of doing the
task so you don't reverse-engineer it. Follow [`CONVENTIONS.md`](./CONVENTIONS.md). Reuse
existing patterns and files over inventing new ones.

**Review the diff before the PR** with
[skills/review-checklist.md](./skills/review-checklist.md) (backend, frontend, database,
security). In Claude Code, run the matching subagents in `.claude/agents/` in parallel:
`php-reviewer`, `frontend-reviewer`, `database-reviewer` (if there's a migration or query
change) and `security-reviewer` (auth, public routes, uploads, tokens, dependencies). Fix
every Critical and High finding, or say in the PR why it doesn't apply.
If the ECC plugin is installed, use these project agents (no prefix), not the
`ecc:*-reviewer` ones: the ECC versions don't know this repo's rules.

### 4 — Update the docs (the step that prevents staleness)
Before opening the PR, update whatever your change invalidated:
- New/renamed/moved module or entry points → **[`ARCHITECTURE.md`](./ARCHITECTURE.md)**
- New convention, or you changed an existing one → **[`CONVENTIONS.md`](./CONVENTIONS.md)**
- A non-obvious decision, trap, or gotcha you hit → **[`KNOWLEDGE.md`](./KNOWLEDGE.md)**
- A reusable task flow worth capturing → add/adjust a **[skill](./skills)**
- Mark your `tasks/<branch>.md` `Status: done`.
- **Brainitive (when MCP connected):** move the board card to **Testing** (not Done — a human
  verifies and moves it on). If the change is user-facing/notable, write or update a
  Brainitive **Page** documenting it (agent's judgment — skip for small fixes). See the
  [tracking skill](./skills/track-work-in-brainitive.md).

> This base existed because the docs had drifted (they described 18 models when there were
> 33). Step 4 is how we keep that from happening again.

### 5 — The gate, then PR
```bash
./vendor/bin/pint --test   # PHP style (drop --test to autofix)
npx tsc --noEmit           # TypeScript
php artisan test           # backend tests
npm run build              # frontend builds clean
```
All green **and** the change verified in the running app (`composer run dev`). Open the PR
against **`pre-release`**, scoped to one feature/fix. CI repeats the gate and adds the e2e suite
(`npm run e2e` locally, see `e2e/README.md`). PHPUnit runs on Postgres in the forced database
`brainitive_test` (phpunit.xml), never `devdb`. Report honestly if any step failed or was skipped.

---

## Fix-specific notes
1. Reproduce first (failing test or manual repro). 2. Find the root cause — don't patch the
symptom. 3. Smallest correct change matching surrounding code. 4. Add a test that would have
caught it. 5. Run the gate. 6. Promote any non-obvious trap into [`KNOWLEDGE.md`](./KNOWLEDGE.md).

## Definition of done — the gate
The four commands above, all green, plus verified in the running app, plus **e2e journeys for the
change** (`npx playwright test -c e2e --project=chromium` green). Plus docs updated
(step 4), `tasks/<branch>.md` marked done, and — when MCP is connected — the Brainitive board
card moved to **Testing**.

**Version bump + "What's new".** Every user-facing change adds an entry at the top of
`CHANGELOG` in `resources/js/components/HelpModal.tsx` (shown in the in-app help modal; the
newest entry *is* the app version). New feature → minor bump (`v1.11.0` → `v1.12.0`), small
fix → patch (`v1.12.0` → `v1.12.1`). Entry = `version`, short `title`, `tag` (Feature /
Major Feature / Enhancement / Patch), `date`, and user-facing `items` (what changed for the
user, not file names).
Merging `pre-release` into `main` with a new top version **releases it automatically**
(`.github/workflows/release.yml`): tag `vX.Y.Z`, GitHub Release with the entry's items as
notes, and Docker images `:X.Y.Z` / `:X.Y` / `:latest`. No bump → only `:edge` is rebuilt.

**Homepage + Welcome tab.** A new module or a big change to one also updates the feature
cards in `resources/js/Pages/Welcome.tsx`, `WELCOME_FEATURES` in `HelpModal.tsx`, and — when
the UI changed visibly — the screenshots in `public/images/screenshots/` (light + `-dark`,
2880×1720 JPEG, demo data only, no real customer data).

## Git & PR conventions
- Branch off `pre-release`; commit/push only when asked.
- Conventional commits with scope: `feat(mcp): …`, `fix(helpdesk): …`.
- End commit messages with the required `Co-Authored-By` trailer; PRs against `pre-release`
  (only the promotion PR targets `main`).

## Agent etiquette
- **Read [`ARCHITECTURE.md`](./ARCHITECTURE.md) + [`CONVENTIONS.md`](./CONVENTIONS.md) +
  [`KNOWLEDGE.md`](./KNOWLEDGE.md) before starting.** They exist so you don't read the whole
  codebase.
- Match surrounding style, naming, comment density.
- When you learn something the next agent needs, write it into the owning doc — that's how
  the shared memory grows.
