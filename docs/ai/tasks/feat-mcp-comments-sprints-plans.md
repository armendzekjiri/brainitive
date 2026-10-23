# MCP: comments, sprints and plans (BRAI-67, 68, 69)

**Branch:** feat/mcp-comments-sprints-plans
**Status:** done
**Started:** 2026-10-23
**Board cards:** BRAI-67 (#item-169), BRAI-68 (#item-170), BRAI-69 (#item-171), on the main board in workspace `ndizeal-everything`

## Decisions (where the cards left room)
- One idempotency mechanism for every MCP write: `request_id` per (user, tool), payload hash, result stored 7 days (`mcp_requests` + `IdempotentRequest`).
- Shared domain actions so the UI and MCP behave the same: `ItemComments::add` + `ItemMentions::notify` (moved out of BoardController, without the debug logs of user ids), `SprintLifecycle::start/complete` with the board row locked (fixes two concurrent starts → two active sprints in the UI too).
- Comments: permissions as in the UI (view to list, update to comment); mentions via `@email` of project members (raw HTML is stripped by the Markdown converter). Reply parent validated against the item (also fixed in the UI controller).
- Sprints: explicit dates for start, an explicit `move_open_to` for completion, `expected_status` for edits; moving issues takes whole subtrees; subtask ids alone are refused.
- Plans: bounded operations (rows, bars, dependencies) via `PlanOperations`, all-or-nothing, cycles refused (the UI doesn't check; MCP does), `expected_revision` checked under a row lock, then the same normalize → restrictToProject → writeThrough → saveDocument path as the editor. Linking rows to issues stays in the UI for now.

## Tests
`tests/Feature/Mcp/{ItemCommentToolsTest,SprintToolsTest,PlanToolsTest,ToolDiscoveryTest}.php`.
