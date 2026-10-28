---
title: Boards and issues
description: "Plan work on boards in Brainitive: issues, columns, assignees, labels, priorities, due dates, subtasks, comments, filters and views."
order: 15
---

# Boards and issues

A **board** shows a project's work as cards in columns, like Trello or Jira. Each card is an **issue** with a key such as `WEB-42`, a description, an assignee, dates, comments and a full history.

## Create a board

1. In the sidebar, click **Add content** under your project and choose **Board**.
2. Enter a **Board Name** and click **Create Board**.

A project can have as many boards as you like. To use sprints and a backlog, turn on **Use sprints (Scrum)** in the board settings, see [Sprints and backlog](./sprints-and-backlog).

## Columns

Columns are the steps of your workflow, for example *To do → In progress → Review → Done*.

- **Add a column:** click **New column** on the board, type a name and press <kbd>Enter</kbd>. You can add the next one right away.
- **Rename a column:** open the column's **⋯** menu → **Rename column**.
- **Limit work in progress:** in the column's **⋯** menu set **Max. cards**. The header then shows "3 / 2" and turns red when the column is over its limit. The limit is a warning, not a lock.
- **Reorder, recolor or delete columns:** open the board settings (gear icon next to the board name), or **⋯ → Edit columns…**.

### The "Done" column

In the board settings, edit a column and tick **Mark as "Done" column**. Moving an issue into a done column marks it as completed, so it no longer counts as overdue. Moving it out again reopens it.

## Issues

### Create an issue

- Click **Create** in the top bar. It creates the card on the board you're on.
- Or click **+** at the bottom of a column and type what needs to be done.

New issues start with priority **Medium**.

### Move an issue

Drag the card to another column. On the board, you can also click the round assignee picture on a card to assign someone without opening the issue.

### The issue panel

Click a card to open it on the side. **Full page** opens it on its own page with its own link.

At the top you'll find the title (click to edit), the status button and the quick actions **Attach**, **Create subtask**, **Link issue** and **Meeting**.

