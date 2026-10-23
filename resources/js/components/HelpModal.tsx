import React, { useState } from 'react';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { LayoutGrid, FileText, PencilRuler, BookMarked, BrainCircuit, X, History, Sparkles, GanttChart, ListChecks, CalendarDays, Bot } from 'lucide-react';
import { ScrollArea } from '@/components/ui/scroll-area';

interface HelpModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

type TabType = 'welcome' | 'changelog';

/** Overview on the Welcome tab — keep in sync when a module changes. */
const WELCOME_FEATURES = [
    {
        icon: LayoutGrid,
        tone: 'bg-blue-500/10 text-blue-500',
        title: 'Boards: Kanban & Scrum',
        description: 'Drag-and-drop boards with sprints, backlog, burndown and velocity, issue history, WIP limits, time tracking and shareable filters.',
        where: 'Project → Boards',
    },
    {
        icon: GanttChart,
        tone: 'bg-violet-500/10 text-violet-500',
        title: 'Plans (Gantt)',
        description: 'Phases, bars, milestones and dependencies on a timeline. Templates, share link, print — and rows linked to board issues.',
        where: 'Project → Plans',
        isNew: true,
    },
    {
        icon: FileText,
        tone: 'bg-emerald-500/10 text-emerald-500',
        title: 'Docs & Wiki',
        description: 'Distraction-free writing with a floating toolbar and slash commands. Drafts stay private until you publish; every version is kept.',
        where: 'Project → Pages',
    },
    {
        icon: PencilRuler,
        tone: 'bg-pink-500/10 text-pink-500',
        title: 'Whiteboards',
        description: 'Excalidraw with templates (retro, roadmap, story map …), autosave and history. Turn sticky notes into issues and see them as live cards.',
        where: 'Project → Whiteboards',
        isNew: true,
    },
    {
        icon: ListChecks,
        tone: 'bg-sky-500/10 text-sky-500',
        title: 'Lists & My Work',
        description: 'Light to-do lists with due dates, plus one view of everything assigned to you across projects.',
        where: 'Project → Lists · Sidebar → My Work',
    },
    {
        icon: CalendarDays,
        tone: 'bg-orange-500/10 text-orange-500',
        title: 'Calendar',
        description: 'Connect Google Calendar and Tasks, see due dates next to your meetings and move things in place.',
        where: 'Sidebar → Calendar',
    },
    {
        icon: BookMarked,
        tone: 'bg-amber-500/10 text-amber-500',
        title: 'Bookmarks & Snippets',
        description: 'Save links, APIs and reusable text or code snippets in one place — share collections publicly if you like.',
        where: 'Sidebar → Bookmarks / Snippets',
    },
    {
        icon: Bot,
        tone: 'bg-slate-500/10 text-slate-500',
        title: 'AI & MCP',
        description: 'Connect Claude or ChatGPT over MCP to read and update your workspace, or use your own AI provider inside docs and issues.',
        where: 'Workspace settings → AI · MCP: add <your Brainitive URL>/mcp as a connector',
    },
];

