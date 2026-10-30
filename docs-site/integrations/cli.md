---
title: Command line (CLI)
description: Install the Brainitive CLI with one command on macOS, Linux or Windows — capture, issues, tasks, snippets, docs and search from your terminal, plus an MCP bridge for AI agents.
order: 95
---

# Command line (CLI)

`brainitive` is a small program for your terminal. It captures thoughts into your inbox, creates
issues (with a screenshot, if you like), tasks, snippets and bookmarks, reads boards and docs,
searches everything, and can set up whole projects. It also lets AI tools on your machine use your
Brainitive account. One file, nothing else to install — for Brainitive Cloud and self-hosted
servers alike.

## Install

::: code-group

```sh [macOS / Linux]
curl -fsSL https://github.com/armendzekjiri/brainitive/releases/latest/download/install.sh | sh
```

```powershell [Windows]
irm https://github.com/armendzekjiri/brainitive/releases/latest/download/install.ps1 | iex
```

:::

The script downloads the latest release for your machine from GitHub, checks its checksum and puts
`brainitive` on your `PATH`. Prefer to do it yourself? Download `brainitive_<os>_<arch>.tar.gz`
(or `.zip`) from the [latest release](https://github.com/armendzekjiri/brainitive/releases/latest).
Later, `brainitive update` upgrades in place.

The installer also adds `bi` as a short command when that name is available, so `bi login` and `brainitive login` do the same thing. It never replaces an existing `bi` command. Use `bi -s "search words"` to search or `bi -c "a quick note"` to capture.

### Quick shortcuts

| Command | Action |
|---|---|
| `bi -s "search words"` | Search everything you can access |
| `bi -c "a quick note"` | Capture a note in your inbox |
| `bi -W` | List workspaces |
| `bi -P` | List projects in the current workspace |
| `bi -B` | List boards in the current project |
| `bi -i` | List issues on the current board |
| `bi -t` | List open tasks in the current task list |
| `bi -s "search words" -j` | Return JSON, also available as `--json` |

Use `-w`, `-p` and `-b` to choose a workspace, project and board, or set defaults with `bi use`. Each quick action runs separately; use the full commands for more options.

## Sign in

```sh
brainitive login                                   # Brainitive Cloud
brainitive login --server brainitive.example.com   # your own server
```

Your browser opens on your Brainitive instance. Approve the access and go back to the terminal —
nothing to copy. The CLI appears under **your avatar → Profile settings → Connected apps**, where you
can revoke it; changing your password disconnects it too.

For scripts and CI, skip the browser and set two environment variables: `BRAINITIVE_SERVER` and
`BRAINITIVE_TOKEN` (a token from Connected apps, or `php artisan mcp:get-token <email>` on a
self-hosted server).

## Set your defaults once

```sh
brainitive workspaces
brainitive use acme/shop/main-board
```

With that, the commands below know which workspace, project and board you mean. You can always
override with `--workspace`, `--project` and `--board`, and if you only have one workspace or
project, nothing is needed.

## Everyday commands

| You want to… | Command |
|---|---|
| Jot something down to sort later | `brainitive capture "Ask Dana about Q4"` · `pbpaste \| brainitive capture` |
| Create an issue | `brainitive issue create Checkout misaligned --type Bug --priority High --label ui --assignee me` |
| …with a screenshot | `brainitive issue create "Crash on save" -f crash.md --screenshot shot.png` |
| See the board | `brainitive issues` · `brainitive issue show SHOP-12` |
| Move, comment, change | `brainitive issue move SHOP-12 --to Done` · `brainitive issue comment SHOP-12 "Fixed"` · `brainitive issue update SHOP-12 --priority Highest` |
| Tasks | `brainitive task add Renew the domain --due 2026-12-01` · `brainitive tasks` · `brainitive task done 3` |
| Snippets | `brainitive snippet add "Reset sequence" -f reset.sql` · `brainitive snippets` |
| Bookmarks | `brainitive bookmark add https://go.dev --title Go` · `brainitive bookmark note "remember this"` |
| Docs | `brainitive pages` · `brainitive page get <uuid>` · `brainitive page create "Notes" -f notes.md --publish` |
| Search | `brainitive search "payment failed" --type issues --mine` |
| Project setup | `brainitive project config` · `brainitive project create "Shop" --key SHOP --columns "Backlog,Doing,Done"` |

Every command prints a readable table. Add `--json` to get the raw result for scripts.

## Everything the server can do: `brainitive tool`

The CLI talks to the same [MCP server](./ai-and-mcp) that Claude and ChatGPT use, so every one of
its tools is available — including the ones that set up a project (statuses, labels, types,
priorities, custom fields, members) and create cards in bulk:

```sh
brainitive tools                                     # the catalogue
brainitive tool create-item-field --describe         # arguments of one tool
brainitive tool create-label name=urgent color=#ef4444
brainitive tool create-board-items --args '{"items":[{"title":"A","column":"Done","labels":["urgent"]}]}'
```

## AI tools on your computer: `brainitive mcp`

Some AI tools only know local MCP servers, or can't sign in to a remote one by themselves. Run
the CLI as that local server and it forwards everything to Brainitive with your login:

```sh
claude mcp add brainitive -- brainitive mcp          # Claude Code
```

```json
{ "mcpServers": { "brainitive": { "command": "brainitive", "args": ["mcp"] } } }
```

The second form is what Cursor, VS Code, Codex and similar tools read from their `mcp.json`.

## Self-hosting

Nothing to switch on: the CLI signs in through the same OAuth server as the connectors
(`/.well-known/oauth-authorization-server`), registering itself with a loopback redirect
(`http://127.0.0.1:<port>/callback`), which is always allowed. The installers and the binaries both
come from the GitHub release; every instance's `/install.sh` and `/install.ps1` forward there, so you
can also point people at your own address.
