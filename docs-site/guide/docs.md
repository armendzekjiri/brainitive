---
title: Docs
description: "Write docs and wiki pages in Brainitive: editor, slash commands, page icons, smart links, drafts and publishing, reviews, sharing and history."
order: 30
---

# Docs

Docs (also called pages) are your project's wiki: specs, meeting notes, how-tos. You write on a clean, full-width page; drafts stay private until you publish, and every published version is kept.

## Create a doc

1. Click **Add content** under the project in the sidebar and choose **Page**.
2. Give it a name. The doc opens in the editor.

Organize docs in a tree: **Add Subpage** inside a doc, or create a **Folder** from **Add content** and put docs and whiteboards in it.

To move a doc, whiteboard or folder:

- **Drag it in the sidebar.** Drop it in the middle of a folder to put it inside; the folder lights up and shows where it goes. Drop it above or below a row to change the order. When it changes folder, Brainitive asks first; **Cancel** leaves it where it was.
- **Use Move…** in the ⋯ menu (in the sidebar or at the top of the doc). Search the project's folders, pick one or **Top level**, and the full path shows before you move.

A folder can't go into one of its own subfolders. Viewers can't move content.

## Writing

Start typing. The page title is edited in place; <kbd>Enter</kbd> jumps into the text.

### The floating toolbar

Select text to get a small toolbar: bold, italic, strikethrough, highlight, code, headings, quote, checklist and link. Prefer the classic toolbar? Click the **Show formatting toolbar** button while editing (remembered per browser).

### Slash commands

Type <kbd>/</kbd> on an empty line ("Start writing, or press / for commands…") to insert:

| Text and structure | Media and more |
|---|---|
| Paragraph, Heading 1–3 | Image, GIF, Emoji |
| Bullet List, Numbered List, Checklist | YouTube Video, Figma Document, X (Tweet) |
| Quote, Code Block | Table, Columns Layout, Collapsible |
| Horizontal Rule, Page Break | Excalidraw drawing, Equation |
| Sticky Note | Poll |
| | Link to issue, doc, ticket or plan |

YouTube, Figma and X embeds load only after a click, so nothing is sent to those services until you choose to.

### The "+" next to a block

Hover over a line and click **+** (**Add block below**) to insert a Paragraph, Heading 1–3, Quote, Code Block, Bullet List, Numbered List or Checklist below it, or **Link to issue, doc…** for a smart link.

### Polls

Insert a **Poll** to let readers vote. Everyone who can read the doc can vote, no edit mode needed. One vote per person: click another option to change your vote, or the same one again to take it back.

### Mentions

Type `@` and a name to mention a teammate.

## Smart links

Link issues, docs, whiteboards, tickets and plans right inside a doc. They show live: title, status, due date and assignee update by themselves.

Three ways to add one:

- **Paste** a Brainitive link. It turns into a smart link ("Pasted as a smart link"); click **Undo to plain link** if you wanted a normal link.
- Type **/link** and search by title or key ("Search by title or key…").
- Click **+** next to a block → **Link to issue, doc…**.

Each smart link can show as **Inline** (a chip in the sentence), **Card** (a larger box) or **Plain** (an ordinary link). Click it to **Open** the item.

The linked issue or doc lists your doc under **Referenced by**, so links work in both directions. If someone can't open the linked item, they see "Restricted" instead of its title. Smart links also work in issue descriptions.

## Page icons

Click the icon next to the title (**Change icon**) and pick an **😀 Emoji** or upload a **🖼 Custom Image**. The icon shows in the sidebar and in lists.

## Full width

Docs open full width. Click **Exit full width** for a narrower, centred column. Your choice is remembered for that doc in your browser; **Full width** switches back.

## Drafts and publishing

Docs follow a draft → publish workflow:

1. **A new doc is a draft.** Only you and the project's admins can see it. Brainitive saves as you type ("Draft saved").
2. Click **Publish** to make it visible to everyone in the project.
3. **Edit a published doc:** click **Edit** (or **Continue editing** if you left a draft). Your edits are an autosaved draft; readers keep seeing the published version.
   - **Close** keeps your draft for later.
   - **Update** publishes your changes. Tick **Notify watchers** to let people watching the doc know.
   - **⋯ → Update with change note…** adds a short note ("What changed?") to the version.
   - **⋯ → Discard unpublished changes** throws your draft away.

A badge **Unpublished changes** reminds you of a pending draft.

### More in the ⋯ menu

| Item | What it does |
|---|---|
| **Page history** | Every published version. Preview any version and restore it. |
| **Request review…** | Ask teammates to review the draft (see below) |
| **Unpublish (back to draft)** | Hide the doc again |
| **Archive** | Archive the doc |
| **Duplicate** | Copy the doc. With subpages you choose: the whole tree or just this doc. |
| **Extract action items** | Turn meeting notes into issues (uses your workspace's AI) |
| **Schedule meeting** | A Google Meet or Microsoft Teams meeting for this doc |
| **Export as Markdown / HTML / PDF** | Download the doc |
| **Delete** | Delete the doc |

### Watch a doc

Click **Watch** to follow a doc. **Watching** means you're notified when someone publishes changes with **Notify watchers**.

## Reviews

Want a second pair of eyes before publishing?

1. Open **⋯ → Request review…** and pick reviewers, optionally with a message.
2. Reviewers get a notification, open the draft, comment on selected text and choose approve or request changes.
3. You see the comments as threads next to the text, reply and resolve them.

If you edit the draft afterwards, the review is marked outdated.

Admins can turn on **⋯ → Review required before publish**: then the doc can only be published once the current draft is approved.

## Writing together

Several people can edit the same doc at once: everyone's cursor shows with their name, and changes appear instantly. If live editing isn't reachable you simply edit alone ("Live editing unavailable — editing alone."). Docs also keep working offline and sync when you're back online.

## Sharing outside Brainitive

Click **Share** in the doc's header to create a read-only link for anyone, even without an account:

- It shows only this doc: no comments, people, other content or unpublished drafts.
- Choose when it expires: **Never expires**, or in 1, 7, 30 or 90 days.
- You see who created the link and how often it was opened, and can revoke it any time.

## Templates

Docs don't have their own template gallery. To reuse a doc, **Duplicate** it. Project templates (see [Workspaces and projects](./workspaces-and-projects#create-a-project)) bring starter docs with them, and whiteboards have their own [templates](./whiteboards#templates).

## Who can do what

Project **editors** and **admins** create and edit docs. Drafts are visible only to their author and admins. Admins and a doc's author can delete it. See [Roles and permissions](./workspaces-and-projects#roles-and-permissions).