const CHANGELOG = [
    {
        version: 'v1.22.0',
        title: 'Share Docs and Issues',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Share a doc or an issue with anyone through a read-only link — from the Share button on the doc or in the issue panel.',
            'The link shows only that doc or issue: no comments, people, other content or unpublished drafts.',
            'Links can expire (1, 7, 30 or 90 days) and be revoked any time; you see who created each link and how often it was opened.',
        ]
    },
    {
        version: 'v1.21.0',
        title: 'Nested Subtasks',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Subtasks can have their own subtasks — up to three levels (issue → subtask → sub-subtask). Add them from the subtask’s panel.',
            'The issue panel shows where an issue sits (parent path above the title); the Parent field and the Create dialogs offer issues and first-level subtasks.',
            'Moving an issue to Done with open subtasks asks whether to complete them too.',
            'Deleting an issue now removes all of its subtasks, and moving it to another sprint or board takes the whole tree along.',
        ]
    },
    {
        version: 'v1.20.0',
        title: 'AI That Knows Your Project',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'The AI writer in issues and docs now gets the project’s name and description as background, so rewrites and prompts fit your project.',
            'New AI assistant on the project overview: summarize the project, draft a task, or write an implementation prompt — all from the project description.',
            'You review every result first, then copy it, save it as a draft doc, or (summary) use it as the project description after confirming.',
            'If the description is too short, the assistant tells you where to add more context. @mentions now also work in issue descriptions.',
        ]
    },
    {
        version: 'v1.19.1',
        title: 'Polls That Count',
        tag: 'Patch',
        date: '2026-10-23',
        items: [
            'Polls in docs and issue descriptions now save votes for everyone who can read them — no edit mode needed.',
            'One vote per person: click another option to change it, or the same one again to take it back. Results show after you vote and stay after a reload.',
            'The poll’s Edit button only shows while you edit the doc or description.',
        ]
    },
    {
        version: 'v1.19.0',
        title: 'Duplicate Issues and Tasks',
        tag: 'Enhancement',
        date: '2026-10-23',
        items: [
            'Duplicate an issue from its card menu or the issue panel. With subtasks you choose: copy them too, or only the issue.',
            'The copy keeps description, type, priority, assignee, dates, estimate and labels; comments, attachments, watchers, logged time and history start fresh.',
            'Tasks in lists can be duplicated too; the copy appears right below the original.',
        ]
    },
    {
        version: 'v1.18.1',
        title: 'Friendly Error Pages',
        tag: 'Patch',
        date: '2026-10-23',
        items: [
            'Missing pages, missing access and server errors now show a clear page in light or dark mode, with the right way back: your workspace when you’re signed in, home or sign-in when you’re not.',
            'An expired session takes you back with a short note instead of a “419 Page Expired” screen.',
        ]
    },
    {
        version: 'v1.18.0',
        title: 'Move Projects Between Workspaces',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Project settings → Danger zone → Move to another workspace: boards and issues, docs, whiteboards, plans, task lists, helpdesks and tickets, labels and issue types all move with the project.',
            'Project members who are already in the other workspace keep their access; you see who loses access before you confirm, and can invite them to the workspace and the project afterwards.',
            'You need to be an admin of both workspaces. If the other workspace already uses the project key, change the key first.',
        ]
    },
    {
        version: 'v1.17.0',
        title: 'Better Helpdesk + Snippets in the Extension',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'New ticket: create tickets for customers in a full form, with their email and name, your support form’s custom fields (required ones included), status, priority and assignee. Optionally email them the ticket link.',
            'Resolve with a message: moving a ticket to a done column asks for a closing message. It’s saved as a reply and the customer gets a “your ticket is resolved” email with the link.',
            'Support page: give your helpdesk a short public address like /support/acme and send it to customers. It opens your support form as a full page (Helpdesk settings → Embed).',
            'Notifications for helpdesk tickets now say what happened (“Cleo opened ticket SUP-12”, “replied to”, “assigned you to”) instead of just “moved”.',
            'Browser extension 1.1: select code or text on any page and save it as a snippet (right-click → Add selection to snippets, or the new Snippet tab). The language is detected from the page.',
        ]
    },
    {
        version: 'v1.16.1',
        title: 'Security Fixes',
        tag: 'Patch',
        date: '2026-10-23',
        items: [
            'Helpdesk: a ticket link now opens only that ticket. Submitting a new ticket with someone else’s email no longer gives access to their earlier tickets. Links sent before this update stop working.',
            'Helpdesk: ticket descriptions, replies and fields are cleaned of scripts before they are stored and shown.',
            'Uploaded files get safe, server-made file names and can never run as code on the server. Self-hosted: see “Security notes” in docs/self-hosting.md and run php artisan uploads:audit.',
            'Search, the AI connector and issue links only show issues and docs from projects you are a member of, and never other people’s private bookmark groups.',
            'Default issue types (Task, Bug…) are switched off per project instead of for every workspace, and can no longer be renamed from a project.',
            'Link previews and AI endpoints can no longer reach internal network addresses (self-hosted: AI_ALLOW_PRIVATE_ENDPOINTS for a local model).',
            'CLI: suspended accounts can’t sign in, login is rate-limited, and task changes are checked against their list.',
            'Fixed: removing a link between two issues failed.',
            'Fixed: creating a ticket in the helpdesk without a customer email, and opening a ticket from an email or notification, failed with an error. Ticket links now open the helpdesk with that ticket.',
            'Snippets: only workspace editors and admins can add, change or delete snippets and collections.',
        ]
    },
    {
        version: 'v1.16.0',
        title: 'Browser Extension',
        tag: 'Feature',
        date: '2026-10-22',
        items: [
            'New browser extension for Chrome, Edge and Firefox: save the page you are on to Brainitive in two clicks.',
            'Create an issue from any page — with the link, the text you selected and a screenshot of the whole view or an area you drag.',
            'Save links to a bookmark group, clip an article or a selection as a doc (draft or published), or add a task with a due date.',
            'Right-click a page, link or selection for quick actions; Alt+Shift+B opens the extension, Alt+Shift+S screenshots an area.',
            'Type “bt” and a space in the address bar to search Brainitive, and keep My Work open in the side panel.',
            'Works with Brainitive Cloud and self-hosted servers. You sign in on your own server; the extension can only save things and search titles — it can’t read your documents.',
        ]
    },
    {
        version: 'v1.15.0',
        title: 'Instance Admin Console',
        tag: 'Feature',
        date: '2026-10-22',
        items: [
            'Privacy: instance admins no longer see other people’s workspaces, pages, boards or search results — only the ones they are invited to.',
            'New admin console (avatar menu → Instance admin) with an overview of users, workspaces, storage, mail and background jobs.',
            'Users: workspace and project counts per person, password reset by email or by a link you share yourself, verify email, suspend, admin role, add and delete accounts.',
            'Workspaces: see owner, members and projects, transfer ownership or delete — without opening the content.',
            'Settings: invite-only sign-up, email verification on or off, and your own SMTP server with a test email.',
            'Every admin action is recorded in an audit log.',
        ]
    },
    {
        version: 'v1.14.0',
        title: 'Brainitive Is Open Source',
        tag: 'Major Release',
        date: '2026-10-22',
        items: [
            'Brainitive is now open source under the GNU AGPL-3.0 — every feature is free to self-host.',
            'Self-hosting with Docker: one image for web, queue and scheduler, MariaDB and Redis, automatic HTTPS. See docs/self-hosting.md.',
            'Brainitive Cloud is free during early access. Planned prices for later are on the homepage: Free (up to 5 members), Team (€6 per user / month, €5 yearly), Business (€12, €10 yearly) and Enterprise — announced at least 30 days before anyone pays.',
            'Contributing: contributor licence agreement, code of conduct, security policy and issue templates.',
        ]
    },
    {
        version: 'v1.13.1',
        title: 'Homepage & Welcome Refresh',
        tag: 'Patch',
        date: '2026-10-22',
        items: [
            'Homepage: features now cover Scrum boards, Gantt timelines, the draft / publish workflow with version history, and whiteboard templates with issue cards.',
            'Homepage: new “See it in action” tour with screenshots of timelines, boards, docs and whiteboards (light and dark).',
            'Help → Welcome: new overview of all modules with where to find them, “New” badges and a short getting-started note.',
        ]
    },
    {
        version: 'v1.13.0',
        title: 'Plans ↔ Board Issues',
        tag: 'Feature',
        date: '2026-10-21',
        items: [
            'Turn plan rows into board issues in one go: pick a board, and each row becomes an issue with its dates as start / due date and a matching "Who" as assignee.',
            'Link a row to an existing issue, or add issues from the board as new rows (Issues menu in the plan toolbar or right-click on a row).',
            'Linked rows show the issue key and the board status, and follow the issue: title, assignee, dates and progress update when the plan opens or with "Refresh from board".',
            'Moving or resizing a linked bar, or renaming the row, updates the issue — the issue history shows the change.',
            'Open or unlink the issue from the row menu; issues deleted on the board are unlinked automatically.',
        ]
    },
    {
        version: 'v1.12.0',
        title: 'Plans: Tom\'s Planner–Style Gantt Charts',
        tag: 'Major Feature',
        date: '2026-10-21',
        items: [
            'New project module "Plans" next to Boards, Lists and Pages — create as many plans per project as you like, from the sidebar or the project page.',
            'Spreadsheet on the left (Task, Who, Status, Start, End, Days, Progress, Notes), Gantt timeline on the right; both scroll together and columns can be shown or hidden.',
            'Draw bars by dragging on the timeline (a click adds one day/week/month), move and resize them, several bars per row; milestones as diamonds; 10 colours, labels and progress.',
            'Groups and sub-rows with collapse, Tab / Shift+Tab to indent, Alt+↑/↓ to move, drag rows by the number column; group rows show a summary bar and rolled-up dates and progress.',
            'Dependencies: drag from the dot at the end of a bar onto another bar; links are drawn as arrows and listed in the bar editor.',
            'Zoom Day / Week / Month / Quarter, today line, weekend shading, week numbers and "Today" button.',
            'Fast editing: type straight into cells, Enter for the next row, undo/redo, copy/paste and duplicate rows — paste straight from Excel or Google Sheets.',
            'Templates: Project plan, Product launch, Software release, Marketing campaign, Event planning and Workshop series, laid out from the current week.',
            'Autosave with conflict guard, version history with preview and restore, CSV import / export (Excel) and Print / PDF.',
            'Share a read-only link with people outside Brainitive; turn it off or replace it at any time.',
        ]
    },
    {
        version: 'v1.11.0',
        title: 'Whiteboards: Autosave, History, Templates & Issue Cards',
        tag: 'Feature',
        date: '2026-10-21',
        items: [
            'Whiteboards autosave a few seconds after every change; leaving with unsaved changes asks first.',
            'Conflict guard: if someone else saved the whiteboard meanwhile, a banner lets you load the latest version or overwrite it — no more silent overwrites.',
            'Whiteboard history: checkpoints on Save / Save & exit and at least every 30 minutes while editing; preview any version read-only and restore it.',
            'Templates: Retrospective, Roadmap (Now / Next / Later), Brainstorm, User story map, Flowchart and SWOT. The picker opens on every new, empty whiteboard.',
            'Issue cards: search the project issues and drop them on the board as cards coloured by status, linked to the issue; cards refresh when the whiteboard opens.',
            'Stickies → issues: select stickies, pick a board and create one issue per sticky (first column, backlog); the stickies turn into live issue cards.',
        ]
    },
    {
        version: 'v1.10.0',
        title: 'Scrum: Sprints, Backlog & Reports',
        tag: 'Major Feature',
        date: '2026-10-21',
        items: [
            'Boards can use sprints (board settings → "Use sprints"); Kanban boards stay as they are.',
            'Backlog page: active and planned sprints plus the backlog, ranked by drag & drop; create, edit, start and delete sprints.',
            'Board shows the active sprint with goal, days left and "Complete sprint" — open issues move to the next sprint or the backlog.',
            'New issues on a Scrum board join the active sprint, like Jira; subtasks always follow their parent.',
            'Reports: burndown (ideal vs. remaining, by story points or issue count) and velocity (committed vs. completed, last 7 sprints).',
            'Issue history records sprint changes.',
        ]
    },
    {
        version: 'v1.9.0',
        title: 'Jira-Style Board & Issue View',
        tag: 'Enhancement',
        date: '2026-10-19',
        items: [
            'Board header: breadcrumb, title, settings and a working "Create" button; view tabs Board · List · Timeline · Calendar.',
            'Toolbar: board search, assignee avatars as one-click filters, quick filters and the filter popover.',
            'Columns: clean lanes with "IN PROGRESS 3 / MAX 2" counts, done marker and a working "+" to add issues.',
            'Cards: summary first, parent and label lozenges, due-date lozenge (amber = soon, red = overdue), key, subtasks, comments, story points, priority and assignee.',
            'Issue view: breadcrumb header, status button coloured by category, quick actions (attach, subtask, link), collapsible details, "Assign to me" and tabs All · Comments · History · Work log.',
        ]
    },
    {
        version: 'v1.8.0',
        title: 'Boards: Issue History, WIP Limits & Time Tracking',
        tag: 'Feature',
        date: '2026-10-18',
        items: [
            'Issue history: every change is logged — created, field changes, moves, assignee, labels, comments, links, attachments and logged work.',
            'WIP limits are shown on the column header and highlighted when a column is over its limit.',
            'Time tracking: original estimate, time spent and "Log work" (e.g. 1h 30m) with a progress bar.',
            'Filters live in the URL, so a filtered board can be shared or bookmarked; quick filters: Only my issues, Recently updated, Due soon / overdue, Blocked.',
            'Faster boards: comments and attachments load when an issue is opened instead of with the whole board; comment counts now show on cards.',
        ]
    },
    {
        version: 'v1.7.0',
        title: 'Pages: Draft, Publish & Version History',
        tag: 'Major Feature',
        date: '2026-10-18',
        items: [
            'Confluence-style workflow: a published page keeps an autosaved draft — readers see the published version until you click "Update".',
            'Every publish is a version with an optional change note; view the history, preview any version and restore it.',
            'Discard draft, unpublish and an optional notification to watchers when you publish changes.',
            'Conflict guard: if someone else changed the page while you were editing, you get a banner to reload or overwrite.',
            'See who else is viewing or editing a page.',
            'Permissions: drafts are only visible to their author and admins; editors can edit, admins and authors can delete.',
        ]
    },
    {
        version: 'v1.6.0',
        title: 'Outline-Style Document Editor',
        tag: 'Enhancement',
        date: '2026-10-18',
        items: [
            'Writing-first page editor in the style of Outline and Evernote: one clean surface and a centred text column.',
            'Floating format toolbar on text selection: bold, italic, strikethrough, highlight, code, headings, quote, checklist and link.',
            'The classic toolbar is hidden by default and can be turned back on (remembered per browser).',
            'Empty lines show "Type / for commands"; the title is edited in place and Enter jumps into the text.',
            'New document typography: calmer headings, quotes, links and spacing; quieter page header and table of contents.',
        ]
    },
    {
        version: 'v1.5.0',
        title: 'Redesign: Board Cards, Shared Detail, Workspace & Project Home',
        tag: 'Major Release',
        date: '2026-05-27',
        items: [
            'Board cards: soft-modern redesign with left accent bar (priority → type color), type badge, pill labels via shared LabelChip, unified PriorityIndicator, and AssigneeAvatar.',
            'Board cards: clickable assignee circle — assign users directly from the card without opening the popup; unassigned cards show a "+" affordance.',
            'Board cards: proper in-app AlertDialog for delete confirmation (replaces browser alert).',
            'Board cards: subtasks of type "subtask" now nest inside the parent card when in the same column, with clickable rows to open each subtask.',
            'Item popup: added Subtasks section (list + inline "Add subtask" creator), Linked Issues (view + add/remove with dialog), and Watchers in the sidebar (functional toggle per member).',
            'Shared ItemDetail component: extracted useItemDetail hook + ItemDetailMain + ItemDetailSidebar + ItemFilePreviewDialog into a single shared module — the popup and full-page item view now render identical markup from one source, eliminating drift.',
            'Labels, priority, type badge, and avatars are now consistent everywhere via shared primitives (LabelChip, PriorityIndicator, TypeBadge, AssigneeAvatar).',
            'Board toolbar: reorganized to a single row — views on the left, search + filter + sort on the right. Added Priority and Type filters to the filter popover.',
            'Workspace Home: full redesign with gradient identity banner (logo + cover image), 4 real stat cards (open tasks, done 7d, avg cycle, health), pinned boards row, projects grid with real completion bars, and a side rail (recent activity, upcoming due dates, team). Added Lists and Documents sections aggregated across projects.',
            'Workspace Settings: converted to a left-rail vertical settings nav with icon tabs, matching the new design language.',
            'Project Home: full redesign matching Workspace Home style — gradient banner with project color, card-grid sections for Boards, Lists, Helpdesks, Documents, and Whiteboards.',
            'Project Settings: converted to left-rail vertical settings nav with Danger Zone moved into its own tab.',
            'App shell: tighter sidebar density (smaller icons, compact nav items), Inbox nav item linked to notifications, search bar in topbar, slimmer topbar (h-12).',
            'Landing page: fixed white-screen-on-mobile-PWA bug caused by IntersectionObserver not firing in standalone mode — added standalone detection and timeout fallback in useScrollReveal.',
            'Workspace Settings: added "Remove cover" button for clearing the header image.',
        ]
    },
    {
        version: 'v1.4.4',
        title: 'My Work Scope and Source Awareness',
        tag: 'Enhancement',
        date: '2026-05-26',
        items: [
            'My Work: added an Assigned/Created scope switch so the page can show work you own or work you created.',
            'My Work: now includes both board items and list items, grouped by project with the exact source name shown in each row.',
            'My Work rows: updated the task list styling to keep the dense reference layout while surfacing source type, status, priority, due date, and assignee context.',
        ]
    },
    {
        version: 'v1.4.3',
        title: 'Board & Document Design Refresh',
        tag: 'Enhancement',
        date: '2026-05-26',
        items: [
            'Board page: refreshed the board UI with a denser, cleaner Atlassian-style layout, improved card presentation, tighter columns, and updated visual polish.',
            'Board styling: added JetBrains Mono for issue keys and expanded Tailwind support needed by the redesigned board surfaces.',
            'Pages: refreshed the document editor page with a Confluence-style document surface, quieter header actions, softer status badges, and improved metadata spacing.',
            'Pages editor: added document-mode typography for richer reading/editing, including tighter headings, prose spacing, links, quotes, inline code, and a cleaner table of contents.',
            'Full-width documents: kept the white document background across the expanded page while preserving the existing edit toolbar and behavior.',
        ]
    },
    {
        version: 'v1.4.2',
        title: 'Mobile Responsive Improvements',
        tag: 'Enhancement',
        date: '2026-03-03',
        items: [
            'Board page: responsive header with icon-only view tabs on mobile, narrower columns (240px vs 280px), and full-width search.',
            'Item modal: details sidebar now slides in as a drawer on mobile (tap sliders icon), fixed sidebar on desktop.',
            'Item page: details sidebar converted to slide-in drawer on mobile for better mobile experience.',
            'Helpdesk (support) board: same responsive treatment with slide-in drawer for ticket details on mobile.',
            'Help modal: optimized for mobile with smaller padding, icon-only tabs, and stacked layout on small screens.',
        ]
    },
    {
        version: 'v1.4.1',
        title: 'Project & Item Type Improvements',
        tag: 'Enhancement',
        date: '2026-03-01',
        items: [
            'Project settings: same emoji + custom image icon picker as bookmarks; icon and name aligned on one line with consistent sizing.',
            'Project description: rich text editor (Lexical) with full toolbar (headings, lists, links, tables, images, slash commands) and optional @mentions; stored as HTML and shown in a dedicated Description section on the project page with prose styling.',
            'Project page: header shows project name only; new stats row (owner with avatar, project key, created date, boards count, members count); Description card below with formatted rich HTML.',
            'Item types: default (global) types can be edited and disabled; disabled types are hidden from boards but remain in settings with an Enable button; new is_active flag and forProject scope.',
            'Item type icon: icon picker (emoji + custom image upload) in project settings when creating/editing item types; upload endpoint returns storage path; ItemType model has icon_display for frontend.',
            'Shared ItemTypeIcon component: shows custom image (URL), emoji, or Lucide icon by name/slug on board cards, item modal, item page, project settings list, and global create modal; default types (task, bug, story, epic, subtask) render proper icons instead of text.',
        ]
    },
    {
        version: 'v1.4.0',
        title: 'Helpdesk & Support Portal',
        tag: 'Major Release',
        date: '2026-03-01',
        items: [
            'Full helpdesk module: boards with columns, tickets, custom fields (EAV), and ticket contacts for external support requests.',
            'Public support widget (floating button + form) and iframe embed so customers can submit tickets from your site.',
            'Public ticket portal page with branded header, footer (terms, privacy, cookies), and rich text description and replies.',
            'Attachments on ticket creation (embed and widget) and configurable default fields: Email & Subject fixed; Name, Message, and Attachments editable or hideable in settings.',
            'Email notifications: ticket created (with portal link) and ticket reply notifications to contacts and agents; reply notifications are queued.',
            'Helpdesk settings: General, Columns, Fields (default + custom with drag-and-drop), and Embed tab with script and iframe snippets.',
            'Rich text for custom fields in the embed (Quill) and for replies on the portal (Lexical); modernized embed UI with card layout, focus rings, and prose styling for description and replies.',
        ]
    },
    {
        version: 'v1.3.6',
        title: 'Item and Page Duplication',
        tag: 'Feature',
        date: '2026-02-28',
        items: [
            'Added the ability to cleanly duplicate Pages along with their content via the page options dropdown.',
            'Added the ability to duplicate Board Items. Duplicating an item with subtasks will now prompt you to either duplicate just the item, or the item and all of its subtasks.',
            'Duplicating a page with subpages will similarly prompt you to duplicate the entire page tree or just the single page.',
        ]
    },
    {
        version: 'v1.3.5',
        title: 'Editor Content Deletion Fix',
        tag: 'Patch',
        date: '2026-02-28',
        items: [
            'Fixed a race condition where clicking Edit → Save on an item description or Edit → Update on a page document without making any changes would wipe all content.',
            'Replaced the fragile "skip first N updates" counter in the editor\'s onChange plugin with a shared initializing ref that is held until the Lexical tree is fully populated — guaranteeing onChange never fires mid-initialization.',
            'Fixed Pages: Update button now correctly uses the saved content as a fallback when clicked before any edits are made.',
            'Added an isEditing guard so the editor\'s auto-save cannot trigger during the view→edit remount.',
        ]
    },
    {
        version: 'v1.3.4',
        title: 'Timeline Ordering & Navigation',
        tag: 'Enhancement',
        date: '2026-02-28',
        items: [
            'Timeline items can now be drag-and-drop reordered in the sidebar — order is persisted to the database and shared across all users.',
            'Parent items with subtasks now show a ▼/▶ collapse toggle to hide or reveal their children in both the sidebar and timeline rows.',
            'Rewrote the timeline layout: single scrollbar, sticky date header (dates stay pinned when scrolling down), and sticky item sidebar (stays pinned when scrolling right).',
            'Added out-of-view indicators (◀ KEY / KEY ▶) that appear at the visible timeline edges when an item\'s date bar is outside the current viewport. Click the indicator to smoothly scroll so the item\'s start date is centered.',
        ]
    },
    {
        version: 'v1.3.3',
        title: 'Item Start Dates & Timeline Improvements',
        tag: 'Enhancement',
        date: '2026-02-27',
        items: [
            'Added editable start dates to items in both the board item sidebar and the full item page to better support planning.',
            'Updated the board Timeline view to show the full calendar year, auto-center on today, and render a consistent grid with clearer day boundaries and month tooltips.',
        ]
    },
    {
        version: 'v1.3.2',
        title: 'Board Filters & Assignee Autocomplete',
        tag: 'Enhancement',
        date: '2026-02-27',
        items: [
            'Added a board-level Filter popover with search, status (column) and label filters.',
            'Introduced an assignee autocomplete inside the Filter popover, so you can quickly filter by “Me”, “Unassigned”, or any project member.',
        ]
    },
    {
        version: 'v1.3.1',
        title: 'Item Watchers & Assignment Notifications',
        tag: 'Enhancement',
        date: '2026-02-27',
        items: [
            'Implemented item watchers! Users can now watch specific items to get notified of updates via the property panel.',
            'Items now automatically register the reporter and assignee to the watch list.',
            'Added assignment notifications – you will now receive a notification when someone assigns you to an item.',
        ]
    },
    {
        version: 'v1.3.0',
        title: 'Notifications System',
        tag: 'Feature',
        date: '2026-02-27',
        items: [
            'Introduced in-app notifications, including a notifications dropdown in the top bar and a full Notifications page.',
            'Store notifications in the database so users can see unread and historical events across sessions.',
            'Added basic notification actions such as marking notifications as read.',
        ]
    },
    {
        version: 'v1.2.8',
        title: 'Icon Picker for Bookmarks & Snippets',
        tag: 'Enhancement',
        date: '2026-02-27',
        items: [
            'Updated bookmark collections to use the full emoji-mart + custom image icon picker, matching page title icons.',
            'Updated snippet collections to use the same rich icon picker, with emoji search and custom image upload.',
        ]
    },
    {
        version: 'v1.2.7',
        title: 'Project Members & Global Create',
        tag: 'Feature',
        date: '2026-02-27',
        items: [
            'Restricted project visibility so only assigned members can see projects inside a workspace (workspace admins still see all).',
            'Added project member management with roles (admin, editor, viewer) in Project Settings, powered by the project_user pivot.',
            'Enhanced Global Create with project/board pre-selection, assignee dropdown, label search + create, and Command Palette entries for creating items, pages, whiteboards, and folders.',
        ]
    },
    {
        version: 'v1.2.6',
        title: 'Help & Changelogs',
        tag: 'Latest',
        date: '2026-02-27',
        items: [
            'Introduced global Help Modal with UI standard matching Global Create.',
            'Added visual Changelog history driven by git log.',
        ]
    },
    {
        version: 'v1.2.5',
        title: 'Global Create Redesign',
        tag: 'Enhancement',
        date: '2026-02-27',
        items: [
            'Redesigned global Create modal — type tabs, board columns, priority/type chips, and rich text description.'
        ]
    },
    {
        version: 'v1.2.4',
        title: 'Sidebar Upgrades',
        tag: 'Update',
        date: '2026-02-27',
        items: [
            'Added collapsible sidebar groups and drag-to-reorder within groups (DB-persisted).',
            'Removed whiteboard label and fixed excalidraw preview mode.'
        ]
    },
    {
        version: 'v1.2.3',
        title: 'Editor & Validation Fixes',
        tag: 'Patch',
        date: '2026-02-27',
        items: [
            'Fixed widen icon column to text and max validation constraints.',
            'Added emoji picker to editor toolbar and slash commands.'
        ]
    },
    {
        version: 'v1.2.2',
        title: 'Smart Slash Menu & TOC Position',
        tag: 'Patch',
        date: '2026-02-27',
        items: [
            'Implemented smart slash menu positioning.',
            'Fixed TOC tracking with scroll root.',
            'Added full emoji picker with image upload.'
        ]
    },
    {
        version: 'v1.2.0',
        title: 'Lexical Editor Engine Swap',
        tag: 'Major Update',
        date: '2026-02-27',
        icon: Sparkles,
        items: [
            'Replaced EditorJS with high-performance RichLexicalEditor on pages.',
            'Added Table of Contents (TOC) with independent scroll panels.'
        ]
    },
    {
        version: 'v1.1.2',
        title: 'AI Enhancements & Board UI',
        tag: 'Patch',
        date: '2026-02-26',
        items: [
            'AI: Added max_completion_tokens, disabled temperature, surface API error.',
            'Board: Added profile images on assignment cards.'
        ]
    },
    {
        version: 'v1.1.1',
        title: 'Editor Poll Insertion Fixes',
        tag: 'Patch',
        date: '2026-02-26',
        items: [
            'Fixed poll insertion for the slash-menu (anchor ref + microtask).',
            'Added fixes for Edit/buttons skipping clicks on button/inputs.'
        ]
    },
    {
        version: 'v1.1.0',
        title: 'AI Integration',
        tag: 'Major Feature',
        date: '2026-02-26',
        icon: BrainCircuit,
        items: [
            'Implemented Generate prompt AI action capability natively (backend + frontend).',
            'Outputs formatted prompts seamlessly without specs metadata blocks.'
        ]
    },
    {
        version: 'v1.0.24',
        title: 'GitHub OAuth Login',
        tag: 'Feature',
        date: '2026-02-26',
        items: [
            'Integrated GitHub OAuth login with Socialite (github_id, Login + Welcome buttons).'
        ]
    },
    {
        version: 'v1.0.23',
        title: 'Google OAuth & Auth Enhancements',
        tag: 'Enhancement',
        date: '2026-02-26',
        items: [
            'Homepage: Google login button with logo & fixed OAuth cancel callback errors.',
            'Fixed Workspace invite email link resolution to correct show page (405 error fix).',
            'Allowed email_verified_at mass assignment for Google login auto-verify.',
            'Added Google OAuth login capability and fixed Inertia router.reload types.'
        ]
    },
    {
        version: 'v1.0.22',
        title: 'Workspace Invitations',
        tag: 'Feature',
        date: '2026-02-26',
        items: [
            'Workspace invite system: invite by email, confirm modal, 30-day pending.',
            'Added auto-join logic directly upon registering.'
        ]
    },
    {
        version: 'v1.0.21',
        title: 'Lexical StickyNote Export/Import',
        tag: 'Patch',
        date: '2026-02-25',
        items: [
            'Fixed StickyNoteNode exportDOM/importDOM so notes survive HTML saves and reloads.'
        ]
    },
    {
        version: 'v1.0.20',
        title: 'Lexical StickyNode Swap',
        tag: 'Update',
        date: '2026-02-25',
        items: [
            'Replaced custom StickyNoteNode with Lexical playground StickyNode (draggable portal/nested editor).'
        ]
    },
    {
        version: 'v1.0.19',
        title: 'Typescript & Action Upgrade',
        tag: 'Patch',
        date: '2026-02-25',
        items: [
            'Fixed StickyNoteNode TS errors.',
            'Upgraded ssh-action to v1.2.0 to resolve ghcr.io timeouts.'
        ]
    },
    {
        version: 'v1.0.18',
        title: 'Table Re-sizing & Horizontal Scroll',
        tag: 'Enhancement',
        date: '2026-02-25',
        items: [
            'Editor: Added table column resizing, horizontal scroll contexts.',
            'Fixed action menu positional tracking.'
        ]
    },
    {
        version: 'v1.0.17',
        title: 'Slash Command Fixes',
        tag: 'Patch',
        date: '2026-02-25',
        items: [
            'Fixed slash command Enter interactions.',
            'Fixed description view mode, linked issues rendering, nested replies and sidebar hovers.'
        ]
    },
    {
        version: 'v1.0.16',
        title: 'Performance & Architecture Updates',
        tag: 'Performance',
        date: '2026-02-24',
        items: [
            'Performance: Bundled local fonts and finalized LCP/CLS layout shifts fixes.',
            'Made standard app installable directly as a PWA (mobile and desktop).',
            'Added fully automated deployments using GitHub Actions.'
        ]
    },
    {
        version: 'v1.0.15',
        title: 'My Work Experience',
        tag: 'Feature',
        date: '2026-02-24',
        items: [
            'Introduced the newly added My Work page highlighting individually assigned tasks.',
            'Supported specific task level custom filters.'
        ]
    },
    {
        version: 'v1.0.14',
        title: 'App Brand Overhaul',
        tag: 'Enhancement',
        date: '2026-02-24',
        items: [
            'Applied customized brand colors across entire app infrastructure.',
            'Reverted sidebar visually back to standard light theme styling.'
        ]
    },
    {
        version: 'v1.0.10',
        title: 'Marketing & Legal Overhaul',
        tag: 'Update',
        date: '2026-02-24',
        items: [
            'Redesigned primary homepage, features overview, specific legal pages and cookie consent modals.',
            'Project page optimizations.'
        ]
    },
    {
        version: 'v1.0.5',
        title: 'Sidebar & Workspaces UI',
        tag: 'Enhancement',
        date: '2026-02-24',
        items: [
            'Workspaces: index page custom cards upgraded with covers, logos, owner context.',
            'Added parent selector routing for pages/folders to the sidebar tree.',
            'Email verifications, favicons & manifest configurations unified.',
            'Added global Project icons.'
        ]
    },
    {
        version: 'v1.0.2',
        title: 'Bookmarks & Icons',
        tag: 'Feature',
        date: '2026-02-23',
        items: [
            'Introduced Bookmark settings interface and global IconPickers.',
            'Added custom image upload for icon mapping options.',
            'Added workspace bookmarks logic directly handling paste operations and to-dos.'
        ]
    },
    {
        version: 'v1.0.0',
        title: 'System Genesis',
        tag: 'Initial Release',
        date: '2026-02-23',
        items: [
            'Embedded active Excalidraw whiteboards within the pages functionality.',
            'Public landing heroes and project initialization models finalized.'
        ]
    }
];

