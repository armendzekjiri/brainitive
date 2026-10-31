---
title: Workspaces and projects
description: "Organize work in Brainitive with workspaces and projects: create them, invite members, choose roles and permissions, and change workspace settings."
order: 10
---

# Workspaces and projects

Everything in Brainitive lives in a **workspace** (your team or company), and inside it in **projects** (one per product, client or initiative). This page shows how to set both up and who can do what.

## How content is organized

```
Workspace  (team, members, settings)
└── Project  (members, labels, issue types, fields)
    ├── Boards, Lists, Plans
    ├── Pages (docs), Whiteboards, Folders
    └── Helpdesks, Lead forms
```

Bookmarks and Snippets belong to the workspace, not to a project.

## Workspaces

### Create a workspace

1. Open the **workspace switcher** at the top of the sidebar and choose **Create workspace** (or click **New Workspace** on the Workspaces page).
2. Enter a **Workspace Name** and, optionally, a description.
3. Click **Create Workspace**.

You become the workspace's **owner** and an admin. There's no special personal workspace: if you want a space just for yourself, create a workspace and don't invite anyone.

### Switch workspaces

Click the workspace name at the top of the sidebar. The menu lists your workspaces, **All Workspaces** and **Create workspace**. You can also press <kbd>⌘K</kbd> / <kbd>Ctrl+K</kbd> and type "Switch workspace".

### Invite people

Workspace admins invite members:

1. Open **your avatar → Workspace settings → Members**.
2. Click **Invite Members**.
3. Enter the **Email Address**, pick a **Role** and click **Send Invite**.

What happens next depends on the person:

- **They already have an account** on this Brainitive: they're added right away.
- **They don't have an account yet**: Brainitive asks **Send invitation?**. Confirm with **Send invitation**. They get an email with a link that is valid for **30 days**, and they join once they've signed up and verified their email address.

Pending invitations are listed under **Pending invitations** with their role and expiry date. **Cancel invite** makes the link stop working.

::: tip
Invited people can sign up even when the installation is otherwise invite-only.
:::

### Change a role or remove a member

In **Workspace settings → Members**, use the role menu next to a person to change their role, or the remove button to take them out of the workspace. Removed members lose access to all of its projects and boards. The owner always stays.

## Roles and permissions

Brainitive has roles on two levels: the **workspace** and each **project**.

### Workspace roles

| Role | What it allows |
|---|---|
| **Owner** | Everything an admin can do, plus deleting the workspace. One per workspace. |
| **Admin** | Change workspace settings; invite and remove members and change roles; open **every** project; create and delete projects; billing rates, Insights and webhooks. |
| **Editor** | Create new projects. |
| **Member** | Be part of the workspace; see the projects you're added to. |
| **Viewer** | Be part of the workspace; see the projects you're added to. |

::: tip Projects are private by default
Apart from workspace admins, people only see a project once they're added to it. What they can do inside a project depends on their **project role**, not their workspace role.
:::

### Project roles

Add people to a project in **Project settings → Members → Add workspace member**, then choose their role:

| | Viewer | Editor | Admin |
|---|:-:|:-:|:-:|
| Open the project and read everything | ✓ | ✓ | ✓ |
| Comment on issues | ✓ | ✓ | ✓ |
| Create and edit issues, boards, docs, whiteboards, lists and plans | | ✓ | ✓ |
| Delete issues they created | | ✓ | ✓ |
| Delete any issue, board, list or plan | | | ✓ |
| Project settings, members and automations | | | ✓ |

Workspace admins have admin rights in every project. Whoever creates a project becomes its admin.

Unpublished draft docs are visible only to their author and admins, whatever the role. See [Docs](./docs).

## Projects

### Create a project

1. Click **New project** on the workspace page or **Create project** in the sidebar, or press <kbd>⌘K</kbd> and choose **Create Project**.
2. Pick a template from the gallery, or start blank. Templates are grouped by category (Software, Marketing, Agency & clients, HR & people, Sales, Operations & finance, Personal & team). Open one to see exactly what you get: workflow, issue types, fields, labels, starter issues, docs, checklists and plans.
3. Enter a name. For templates, choose a **start date** (due dates and plan bars move with it) and leave out parts you don't need.
4. Create the project.

You need to be a workspace admin or editor to create projects. Templates saved by your team show up under **Your workspace**.

### The project key

Every project has a short **key**, for example `WEB`. Issues are numbered with it: `WEB-1`, `WEB-2` … You set it when you create the project and can change it in **Project settings → General**.

### The project page

Click a project in the sidebar to open its overview: members and quick links on top, then **Pinned**, **Recently updated**, **Work** (boards, lists, plans), **Docs** (pages and whiteboards), **Customer-facing** (helpdesks, lead forms) and **Tools** (for example [Automations](./automations)).

### Add content to a project

Click **Add content** under the project in the sidebar and choose:

| Type | Use it for |
|---|---|
| **Board** | Issues on a Kanban or Scrum board. [Boards and issues](./boards-and-issues) |
| **List** | Simple to-do lists with due dates and reminders. [Task lists](./task-lists) |
| **Plan** | A Gantt timeline. [Plans](./plans) |
| **Page** | A document or wiki page. [Docs](./docs) |
| **Whiteboard** | Drawing and diagrams. [Whiteboards](./whiteboards) |
| **Folder** | Grouping pages and whiteboards |
| **Helpdesk** | Customer support tickets |
| **Lead form** | A public form whose answers land on a board |

### Project settings

Project admins open the settings with the gear on the project page. Tabs:

| Tab | What's there |
|---|---|
| **General** | Icon, **Project name**, **Project key**, **Client** (for billing), color, description; **Save as template** (workspace admins) and **Export project** |
| **Labels** | The labels you can put on issues |
| **Item types** | Issue types such as Task, Bug, Story, Epic, Subtask; switch default types off or add your own with an icon |
| **Fields** | Custom fields for issues: text, number, date, yes/no checkbox or a list of options |
| **Members** | Who can open the project and with which role |
| **Integrations** | Connect GitHub. [GitHub integration](/integrations/github) |
| **Danger zone** | Move the project to another workspace, or delete it |

**Move to another workspace** takes everything along: boards and issues, docs, whiteboards, plans, task lists, helpdesks, labels and issue types. You need to be an admin of both workspaces, and the other workspace must not already use the project key.

## Workspace settings

Workspace admins open **your avatar → Workspace settings**. The tabs on the left:

| Tab | What's there |
|---|---|
| **General** | Name, website, description, industry, company size; **Regional Settings** (timezone, language, currency); social links |
| **Branding** | Brand colors. Upload a logo by clicking the round workspace picture at the top. |
| **Members** | Team members, roles, invitations (see above) |
| **AI** | Connect your own AI provider for the writing helpers in docs and issues |
| **Legal** | Your organisation's legal name, privacy policy and imprint links, shown on your public pages |
| **Webhooks** | Send events to your own systems |
| **Danger zone** | **Delete this workspace** (owner only, can't be undone) |

## Customize your sidebar

Choose **your avatar → Customize sidebar** to hide entries you don't use, choose which groups each project shows, and pin the boards, lists and docs you open every day. Hidden entries are still reachable with <kbd>⌘K</kbd>.

- **Pinned** items show at the top of the project, above everything else. Pinning doesn't hide the rest; turn on **Show only pinned** if you want just your pins.
- **Reorder pins** by dragging them in the sidebar's Pinned group, or with **Move up / Move down** in Customize sidebar. The project page uses the same order.
- **Project tools:** Health, Workload and Releases have their own entries in the project sidebar. Show or hide each one under **Customize sidebar → Project tools**.
