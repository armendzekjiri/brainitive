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
   off main  ─▶   tasks/<branch>.md ─▶ follow a skill  ─▶ ARCH/KNOW/CONV  ─▶  green + PR
                  + Brainitive card                       (+ move card → Testing)
```

> **Tracking lives in Brainitive.** When the Brainitive MCP server is connected, the project
> board on Brainitive.app is the source of truth for *status*: every task gets a card, and
> it's moved to **Testing** when done. The local `tasks/<branch>.md` is the *detailed plan*;
> the card is the *tracker*. Full steps: [skills/track-work-in-brainitive.md](./skills/track-work-in-brainitive.md).
> `BRAI-*` keys refer to Brainitive board cards for this project, not Jira issues. Do not
> check Jira/Atlassian for Brainitive work unless the user explicitly asks for it.
> If MCP is **not** connected, skip the card and use the local plan only — don't fabricate
> board updates.

### 1 — Branch off `main`
Never commit to `main`. Use the existing prefixes: `feat/...`, `fix/...`, `chore/...`
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
against `main`, scoped to one feature/fix. Report honestly if any step failed or was skipped.

---

## Fix-specific notes
1. Reproduce first (failing test or manual repro). 2. Find the root cause — don't patch the
symptom. 3. Smallest correct change matching surrounding code. 4. Add a test that would have
caught it. 5. Run the gate. 6. Promote any non-obvious trap into [`KNOWLEDGE.md`](./KNOWLEDGE.md).

## Definition of done — the gate
The four commands above, all green, plus verified in the running app. Plus docs updated
(step 4), `tasks/<branch>.md` marked done, and — when MCP is connected — the Brainitive board
card moved to **Testing**.

## Git & PR conventions
- Branch off `main`; commit/push only when asked.
- Conventional commits with scope: `feat(mcp): …`, `fix(helpdesk): …`.
- End commit messages with the required `Co-Authored-By` trailer; PRs against `main`.

## Agent etiquette
- **Read [`ARCHITECTURE.md`](./ARCHITECTURE.md) + [`CONVENTIONS.md`](./CONVENTIONS.md) +
  [`KNOWLEDGE.md`](./KNOWLEDGE.md) before starting.** They exist so you don't read the whole
  codebase.
- Match surrounding style, naming, comment density.
- When you learn something the next agent needs, write it into the owning doc — that's how
  the shared memory grows.
