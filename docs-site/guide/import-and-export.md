---
title: Import and export
description: "Move to Brainitive from Trello, Jira or Notion, export a project as a ZIP and restore it on any Brainitive, and download a copy of all your personal data."
order: 90
---

# Import and export

Bring your work over from other tools, take a project with you, or download everything Brainitive holds about you.

## Import from other tools

Open the account menu and choose **Import data**. Pick what you are importing:

| Source | What to upload | What you get |
|---|---|---|
| **Trello board (JSON)** | In Trello: board menu → **Print, export and share → Export as JSON** | A new board: lists become columns, cards become issues in the same order, with descriptions, due dates, labels and checklists |
| **Jira (CSV)** | In Jira, search the issues and choose **Export → Export CSV (all fields)** | A new board: statuses, types, priorities, epics and sub-tasks, labels, sprints, links between issues, story points and comments |
| **Notion (Markdown & CSV)** | In Notion, export a page or workspace as **Markdown & CSV** (with subpages) and upload the ZIP | Pages and folders with links between pages, images and files; databases become tables. Everything arrives as drafts |
| **Brainitive project export** | A ZIP from another project's export (below) | A new project |

### How an import works

1. Choose the source, the **Project** it goes into (where needed) and the **Exported file**.
2. Check the **Preview**. Nothing is created until you click **Start import**. Depending on the source you can:
   - name the new board, map lists or statuses to columns and choose the **Done column**,
   - map labels, types and priorities,
   - map the people in the export to project members (nobody is invited; unmapped work stays unassigned),
   - for Trello, keep checklists in the description or turn checklist items into subtasks,
   - for Notion, choose where the pages go and whether to import them as drafts.
3. Follow the progress. You can leave the page; the import keeps going.
4. When it's done, click **Open what was imported**.

Every import shows what couldn't come over under **Not imported** (custom fields or attachments, for example), so
nothing disappears silently. Imports don't send a flood of notifications.

If an import stops halfway, click **Retry — continue where it stopped**. It picks up where it left off without
creating duplicates. **Recent imports** on the import page lists your earlier imports.

## Export a project

Project admins can download a whole project as one ZIP file and restore it as a new project — on the same Brainitive or
on another one (for example, when you move from Brainitive Cloud to your own server).

1. Open the project → **Settings** → **General** → **Export project**.
2. The export is prepared in the background. You get a notification when it's ready, and a **Download** button
   appears.
3. The download link works for **24 hours** and only for you.

**Included:** boards, issues (with subtasks, labels, custom fields, comments, attachments and links), docs, folders
and whiteboards, task lists, plans, releases, sprints and the helpdesk structure.

**Not included:** members and roles, helpdesk tickets and contacts, leads, time entries, history, integrations,
webhooks, automations, share links and tokens. The file lists what was left out and why.

### Restore a project

1. Account menu → **Import data** → **Brainitive project export**. You need permission to create projects.
2. Upload the ZIP. Brainitive checks every file first; a changed or incomplete file is refused.
3. In the preview, check the new project's name and **Key** (it must be free), and who is who. People are matched to
   workspace members by email; pick someone else or **Nobody (unassigned)** for each. Mapped people become editors of
   the new project. Nobody is invited.
4. Click **Restore as a new project**.

Issues get new keys; links, subtasks, the page tree, whiteboard issue cards and plan rows point to the new copies.
Comments keep their original author ("Originally by …").

## Download my data

Get a copy of everything Brainitive holds about your account (your right under GDPR Art. 15 and 20).

1. Open your profile (account menu → **Profile settings**) and go to **Your data**.
2. Click **Download my data**.
3. When the ZIP is ready, you get a notification and an email. Click **Download**.

The ZIP contains your profile and settings, the issues, comments, pages, whiteboards and tasks you created, your time
entries, notifications, devices, connected calendars and apps, and your uploaded files. The link works for 24 hours, only
for you.

## Related

- [Your profile and account](./your-profile)
- [Workspaces and projects](./workspaces-and-projects)
- [Self-hosting](/self-hosting/)
