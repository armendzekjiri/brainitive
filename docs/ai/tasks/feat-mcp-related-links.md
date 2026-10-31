# MCP: related content, referenced by and linked issues (BRAI-432, GitHub #132)

**Branch:** feat/mcp-related-links (from release/v1.48.0)
**Status:** done
**Started:** 2026-10-31
**Board card:** BRAI-432 · GitHub issue #132 (self-hosted user, MCP through a remote proxy: "connect the meeting task to its notes doc")

## Goal
Everything the issue / doc view offers under **Related content**, **Referenced by** and **Linked issues**
is reachable over MCP: read, add, remove — with the UI's rules and services, no copies.

## What the UI supports (checked)
- Related content (BRAI-71, `ContentReference::TYPES`): `item` (issue), `page` (doc or whiteboard), `ticket`, `plan` —
  all endpoints in **one project**. Issue → issue is refused there (that's a linked issue). Task-list tasks are not
  supported by the UI, so not by MCP either.
- Referenced by = incoming references, manual or derived (plan rows, whiteboard cards, smart links). Derived ones are
  read-only (change the plan row / card / smart link).
- Linked issues (`ItemLink::LINK_TYPES`): blocks, is_blocked_by, duplicates, is_duplicated_by, relates_to, clones,
  is_cloned_by. The picker offers issues of the same project only.

## Tools (71 → 76)
- `list_content_links` (read-only): `type` + `id` (issue id or key, doc uuid, ticket id or key, plan id or slug) →
  `related`, `referenced_by`, `linked_issues` (issues only), `can_link`.
- `link_related_content` / `unlink_related_content` (request_id): source + target endpoint. Add is a no-op when the
  link exists (`created: false`); remove of a missing link answers `removed: false`; a derived link is refused with
  where to change it.
- `link_issues` / `unlink_issues` (request_id): `item`, `target_item` (id or key, same project), `link_type`.
  An existing link — the same one or its inverse from the other side (A blocks B = B is_blocked_by A) — is a no-op.
  Unlink without `link_type` removes every link between the two issues.
- `get_board_item` gains `related_content`, `referenced_by`, `linked_issues`; `get_page` gains `related_content`,
  `referenced_by` (compact entries: type, id, key, uuid, title, url, origin).

## Decisions
- Reuse: `ContentReferences::resolve / link / forEndpoint` + a new `ContentReferences::visibleEndpoint()` (moved out of
  `ContentReferenceController`, which now calls it). Item links move into a shared domain action
  `App\Support\Boards\ItemLinks` (find incl. inverse, add with history entry, remove with history entry);
  `BoardController::storeItemLink / destroyItemLink` call it — same history entries as before.
- Permissions as the UI: view to read, update on the source to link / unlink (the issue for linked issues).
- Visibility: related content the user can't see is left out entirely (as in the UI — a private draft never shows
  up, not even as a count). A linked issue the user can't open (e.g. an old cross-project link) shows as
  `restricted: true` with no key, title or url.
- Cross-project / cross-workspace targets: "not found in this project" (same answer as a missing one).
- Errors list the valid types / link types.
- Not in scope: `fetch` output (deep-research contract stays as is), `/api/v1`, UI changes.

## Tests
`tests/Feature/Mcp/ContentLinkToolsTest.php` (+ `ToolDiscoveryTest` expectations). Focused runs only:
`TEST_DB_SUFFIX=mcplinks php artisan test --filter='ContentLinkToolsTest|ToolDiscoveryTest|ItemHistoryTest|SecurityRegressionTest|ContentReferencesTest'`.
