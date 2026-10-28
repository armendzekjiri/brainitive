---
title: AI and MCP
description: "Use AI inside Brainitive docs and issues with your own provider, and connect Claude or ChatGPT over MCP to read and update your workspace."
order: 82
---

# AI and MCP

Brainitive works with AI in two ways: an **AI writer** inside docs and issues that uses your workspace's own AI provider,
and an **MCP connector** that lets assistants like Claude or ChatGPT work with your Brainitive content.

Both only ever see what you yourself can open.

## Set up the AI provider (workspace admins)

The in-app AI uses an AI provider your workspace chooses ("bring your own key"). In **Workspace settings → AI**:

1. Enter the provider's endpoint (an OpenAI-compatible API, for example `https://api.openai.com`) and your API key.
2. Click **Fetch Available Models** and **Select Model**.
3. Click **Save AI Settings**.

Until this is done, the AI buttons don't appear.

## The AI writer in docs and issues

In a doc or an issue description, click **AI** in the editor toolbar. Type what you want in **Tell AI what to do...**, or
pick one of the **Actions**, such as Improve writing, Fix spelling & grammar, Make concise, Make formal, Simplify
language, Summarize writing or a translation. Select text first to work on just that part.

The AI knows your project's name and description, so its answers fit your project. You see the result first and decide
whether to use it — for example with **Replace**, or **Retry** for another answer. **Generate prompt** turns the text
(in an issue, together with its title) into an implementation prompt for a coding assistant.

## The project assistant

On the project page, under **Tools**, the AI assistant can **Summarize project**, **Draft a task** or write an
**Implementation prompt**, all from the project description. Review the result, then copy it, **Save as draft doc**, or
(for a summary) **Use as project description**.

## Meeting notes → issues

Turn meeting notes into issues:

- In a doc, open **⋯ → Extract action items**, or
- on the project page, under **Tools**, use **Meeting notes → issues** and paste your notes.

The AI suggests each action item with the sentence it came from, an owner (only project members) and a due date (only
when the notes are clear about it). Possible duplicates are marked. You edit, pick and create only what you want —
clicking **Create** twice never creates issues twice.

## Connect Claude, ChatGPT and other assistants (MCP)

Brainitive has a built-in MCP server, so AI assistants that support MCP connectors can read and update your workspace:
search, read and write docs, create and update issues, comment, plan sprints, edit Gantt plans, manage task lists and
look at your calendar.

1. In your AI app, add a new custom connector (MCP server).
2. Use your Brainitive address followed by `/mcp`, for example `https://brainitive.example.com/mcp`.
3. The app sends you to Brainitive to sign in and approve the connection.

::: warning
The approval screen shows where you will be sent back to. Only continue if you started the connection yourself.
:::

The assistant acts as you: it can only see and change what you can, and every change it makes shows up like one of
yours (for example in the issue history). When it creates or updates something, it gets a link back to the item.

### See and disconnect apps

**Profile settings → Connected apps** lists every AI app, the browser extension and the command-line tool that can access
your account, with what they may do. Click **Disconnect** to remove one. Changing your password disconnects all of them.

## Related

- [Search and smart links](./search)
- [Apps, extension and shortcuts](./apps-and-shortcuts)
