# MCP tools for releases

**Branch:** feat/mcp-releases (from origin/pre-release)
**Status:** done
**Started:** 2026-11-06

## Problem / goal
AI clients (MCP) can plan sprints but not releases (fix versions). Let them list / read releases, create them
(also historical, already shipped ones), change them and put issues into a release by key — with the web's rules.

## Approach
- Extract the web `ReleaseController` rules into `App\Support\Releases\ReleaseLifecycle` (`rules`, `ordered`,
  `ship`, `setStatus`, `assignItems`, `itemsWithState`); the controller and the tools both call it.
- Permissions: `ReleasePolicy` (view = project view, manage = `createBoards`). Clearing issues' release
  (`release_id` 0) needs `create` on releases of the project.
- Tools (trait `InteractsWithReleases`): `list_releases`, `get_release`, `create_release` (name dedup, any case →
  existing returned unchanged, `created: false`; no request_id needed), `update_release` (only passed keys;
  shipping with unfinished issues requires explicit `move_unfinished_to`), `set_items_release` (keys, ≤ 200,
  unknown / other project keys → nothing changes; archived releases refused).
- Notes: Markdown in (`IssueMarkdown::toHtml` + `SafeHtml::clean`, like the web's notes save), Markdown out.
- `ResourceLocator` knows `Release` (type `release`).

## Changes
- `app/Support/Releases/ReleaseLifecycle.php`, `app/Http/Controllers/ReleaseController.php` (uses it)
- `app/Mcp/Tools/{List,Get,Create,Update}ReleaseTool.php`, `SetItemsReleaseTool.php`, `Concerns/InteractsWithReleases.php`
- `app/Mcp/Servers/BrainitiveServer.php` (registration + "Releases" instructions), `app/Support/Mcp/ResourceLocator.php`
- Tests: `tests/Feature/Mcp/ReleaseToolsTest.php`, `ToolDiscoveryTest.php`
- Docs: ARCHITECTURE (MCP + Releases), docs-site MCP pages, HelpModal v1.51.0 changelog line
