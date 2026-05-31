# Skill: Track work on the Brainitive board (via MCP)

**Planning and tracking of work items live in Brainitive itself** — the project board on
Brainitive.app is the source of truth for *status*. When the Brainitive MCP server is
connected, every piece of work must have a card on that board, moved to **Testing** when
done. The local `docs/ai/tasks/<branch>.md` remains the *detailed plan*; the board card is
the *tracker*. They link to each other.

> **Status: dormant until MCP is connected.** As of now there is no Brainitive MCP
> connection in this environment. If the Brainitive MCP tools below are **not** available,
> skip this skill and rely on the local plan file alone. Do not fabricate board updates.

## How to tell if MCP is connected
The Brainitive MCP tools are present in your tool list, e.g. `list_workspaces`,
`list_boards`, `get_board`, `create_board_item`, `move_board_item`, `update_board_item`.
If they aren't available, this skill doesn't apply right now.

## The flow

### 0. Locate the Brainitive board (once per task)
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
**Page** with `create_page` / `update_page` describing what changed and how to use it (page
bodies are Markdown, converted automatically). Skip this for small/internal fixes. This is
the in-product documentation, separate from the repo's `docs/ai/` (which documents *how we
build*, not *what the product does*).

## Summary
```
branch + tasks/<branch>.md  ─▶  card in "In Progress" (create if missing)  ─▶  link both
        ─▶  work  ─▶  gate green  ─▶  move card to "Testing"  ─▶  (optional) Brainitive Page
```
