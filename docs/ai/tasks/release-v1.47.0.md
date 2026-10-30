# Release v1.47.0 — The Brainitive CLI + an MCP server that can set up a whole project

**Branch:** release/v1.47.0
**Status:** done
**Started:** 2026-10-30
**Board card:** none (MCP not connected in this session)

## Problem / goal (the user's ask, 2026-10-30)

1. **A real CLI.** Until now "the CLI" was eight JSON endpoints under `/api/cli` with no client at
   all, a password login that doesn't work for Google / GitHub / Microsoft accounts, and only task
   lists. The ask: a binary built on GitHub for macOS, Linux and Windows, installed with one
   command (`curl … | sh`), that works without any runtime on the machine, reaches as much of
   Brainitive as possible, and makes **capture** (inbox, snippets, bookmarks, tasks, issues) great.
2. **An MCP server that can do everything the website can for setting up a project**: create
   workspaces and projects, boards, columns, labels, item types, priorities, custom fields, members,
   and create issues in bulk with all of those set — so that an AI given a Jira export can analyse
   it, build the matching structure in Brainitive and import the issues.

## Approach

### The CLI is a thin Go client of the MCP server
- **Go**, not Node: one static binary per platform, no runtime for the user to install, trivial
  cross-compiling in Actions (`GOOS`/`GOARCH`), ~10 MB. Source in `cli/` (own `go.mod`, module
  `github.com/armendzekjiri/brainitive/cli`, stdlib + cobra only).
- **Every command is an MCP tool call.** The CLI speaks JSON-RPC to `<server>/mcp` with a Bearer
  token (`tools/call`; the Laravel MCP HTTP transport needs no session). The 70-odd tools are the
  API: adding a tool makes it available in the CLI through `brainitive tool <name>` immediately, and
  the friendly commands (`issue create`, `snippet add`, …) are just typed wrappers. No second REST
  surface to keep in sync. The one exception is screenshots: `issue create --screenshot` posts to
  the extension API (`/api/ext/v1/boards/{id}/items`), which takes a file.
- **Sign-in like the extension**: OAuth 2.1 + PKCE with Dynamic Client Registration, redirect to a
  loopback port (`http://127.0.0.1:<port>/callback` is accepted by `GuardOAuthFlows`). Scopes
  `mcp:use capture`. Tokens are refreshed automatically; stored in
  `~/.config/brainitive/config.json` (0600) — no keychain dependency (cgo-free build).
  `BRAINITIVE_TOKEN` + `BRAINITIVE_SERVER` env vars for CI / scripts (a token from Profile →
  Connected apps or `php artisan mcp:get-token`).
- **Context**: `--workspace/-w`, `--project/-p`, `--board/-b`, `--list`, `--collection`, `--group`
  flags, else `brainitive use <workspace>[/<project>[/<board>]]` defaults, else the only one that
  exists.
- **Output**: tables for people, `--json` for scripts (the tool's `structuredContent` verbatim).
- **`brainitive mcp`**: a stdio ⇄ HTTP proxy so Claude Code, Cursor, Codex, Copilot CLI and CI
  agents get the remote server with the CLI's login (no OAuth support needed on their side).
- **Install**: `curl -fsSL https://brainitive.app/install.sh | sh` (route `/install.sh` serves
  `cli/install.sh`; also raw.githubusercontent). Detects OS/arch, downloads the asset from the
  **latest GitHub Release** (`releases/latest/download/brainitive_<os>_<arch>.tar.gz`), verifies
  `checksums.txt`, installs to `/usr/local/bin` (or `~/.local/bin` and tells you to add it to PATH).
  Windows: `irm https://brainitive.app/install.ps1 | iex` (zip, `%LOCALAPPDATA%\Brainitive\bin`).
  `brainitive update` does the same from inside the binary.
- **Release**: the CLI is versioned with the app. `release.yml` gets a `cli` job that cross-compiles
  the six binaries and uploads them to the app's release with `--clobber` (like the extension
  zips). `cli.yml` runs `go vet` + `go test` + a cross-build on every push touching `cli/`.
- The old `/api/cli/*` endpoints stay untouched this release (removal is a separate decision). Their
  only client, the Python `brainitive_cli` package that lived in `cli/` (pip install, password login,
  task lists only), is deleted: the Go binary replaces it in the same directory.

### MCP: a "set up a project" tool group
Same rules as every tool (policy of the UI action, slugs, `location`, `request_id` idempotency).
Logic that the UI already has is shared, not copied: custom fields move out of
`ItemFieldController` into `App\Support\Boards\ItemFields`; issue creation keeps using
`ProjectIssues::create` (extended with type, labels, story points, parent, fields).