export function HelpModal({ open, onOpenChange }: HelpModalProps) {
    const [activeTab, setActiveTab] = useState<TabType>('welcome');

    return (
        <Dialog open={open} onOpenChange={(o) => {
            onOpenChange(o);
            if (!o) setTimeout(() => setActiveTab('welcome'), 300);
        }}>
            <DialogContent className="max-w-7xl w-[90vw] p-0 gap-0 overflow-hidden [&>button]:hidden" aria-describedby={undefined}>
                <DialogTitle className="sr-only">Brainitive Help & Changelog</DialogTitle>

                {/* Header with Custom Tabs */}
                <div className="flex items-center justify-between px-3 sm:px-4 pt-3 sm:pt-4 pb-0 gap-2">
                    <div className="flex items-center gap-1 bg-muted/50 rounded-lg p-1">
                        <button
                            type="button"
                            onClick={() => setActiveTab('welcome')}
                            className={cn(
                                'flex items-center gap-1.5 px-2 sm:px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150',
                                activeTab === 'welcome'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            )}
                        >
                            <BrainCircuit className={cn("size-4", activeTab === 'welcome' ? 'text-primary' : '')} />
                            <span className="hidden sm:inline">Welcome</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('changelog')}
                            className={cn(
                                'flex items-center gap-1.5 px-2 sm:px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150',
                                activeTab === 'changelog'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            )}
                        >
                            <History className={cn("size-4", activeTab === 'changelog' ? 'text-primary' : '')} />
                            <span className="hidden sm:inline">Changelog</span>
                        </button>
                    </div>
                    <Button variant="ghost" size="icon" className="size-8 shrink-0" onClick={() => onOpenChange(false)}>
                        <X className="size-4" /><span className="sr-only">Close</span>
                    </Button>
                </div>

                {/* Main Content Area */}
                <div className="flex flex-col">
                    <div className="px-3 sm:px-5 py-4 sm:py-6 h-[70vh] sm:h-[80vh] overflow-y-auto">

                        {/* Welcome Tab */}
                        {activeTab === 'welcome' && (
                            <div className="space-y-6 animate-in fade-in slide-in-from-bottom-2 duration-300">
                                <div>
                                    <h2 className="text-xl font-semibold flex items-center gap-2">
                                        <BrainCircuit className="size-6 text-primary" />
                                        Welcome to Brainitive
                                    </h2>
                                    <p className="text-sm text-muted-foreground mt-2 leading-relaxed">
                                        Your all-in-one productivity and collaboration workspace.
                                        Brainitive is designed to help you organize ideas, manage projects, and collaborate efficiently in a unified environment. Here is a quick overview of what you can do:
                                    </p>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                    {WELCOME_FEATURES.map((feature) => (
                                        <div key={feature.title} className="flex items-start gap-4 p-4 rounded-xl border bg-card/50 shadow-sm hover:shadow-md transition-shadow">
                                            <div className={cn('p-2.5 rounded-lg shrink-0', feature.tone)}>
                                                <feature.icon className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <h4 className="font-semibold text-sm flex items-center gap-2">
                                                    {feature.title}
                                                    {feature.isNew && <span className="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">New</span>}
                                                </h4>
                                                <p className="text-xs text-muted-foreground mt-1 leading-snug">{feature.description}</p>
                                                <p className="text-[11px] text-muted-foreground/80 mt-1.5">{feature.where}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="rounded-xl border bg-muted/40 p-4 text-xs text-muted-foreground leading-relaxed">
                                    <span className="font-semibold text-foreground">Getting started:</span>{' '}
                                    pick a project in the sidebar and use <span className="font-medium text-foreground">+</span> to add a board, page, whiteboard, list or plan.
                                    Everything you create inside a project is shared with its members by role (admin, editor, viewer).
                                    See the <span className="font-medium text-foreground">Changelog</span> tab for what changed recently.
                                </div>
                            </div>
                        )}

                        {/* Changelog Tab */}
                        {activeTab === 'changelog' && (
                            <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
                                <div className="space-y-8 relative before:absolute before:inset-0 before:ml-5 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-border before:to-transparent">

                                    {CHANGELOG.map((log, index) => {
                                        const isLatest = index === 0;
                                        const IconComponent = log.icon;

                                        return (
                                            <div key={log.version} className={cn("relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group", isLatest && "is-active")}>
                                                <div className={cn(
                                                    "flex items-center justify-center w-10 h-10 rounded-full border shrink-0 md:order-1 md:group-odd:-translate-x-1/2 md:group-even:translate-x-1/2 shadow-sm z-10",
                                                    isLatest ? "border-primary/30 bg-primary/10 text-primary" : "bg-background text-muted-foreground"
                                                )}>
                                                    {IconComponent ? <IconComponent className="size-4" /> : <div className="size-2 rounded-full bg-muted-foreground/30" />}
                                                </div>
                                                <div className="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] p-3 sm:p-4 rounded-xl border bg-card/50 shadow-sm">
                                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-0 mb-1">
                                                        <h3 className={cn("font-bold text-sm sm:text-base", isLatest ? "text-primary" : "text-foreground")}>
                                                            {log.version} • {log.title}
                                                        </h3>
                                                        <span className={cn(
                                                            "text-xs px-2 py-0.5 rounded-full font-medium self-start",
                                                            isLatest ? "bg-muted text-muted-foreground" : "text-muted-foreground"
                                                        )}>
                                                            {log.tag}
                                                        </span>
                                                    </div>
                                                    <span className="text-xs font-mono text-muted-foreground bg-muted/50 px-1.5 py-0.5 rounded inline-block mb-1">
                                                        {log.date}
                                                    </span>
                                                    <ul className="text-xs sm:text-sm text-muted-foreground space-y-1 list-disc list-inside mt-2">
                                                        {log.items.map((item, i) => (
                                                            <li key={i}>{item}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </div>
                                        );
                                    })}

                                </div>
                            </div>
                        )}

                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
