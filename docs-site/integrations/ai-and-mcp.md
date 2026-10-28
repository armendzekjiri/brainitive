---
title: AI and MCP
description: Connect Claude, ChatGPT and other AI apps to Brainitive over MCP, and use your own AI provider (OpenAI, OpenRouter, Ollama…) in a workspace.
order: 70
---

# AI and MCP

Brainitive works with AI in two independent ways:

- **MCP connector:** AI apps such as Claude or ChatGPT read and update your workspace on your behalf.
- **Workspace AI:** writing helpers inside Brainitive, using an AI provider that your workspace chooses and pays
  for.

## Connect an AI app over MCP

Brainitive runs a remote MCP (Model Context Protocol) server at:

```text
<your Brainitive URL>/mcp
```

For example, `https://brainitive.example.com/mcp`. On Brainitive Cloud, use the address you sign in at, followed
by `/mcp`.

1. In your AI app, add a **custom connector / MCP server** with that URL. Claude (web, desktop, mobile), ChatGPT,
   Perplexity, Cursor and VS Code can all do this.
2. The app sends you to Brainitive. Sign in and check the page: it shows where you'll be sent back to. Only
   continue if you started the connection yourself.
3. Approve the access. The app registers itself automatically (OAuth 2.1 with dynamic client registration), so
   you don't need an API key.

The AI app can only see and change what **you** can access in Brainitive. To see or revoke connected apps, open
**your avatar → Profile settings → Connected apps**. Changing your password disconnects all of them.

### What the connector can do

| Area | Tools |
|---|---|
| Navigation and search | List workspaces and projects, project details and members, search across content, fetch any result |
| Docs | List, read, create and update pages (Markdown in, converted automatically) |
| Boards and issues | List boards, read a board or an issue, create, update, move and delete issues, read and add comments |
| Sprints | List, read, create, update, start and complete sprints, and move issues into a sprint |
| Plans (Gantt) | List, read and update plans |
| Task lists | List and read task lists, create and update tasks |
| Snippets and bookmarks | List, create and update snippets and collections, list bookmarks, add bookmarks |
| Calendar | List connected calendars and events, create, update and delete events, list, create and update Google Tasks |

Every tool that creates or changes something answers with where the content lives and a link to it.

### Self-hosting the MCP server

There is nothing to switch on. The server signs tokens with OAuth keys, which the Docker image creates on first
start and keeps in the storage volume. Without Docker, run `php artisan passport:keys` once. Your instance must be
reachable over HTTPS from the AI app.

When an app registers, Brainitive only accepts sign-in redirects to known clients: Claude, ChatGPT, Perplexity,
Cursor, VS Code, the browser extension, and `localhost`. To allow another client, add its callback host:

```ini
MCP_REDIRECT_HOSTS=app.example.com,*.example.org
```

## Workspace AI (bring your own provider)

The AI writer in issue and doc descriptions (**Improve description**, **Improve writing**, **Generate prompt**…),
the AI assistant on the project overview, **Extract action items** from meeting notes, and drafted status updates
all use the AI provider that you set up for the workspace.

1. As a workspace admin, open **your avatar → Workspace settings → AI**.
2. **API Endpoint:** the base URL of any OpenAI-compatible API. Examples are `https://api.openai.com`,
   OpenRouter, Azure, or a local Ollama / LM Studio server.
3. **API Key:** your provider's key. It is stored encrypted and never sent to the browser. A key is always
   required, so for a local server that doesn't check keys, enter any placeholder.
4. Click **Fetch Available Models**, choose a model, then **Save AI Settings**.

Brainitive calls `<endpoint>/v1/chat/completions` and `<endpoint>/v1/models`. If your endpoint already ends in
`/v1`, it isn't added twice.

### Self-hosting: local models

By default, Brainitive refuses AI endpoints on `localhost` or private networks, so that workspace admins can't make
the server call internal services. To use a model running next to Brainitive, such as Ollama, allow it:

```ini
AI_ALLOW_PRIVATE_ENDPOINTS=true
```