New tools: `create_workspace`, `create_project` (default board like the UI; optional columns),
`update_project`, `get_project_config` (boards + columns, labels, item types, priorities, custom
fields with options, members — everything an import needs to map against), `create_board`,
`update_board`, `create_board_column`, `update_board_column`, `delete_board_column` (empty only),
`reorder_board_columns`, `create_label`, `update_label`, `delete_label`, `create_item_type`,
`update_item_type`, `create_priority`, `update_priority`, `create_item_field`, `update_item_field`
(options sync / archive / restore), `add_project_member`, `create_task_list`,
`create_board_items` (bulk, ≤ 100 per call, each with column / type / priority / labels /
assignee / dates / story points / fields / parent, `external_key` echoed back for mapping),
`capture_to_inbox`.

Extended: `create_board_item` and `update_board_item` take `type`, `priority`, `labels`,
`story_points`, `parent_id`, `fields`. `get_board` returns column metadata (slug, color, done).
The server `#[Instructions]` get a "Setting up or migrating a project" section.

## Changes

- **PHP**
  - `app/Support/Boards/ItemFields.php` (new): `create`, `update`, `syncOptions` — moved from
    `ItemFieldController` (which now delegates).
  - `app/Support/Boards/ProjectIssues::create` accepts `item_type_id`, `story_points`, `parent_id`,
    `label_ids`, `fields`.
  - `app/Mcp/Tools/Concerns/InteractsWithBrainitive`: `resolveColumn`, `resolvePriorityId`,
    `resolveItemTypeId`, `resolveLabelIds`, `resolveFieldValues` helpers.
  - 24 new tools in `app/Mcp/Tools/` + registration in `BrainitiveServer` + instructions.
  - Route `GET /install.sh` and `GET /install.ps1` (public, text/plain) in `routes/web.php`.
- **Go** — `cli/` (see `cli/README.md`): `cmd/brainitive`, `internal/{config,auth,mcp,api,output,cli}`.
- **CI** — `.github/workflows/cli.yml` (new), `release.yml` `cli` job.
- **Install** — `cli/install.sh`, `cli/install.ps1`.
- **Frontend** — `HelpModal.tsx` CHANGELOG v1.47.0; `Welcome.tsx` CLI section + "Connect & automate"
  card; `e2e/tests/homepage.spec.ts` titles.
- **Docs** — `docs-site/integrations/cli.md` (new), `ai-and-mcp.md` tool table, ARCHITECTURE (CLI
  module rewritten, MCP tool list), KNOWLEDGE (CLI release + OAuth loopback + bulk import notes),
  README.

## Test plan
- PHPUnit `tests/Feature/Mcp/ProjectSetupToolsTest.php`: workspace → project → board → columns →
  labels / types / priorities / fields → bulk items with everything set → `get_project_config`
  shows it all; policies (viewer refused); idempotent retries; column delete refused with items;
  field type change refused with values. `CaptureToolsTest`: inbox capture.
  `ToolDiscoveryTest` extended with the new names + hints.
- Go: `go test ./...` — config round-trip, OAuth PKCE helpers, MCP client against `httptest`
  (tools/call, error mapping, token refresh on 401), table rendering, install asset naming.
- Manual: `go run ./cmd/brainitive login --server http://localhost:8000`, `capture`, `snippet add`,
  `issue create --screenshot`, `brainitive mcp` from Claude Code.
- Install script: `sh -n`, run against a fake release dir.
- Gate: pint, tsc, PHPUnit, build; docs-site build.

## Docs to update
ARCHITECTURE (CLI section, MCP tools), KNOWLEDGE, CONVENTIONS (one line: CLI = MCP client),
skills/add-mcp-tool.md (bulk + resolver helpers), docs-site.

## Review outcomes (php-reviewer + security-reviewer, 2026-10-30)
Fixed: soft-deleted project / board / list names reused through `IdempotentRequest` (now
`withTrashed()` checks + the wrapper rethrows unique violations that aren't its own race); subtasks
created over MCP join the parent's sprint; `update_board` refuses kanban while a sprint runs; new
columns get `max(position) + 1`; labels dedupe by slug and renames move the slug; `create_item_type`
re-enables a disabled instance type instead of shadowing it; `parent_external_key` must point at an
earlier card; `brainitive logout` revokes access + refresh token on the server (`DELETE
/api/cli/logout`); loopback callback only ends the flow on a state match and escapes what it shows;
OAuth endpoints must share the server's origin (mix-up defence); `/install.sh` is session-free and
throttled; `install.sh` runs from `main()`; CI uses the newest Go patch release + govulncheck.
Accepted as is: checksums.txt comes from the same release as the archive (integrity, not
authenticity) — signing (minisign / Sigstore) is the next step if the CLI gets traction.

## Open questions
- `create_priority` / `update_priority` have no project-settings UI yet (priorities were
  instance-wide before): an AI-created priority can only be renamed through MCP, not deleted. Either
  a small settings card or a `delete_priority` tool in a follow-up.
- Keychain storage for the token (needs cgo or a platform helper) — later.
- Removing `/api/cli/*` + `CliController` — proposed for v1.48 once the binary has shipped.