**Description:** double-click to edit. Use the full editor: headings, lists, tables, images, polls, `@`-mentions and [smart links](./docs#smart-links). Several people can write in the same description at the same time.

**Details** on the right:

| Field | What it is |
|---|---|
| **Assignee** | Who works on it. **Assign to me** sets you. |
| **Type** | Task, Bug, Story, Epic, Subtask or your own types (Project settings → Item types) |
| **Parent** | The issue this one belongs to |
| **Priority** | Highest, High, Medium, Low or Lowest |
| **Fix version** | The release it ships in |
| **Points** | Story points |
| **Estimate** / **Time tracking** | Original estimate and time spent, with **Log work** |
| **Start Date** / **Start Time**, **Due Date** / **Due Time** | Plan when it happens. Due dates turn amber when close and red when overdue. |
| **Reporter** | Who created it |
| **Watchers** | Who gets notified about changes |
| **Labels** | Search or create labels |

Your project's custom fields (Project settings → Fields) appear in the details as well.

### Subtasks

Click **Create subtask** or type in **Add a subtask…** in the panel. Subtasks can have their own subtasks, up to three levels (issue → subtask → sub-subtask). The panel shows the parent path above the title.

- Moving an issue to Done while subtasks are still open asks whether to complete them too.
- Deleting an issue also deletes its subtasks.
- Moving an issue to another sprint or board takes its subtasks along.

### Linked issues

Click **Link issue**, pick a **Link Type** and the **Target Issue**. Types: *blocks*, *is blocked by*, *relates to*, *duplicates*, *is duplicated by*, *clones*, *is cloned by*. Issues that are blocked show up under the **Blocked** quick filter.

### Comments, history and work log

The **Activity** section at the bottom has tabs:

- **Comments:** write with `@`-mentions, reply to a comment to start a thread. Comments from others appear instantly, and you see who is typing.
- **History:** every change, with who and when: creation, field changes, moves, assignee, labels, comments, links, attachments, logged work and sprint changes.
- **Work log:** time logged on the issue.
- **All:** everything together.

Anyone who can open the project can comment, including viewers.

### Attachments

Click **Attach** to upload files. Images and PDFs can be previewed in Brainitive; every file can be downloaded.

### Watchers and notifications

The reporter and the assignee watch an issue automatically. Add or remove watchers under **Watchers** in the details. Watchers hear about comments and changes; you choose how (in-app, push, email) in **Profile → Notifications**.

### Log time

Click **Log work** in the details (or under Time tracking), enter the **Time spent** like `45m`, `1h 30m` or `1d` (1d = 8h, 1w = 5d), pick the **Date** and optionally what you worked on. The progress bar compares time spent with the estimate. See [Time tracking and billing](./time-tracking-and-billing).

### More actions

- **Duplicate…** (card menu or panel): copies description, type, priority, assignee, dates, estimate and labels. Comments, attachments, watchers, logged time and history start fresh. With subtasks you choose whether to copy them too.
- **Make this recurring:** turns the issue into a repeating one (see below).
- **Share:** creates a read-only link for people outside Brainitive. It shows only that issue, no comments or people. Choose an expiry (1, 7, 30 or 90 days, or never) and revoke it any time.
- **Meeting:** schedules a Google Meet or Microsoft Teams meeting for the issue from your connected calendar.

## Recurring issues

For work that comes back (weekly reports, monthly invoices):

1. Open the board's menu next to its name → **Recurring issues**, or open an issue → **Make this recurring**.
2. Choose how often: daily, weekly on chosen days, every N weeks, monthly (a day, or for example the last weekday) or yearly.
3. Set the assignee, priority, labels and due date for the new issues.

## Board views

The tabs under the board name switch the layout:

| View | Best for |
|---|---|
| **Board** | Columns and cards, drag and drop |
| **List** | A table you can sort by clicking a column header (key, title, status, priority, assignee, due date, story points, custom fields). **Fields** chooses which custom fields show as columns. |
| **Timeline** | Issues as bars between start and due date, with parents and subtasks you can collapse |
| **Calendar** | Issues on their due dates |

On Scrum boards you also get **Backlog** and **Reports**, see [Sprints and backlog](./sprints-and-backlog).

### Bulk edit

In the **List** view, tick issues (shift-click selects a range, the header box selects everything visible). A bar appears where you change status, assignee, priority, type, sprint, due date or labels for all of them at once, or delete them. <kbd>Esc</kbd> clears the selection.

### Swimlanes

Split the board into rows. Use **Swimlanes** in the board toolbar to switch for yourself: lanes by assignee, priority, type or fix version, or the board's custom lanes. In "by assignee / priority / …" lanes, dragging a card to another lane changes that field too.

Board managers set up **Custom lanes** in the board settings → **Swimlanes**: add lanes with conditions (priority, type, label, assignee, fix version or overdue). A card goes into the first lane that matches, everything else into the last one. Example: "🔥 Urgent" (Highest or High) → "Bugs" → "Everything else".

## Find the right issues

### Search and quick filters

The toolbar above the board has:

- **Search board** to filter cards by text,
- the team's pictures, to show one person's issues with a click,
- quick filters:
  - **Only my issues**,
  - **Recently updated** (last 7 days),
  - **Due soon** (due within 3 days, or overdue),
  - **Blocked** (has an "is blocked by" link).

### The filter menu

Open **Filter** to narrow by status (column), labels, priority, type, assignee (anyone, **Unassigned** or a person) and your custom fields.

Filters are part of the page address, so you can bookmark a filtered board or send the link to a colleague.

### Saved views

Save the way you look at a board (filters, layout, list sorting and hidden columns) as a named view from the **Views** menu in the toolbar.

- Views under **My views** are only yours. Board managers can also share views with everyone (**Team views**).
- A dot and "(unsaved changes)" show that you've changed a view. Save, rename, duplicate, share or delete it from the menu.
- Mark a view as **Your default** (★) to open the board with it. Choose "All issues" to get back to the plain board.
- "Only my issues" in a shared view shows each person their own issues.

## Working together live

Changes by teammates, automations, the AI connector and imports show up on your open board right away, in every view, without losing your filters or the card you're dragging. Avatars on an issue show who else is looking at it.

## Who can do what

People with the **Editor** or **Admin** role in the project create and edit issues and boards; **Viewers** can read and comment. Only project admins delete boards and other people's issues. See [Roles and permissions](./workspaces-and-projects#roles-and-permissions).
