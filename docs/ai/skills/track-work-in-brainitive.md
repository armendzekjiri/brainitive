# Skill: Track work on the Brainitive board (via MCP)

**Planning and tracking of work items live in Brainitive itself** — the project board on
Brainitive.app is the source of truth for *status*. When the Brainitive MCP server is
connected, every piece of work must have a card on that board, moved to **Testing** when
done. The local `docs/ai/tasks/<branch>.md` remains the *detailed plan*; the board card is
the *tracker*. They link to each other.

> **MCP-dependent.** Use this skill when the Brainitive MCP tools are available in the
> current environment. If they are **not** available, rely on the local plan file alone and
> say that Brainitive updates were skipped. Do not fabricate board updates.

## How to tell if MCP is connected
The Brainitive MCP tools are present in your tool list, e.g. `list_workspaces`,
`list_boards`, `get_board`, `create_board_item`, `move_board_item`, `update_board_item`.
If they aren't available, this skill doesn't apply right now.

## Tracker ownership
For this project, work item keys like `BRAI-41` are **Brainitive board card keys**, not Jira
issues. Start with the Brainitive MCP tools (`list_workspaces` → `list_projects` →
`list_boards` → `get_board` / `get_board_item`) when the user references a `BRAI-*` key.
Do not query Jira/Atlassian for Brainitive project work unless the user explicitly names
Jira/Atlassian or asks you to compare with an external tracker.

## The flow

### 0. Locate the Brainitive board (once per task)
Current location (moved from `personal` on 2026-10-23): workspace **`ndizeal-everything`**,
project **`brainitive`**, board **`main-board`**: columns To Do `25`, In Progress `26`,
Testing `67`, Can be Improved `68`, Done `27`. If that fails (moved again), discover it:

`list_workspaces` → pick the Brainitive workspace slug → `list_projects` → the Brainitive
project → `list_boards` → the project's development board → `get_board` to read its columns
(note the **In Progress** and **Testing** column names/ids) and existing items.

### 1. Ensure a card exists — create in "In Progress" if not
When you start work (right after creating the branch + `tasks/<branch>.md`):
- Search the board (`get_board`, or the `search` tool) for an existing card matching the
  task. Match on the branch name or feature title.
- **If none exists**, `create_board_item` in the **In Progress** column:
  - **title:** the task/feature (concise).
  - **description:** the goal + the branch name + a pointer to `docs/ai/tasks/<branch>.md`.
  Capture the returned card id/url.

### 2. Link the card and the plan
- In `docs/ai/tasks/<branch>.md`, record the card: add a `**Board card:** <url or id>` line
  under the header.
- The card description references the branch (and plan path). This is the two-way link.

### 3. While working
Leave the card in **In Progress**. Use `update_board_item` to keep its description/status
current if scope changes meaningfully.

### 4. When done — move to "Testing"
Once the work is complete and the [gate](../WORKFLOW.md#5--the-gate-then-pr) passes,
`move_board_item` the card to the **Testing** column (a human verifies/QAs and moves it on
to Done — the agent does **not** move cards to Done). Mark `tasks/<branch>.md` `Status: done`.

### 5. Document in a Brainitive Page — when it makes sense
Use judgment: for a user-facing feature or a notable change, write or update a Brainitive
**Page** describing what changed and how to use it. Skip this for small/internal fixes.
Follow the **page writing/editing rules** below.

---

## Page writing / editing rules

When writing or editing Brainitive Pages via MCP (`create_page` / `update_page`):

### Where pages go — structure
- **AI-agent documentation lives under one parent page: "General AI Documentation"**
  (workspace `ndizeal-everything`, project `brainitive`, uuid `0b4eb6f8-efa9-4191-8425-f6fe4dde676d`).
  It is the index/parent for all AI docs.
- **Every AI documentation page is a child of that parent** — pass its uuid as `parent_uuid`
  on `create_page`. Never create AI docs at the project root.
- **Check first, don't duplicate.** `list_pages` (or `search`) before creating; if a page on
  the topic already exists, **`update_page`** it (mode `replace` to rewrite, `append` to add)
  instead of making a second one.
- **Update the parent index.** When you add, rename, or remove an AI documentation child,
  update the "General AI Documentation" page so its `Pages` section stays accurate.

### How to write
- **Body is Markdown** — headings, lists, bold, links, code blocks (converted automatically).
- Lead with a one-line purpose. Keep it readable for non-developers browsing Brainitive.
- **Repo `docs/ai/` is authoritative**; these pages mirror the key parts. When they could
  drift, say "authoritative version lives in `docs/ai/...`" and link the concept, so readers
  know where truth lives.
- These pages document **what the product does / how we work in-product**; the repo `docs/ai/`
  documents **how we build**. Don't paste build internals into product pages.
- After `create_page`/`update_page`, share the returned `location.url` with the user.

## Summary
```
branch + tasks/<branch>.md  ─▶  card in "In Progress" (create if missing)  ─▶  link both
        ─▶  work  ─▶  gate green  ─▶  move card to "Testing"  ─▶  (optional) Brainitive Page
```
