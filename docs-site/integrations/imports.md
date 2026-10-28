---
title: Imports (Jira, Trello, Notion)
sidebarTitle: Imports
description: Import Jira issues (CSV), Trello boards (JSON) and Notion pages (Markdown & CSV) into Brainitive, or restore a Brainitive project export.
order: 100
---

# Imports (Jira, Trello, Notion)

You can bring existing work into Brainitive from an exported file. Open **your avatar → Import data**, pick the
source and the target project, then upload the file. The import runs in the background, and **Recent imports**
shows its progress and result.

| Source | Export from the tool | What comes over |
|---|---|---|
| **Jira (CSV)** | In Jira, search the issues and choose **Export → Export CSV (all fields)** | A new board with statuses, types, priorities, epics and sub-tasks, labels, sprints, links and comments |
| **Trello board (JSON)** | Board menu → **Print, export and share → Export as JSON** | A new board with lists, cards, labels, checklists and due dates |
| **Notion (Markdown & CSV)** | Export a page or workspace as **Markdown & CSV**, with subpages, and upload the ZIP | Pages, subpages, images and files as draft pages, and databases as tables |
| **Brainitive project export** | **Project settings → Export project** (ZIP) | A new project with its boards, issues, docs, task lists, plans and attachments |

Jira, Trello and Notion imports go into a project that you can edit. A Brainitive project export always becomes
a new project. Imports don't send webhooks or run automations for each imported record.
