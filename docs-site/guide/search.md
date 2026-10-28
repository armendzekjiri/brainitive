---
title: Search and smart links
description: "Find anything in Brainitive with ⌘K or the search page: issues, docs, comments, tickets, tasks and files, with filters and exact phrases. Plus smart links."
order: 70
---

# Search and smart links

Brainitive searches everything you can open: issues, docs, comments, helpdesk tickets, list tasks, plans, snippets,
bookmarks, your own captures and the names of attached files. Smart links then let you point to that work from any doc
or issue.

## Quick search with ⌘K

Press **⌘K** (Mac) or **Ctrl+K** (Windows, Linux) anywhere. The command palette opens:

- With nothing typed, it shows what you opened recently, plus navigation and "Create" commands.
- Type to see the best matches. Results from the project you're in come first.
- Choose **See all … results** at the bottom to open the full search page.
- Type **>** first (or press **Ctrl+.**) to see commands only — go to My Work, Calendar or Inbox, switch workspace,
  create an issue, page, snippet or bookmark, quick capture, help and more.

Use **↑** and **↓** to move, **Enter** to open and **Esc** to close. On a phone, tap the search field at the top.

## The search page

The search page (at `/search`) shows all results, ranked, with your search words highlighted. Press **/** to jump into
the search field.

### Narrow it down

- **Tabs** split the results by type, with counts: Issues, Docs, Comments, Tickets, Tasks, Plans, Snippets,
  Bookmarks, Captures and Files.
- **Filter chips**: **Project**, **Status** (Open or Done), **Assignee** (including **Assigned to me**) and **Updated**
  (Past week, Past month, Past 3 months, Past year).

Filters live in the page address, so you can bookmark or share a search.

### Search tips

| Type | To find |
|---|---|
| `WEB-12` | That issue (or ticket) directly |
| `"exact phrase"` | Words in exactly that order |
| `-word` | Results without that word |
| `onbord` | Small typos are fine — Brainitive still finds "onboarding" |

You only ever find what you are allowed to open. Private drafts of other people never show up. If something is
missing, ask a project admin whether it should be shared with you.

::: tip Self-hosted?
After an update, the search index builds itself in the background. On a big instance, results can be incomplete for a
little while.
:::

## Smart links

A smart link is a live link to an issue, doc, ticket or plan inside a document or issue description. Instead of a bare
URL it shows the title, status, due date and assignee — and stays up to date.

### Add a smart link

- **Paste** a link to Brainitive content into a doc or description. It turns into a smart link (a toast offers
  **Undo to plain link**). To paste as a plain link instead, use **Ctrl+Shift+V** (**⌘⇧V** on Mac).
- Or type **/link**, or use the **+** next to a block, and search for the issue, doc, ticket or plan.

### Inline or card

Hover a smart link to switch between **Inline** (a small chip in the text) and **Card** (a bigger card with title,
key, status, assignee, due date, priority and project). You can also turn it back into a **Plain link**, open it or remove it.

If the linked item is deleted or you don't have access to it, the smart link says so instead of showing its content.

### Referenced by

Every issue, doc, whiteboard, ticket and plan shows **Referenced by**: the docs and issues that link to it, one click
away. You can also connect related work by hand with **Link related content**.

## Related

- [Apps, extension and shortcuts](./apps-and-shortcuts) – more keyboard shortcuts.
- [Docs](./docs)
