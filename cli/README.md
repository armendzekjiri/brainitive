# Brainitive CLI

Brainitive from your terminal: capture thoughts, create issues and tasks, save snippets and
bookmarks, search everything — and give AI agents your whole workspace with `brainitive mcp`.
One static binary for macOS, Linux and Windows, no runtime to install. Works with Brainitive
Cloud and self-hosted servers.

## Install

```sh
curl -fsSL https://github.com/armendzekjiri/brainitive/releases/latest/download/install.sh | sh   # macOS / Linux
```

```powershell
irm https://github.com/armendzekjiri/brainitive/releases/latest/download/install.ps1 | iex   # Windows (PowerShell)
```

Or download `brainitive_<os>_<arch>.tar.gz` / `.zip` from the
[latest release](https://github.com/armendzekjiri/brainitive/releases/latest) and put the binary
on your `PATH`. `brainitive update` upgrades in place.

## Sign in

```sh
brainitive login                                   # Brainitive Cloud
brainitive login --server brainitive.example.com   # your own server
```

Your browser opens, you approve the access, done — the same OAuth flow the browser extension
uses, nothing to copy. The session lives in `~/.config/brainitive/config.json` (readable only by
you). For scripts and CI set `BRAINITIVE_TOKEN` and `BRAINITIVE_SERVER` instead (a token from
*Profile → Connected apps*, or `php artisan mcp:get-token <email>` on the server).

## Everyday

```sh
brainitive use acme/shop/main-board         # defaults for the commands below
brainitive capture "Ask Dana about Q4"      # → your inbox, sort it later
pbpaste | brainitive capture
brainitive issue create Checkout misaligned --type Bug --priority High --label ui --assignee me
brainitive issue create "Crash on save" -f crash.md --screenshot shot.png
brainitive issues                           # the board, by column
brainitive issue move SHOP-12 --to Done
brainitive issue comment SHOP-12 "Fixed in **PR 12**"
brainitive task add Renew the domain --due 2026-12-01
brainitive snippet add "Reset sequence" -f reset.sql
brainitive bookmark add https://go.dev --title "Go"
brainitive search "payment failed" --type issues --mine
brainitive page create "Release notes" -f notes.md --publish
brainitive project config                   # columns, labels, types, priorities, fields, members
```

Every command prints a table for people; add `--json` for the raw result in scripts.

## Everything else: `brainitive tool`

The CLI is a client of the Brainitive MCP server, so every tool the server has is one command
away — including the project-setup tools (create a project with its statuses, labels, types,
priorities, custom fields, members; create cards in bulk):

```sh
brainitive tools                                   # the catalogue (70+)
brainitive tool create-label --describe            # arguments of one tool
brainitive tool create-label name=urgent color=#ef4444
brainitive tool create-board-items --args '{"items":[{"title":"A","column":"Done"}]}'
```

Values that look like numbers, booleans or JSON are typed; `key:=value` forces a string. The
workspace / project / board context comes from your defaults.

## AI agents: `brainitive mcp`

Runs a local MCP server on stdio bridged to your account, for tools that only know local
servers or can't do OAuth themselves:

```sh
claude mcp add brainitive -- brainitive mcp        # Claude Code
```

```json
{ "mcpServers": { "brainitive": { "command": "brainitive", "args": ["mcp"] } } }
```

## Development

```sh
cd cli
go test ./...
go run ./cmd/brainitive --server http://localhost:8000 login
```

Layout: `cmd/brainitive` (main), `internal/cli` (commands), `internal/mcp` (JSON-RPC client),
`internal/auth` (OAuth 2.1 + PKCE with dynamic client registration and a loopback redirect),
`internal/api` (the extension API, for screenshots), `internal/config`, `internal/output`.
Releases: `.github/workflows/release.yml` builds the six binaries and attaches them to the app's
GitHub release with `checksums.txt`; `install.sh` / `install.ps1` download from there.
