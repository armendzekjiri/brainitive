---
title: Integrations
description: Everything Brainitive connects to — Google, Microsoft, GitHub, calendars, Meet and Teams, web push, MCP for Claude and ChatGPT, webhooks and imports.
---

# Integrations

Brainitive connects to the tools your team already uses. On **Brainitive Cloud** everything below is ready to use.
On a **self-hosted** instance, some integrations need an OAuth app or a few environment variables first. Each
page explains both.

| Integration | What it does | Cloud users | Self-hosted needs |
|---|---|---|---|
| [Google](./google) | Sign in, Google Calendar, Google Tasks, Google Meet | Ready | Google OAuth client: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (+ Calendar and Tasks APIs) |
| [Microsoft](./microsoft) | Sign in, Outlook / Microsoft 365 calendar, Teams | Ready | Entra app: `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_TENANT` |
| [GitHub](./github) | Sign in, and branches / commits / PRs / deployments on issues | Ready | OAuth app: `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`. GitHub App: **Admin → GitHub App** or `GITHUB_APP_*` |
| [Calendars](./calendars) | Google, Outlook, iCloud / CalDAV, ICS links, plus a feed for other apps | Ready | Google / Microsoft as above. CalDAV and ICS: nothing (optional `CALENDAR_*`) |
| [Meetings](./meetings) | Google Meet and Teams links from issues, docs, the calendar and booking pages | Connect a calendar | Google / Microsoft as above |
| [Web push](./web-push) | Desktop and mobile notifications | Ready | `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, HTTPS |
| [AI and MCP](./ai-and-mcp) | Claude, ChatGPT and other apps over MCP, plus your own AI provider in the editor | Ready (AI: your provider key) | Nothing for MCP (optional `MCP_REDIRECT_HOSTS`). Local models: `AI_ALLOW_PRIVATE_ENDPOINTS` |
| [Outgoing webhooks](./webhooks) | Signed events for Zapier, Make, n8n or your own service | Ready | A running queue worker |
| [Browser extension](./browser-extension) | Save pages, issues, bookmarks, docs, tasks and snippets | Install the extension | HTTPS |
| [Command line (CLI)](./cli) | Capture, issues, tasks, snippets, docs and search from the terminal; an MCP bridge for local AI tools | `curl -fsSL https://brainitive.app/install.sh \| sh` | Nothing |
| [Imports](./imports) | Jira, Trello, Notion, Brainitive project exports | Ready | A running queue worker |

::: tip After changing environment variables
Restart Brainitive so it reads the new values (with Docker: `docker compose up -d`). See
[Configuration](/self-hosting/configuration) for where the variables go.
:::
