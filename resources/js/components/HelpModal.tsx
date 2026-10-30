import React, { useState } from 'react';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { LayoutGrid, FileText, PencilRuler, BookMarked, BrainCircuit, X, History, Sparkles, GanttChart, ListChecks, CalendarDays, CalendarClock, Bot, Magnet, BookOpen, Terminal } from 'lucide-react';
import { CLI_INSTALL_SH } from '@/lib/cli';
import { DOCS_URL } from '@/lib/docs';
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
        description: 'To-do lists with due dates, repeats and reminders (plus a morning summary), and one view of everything assigned to you across projects.',
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
        icon: CalendarClock,
        tone: 'bg-sky-500/10 text-sky-500',
        title: 'Booking pages',
        description: 'Share a link and let people book a free time with you — your hours minus what’s in your calendars, with your own meeting types and lengths.',
        where: 'Sidebar → Booking pages',
        isNew: true,
    },
    {
        icon: BookMarked,
        tone: 'bg-amber-500/10 text-amber-500',
        title: 'Bookmarks & Snippets',
        description: 'Save links, APIs and reusable text or code snippets in one place — share collections publicly if you like.',
        where: 'Sidebar → Bookmarks / Snippets',
    },
    {
        icon: Magnet,
        tone: 'bg-rose-500/10 text-rose-500',
        title: 'Lead forms',
        description: 'Multi-step lead forms with icon cards, conditional questions and your branding — every submission becomes a card on the form’s own leads board.',
        where: 'Project → Add content → Lead form · share at /f/your-name or embed it',
    },
    {
        icon: Bot,
        tone: 'bg-slate-500/10 text-slate-500',
        title: 'AI & MCP',
        description: 'Connect Claude or ChatGPT over MCP to read and update your workspace — or let it set up a whole project from a Jira export — and use your own AI provider inside docs and issues.',
        where: 'Workspace settings → AI · MCP: add <your Brainitive URL>/mcp as a connector',
    },
    {
        icon: Terminal,
        tone: 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-300',
        title: 'Command line (CLI)',
        description: 'Capture, issues with screenshots, tasks, snippets, docs and search from your terminal; "brainitive mcp" gives AI tools on your machine your workspace.',
        where: `${CLI_INSTALL_SH} · then brainitive login`,
        isNew: true,
    },
];

const CHANGELOG = [
    {
        version: 'v1.47.1',
        title: 'Install the CLI straight from GitHub',
        tag: 'Fix',
        date: '2026-10-30',
        items: [
            `The CLI installer now ships on the GitHub release next to the programs it downloads, so installing works on every Brainitive server: ${CLI_INSTALL_SH} (Windows: irm …/install.ps1 | iex). brainitive.app/install.sh forwards there.`,
        ]
    },
    {
        version: 'v1.47.0',
        title: 'The CLI, and an AI that sets up whole projects',
        tag: 'Major Feature',
        date: '2026-10-30',
        items: [
            'Brainitive CLI: one command installs it on macOS, Linux or Windows (curl -fsSL https://brainitive.app/install.sh | sh — or irm …/install.ps1 | iex on Windows). No runtime needed, works with Brainitive Cloud and your own server; sign in through your browser with "brainitive login".',
            'From the terminal: capture a thought, a link, your clipboard or a file to your inbox; create issues (with a screenshot), move, comment and update them; add and complete tasks; save snippets and bookmarks; read and publish docs from Markdown; search everything; see a project\'s whole setup. Tables for people, --json for scripts.',
            '"brainitive mcp" runs a local MCP server bridged to your account, so Claude Code, Cursor, Codex, Copilot and other tools on your machine get your workspace without their own sign-in. "brainitive tool <name>" calls any of the 70+ server tools directly.',
            'MCP: AI connectors can now set up a project end to end — create workspaces and projects with their statuses, boards and columns, labels, item types, priorities, custom fields (with options), task lists, add members — and create up to 100 cards in one call with type, priority, labels, assignee, dates, story points, parent and custom fields. Give Claude or ChatGPT a Jira export and let it build and fill the project; names are checked and one bad value fails the batch with the valid choices, so nothing is half-imported.',
            'MCP: create_board_item / update_board_item take type, priority, labels, story points, parent and custom fields; get_board returns column slugs, colors and done flags; a personal capture_to_inbox tool.',
            'Fixed: the MCP list_projects tool failed for members who are not workspace admins.',
            'The old Python CLI (pip install, password login, task lists only) is retired; the new binary replaces it.',
        ]
    },
    {
        version: 'v1.46.0',
        title: 'Plans for Brainitive Cloud, and a new licence',
        tag: 'Major Feature',
        date: '2026-10-30',
        items: [
            'Brainitive Cloud has plans now: Free (up to 3 members, 5 projects), Starter (€3 per user / month or €20 per year), Team (€5 or €50, unlimited members and projects) and Business (€15 or €150). Every feature is in every plan — plans differ in members, projects, storage, version history and support.',
            'Workspace settings → Plan & usage shows your plan and how much of it you use: members, projects, content and file storage.',
            'At a limit, Brainitive tells you what is full and how to upgrade, instead of failing. After a downgrade nothing is deleted for 90 days; only adding more is paused until you are back under the limit.',
            'Self-hosted installs have no limits and no billing. Brainitive is now licensed under the Business Source License 1.1: free to self-host for organizations under €2 million annual revenue, and each version becomes AGPL-3.0 after four years.',
        ]
    },
    {
        version: 'v1.45.3',
        title: 'Safari and offline',
        tag: 'Fix',
        date: '2026-10-29',
        items: [
            'Offline mode: updating the offline copy of the app can no longer leave a device without it — if you leave the page while it updates (Safari takes a moment), the previous copy keeps working.',
            'Safari and Firefox: requests the browser cancels when you reload or leave a page are no longer reported as errors.',
        ]
    },
    {
        version: 'v1.45.2',
        title: 'Phones and Firefox',
        tag: 'Fix',
        date: '2026-10-28',
        items: [
            'Phones: the top bar always stays in reach — on iPhones it could slide under Safari\'s bars and the bell, search or "+" couldn\'t be tapped. Its buttons are bigger touch targets now, and search is a magnifier icon (light / dark is in the account menu).',
            'Phones: panels and dialogs keep clear of the notch, Dynamic Island and home indicator, with bigger close buttons; quick capture and the notifications prompt sit above the home indicator.',
            'Firefox: links that forward to another page — like Billing, which opens its first tab — work again (they did nothing since offline mode).',
        ]
    },
    {
        version: 'v1.45.1',
        title: 'GitHub App: creating it works',
        tag: 'Fix',
        date: '2026-10-27',
        items: [
            'Fixed: creating the GitHub App (Admin → GitHub App → Connect) no longer ends in "GitHub could not create the app (HTTP 422)" after you confirm on GitHub.',
        ]
    },
    {
        version: 'v1.45.0',
        title: 'Booking pages: let people book a time with you',
        tag: 'Major Feature',
        date: '2026-10-27',
        items: [
            'Booking pages (sidebar → Booking pages): share a link and people outside Brainitive pick a free time with you — no account, no back-and-forth emails. Free times are your working hours minus what is in your calendars.',
            'As many pages as you need ("Clients", "Emergency", "Hiring"), each with its own link and its own meeting types: you name them and choose the length — 15, 45 or any number of minutes.',
            'Per page you choose what blocks your time: each connected calendar (Google, Outlook, iCloud / CalDAV, calendar links), your issues and tasks with a time, all-day events. Bookings on any of your pages always block, so you are never booked twice.',
            'Templates for sales calls, consulting, support, job interviews, emergencies, office hours and coaching fill in meeting types, hours, rules and questions — everything stays editable.',
            'Weekly hours with breaks, days off and extra hours on single dates, buffers before and after, minimum notice, how far ahead, start times every N minutes and a daily maximum.',
            'A booking lands in the calendar you pick with the guest invited (and a Google Meet or Teams link), in your Brainitive calendar, in the page\'s Bookings tab and — if you want — as a card on a board. You get a notification; the guest gets a confirmation with a calendar file, a link to reschedule or cancel, and a reminder a day before.',
            'Visitors see times in their own time zone, on any screen size; the page can be embedded on your website. Only free times ever leave Brainitive — never what is in your calendar.',
            'If a calendar can\'t be read, the page keeps working without it and tells you that bookings may overlap its events.',
            'Fixed: instance admins can create the GitHub App again (Admin → GitHub App) — GitHub refused it with "HTTP 422". If GitHub refuses for another reason, such as a name that is already taken, the page now says why.',
        ]
    },
    {
        version: 'v1.44.0',
        title: 'Everything live, branded emails, privacy by default',
        tag: 'Feature',
        date: '2026-10-27',
        items: [
            'Live boards and plans: cards created, moved, edited or deleted by teammates (and by automations, the AI connector or imports) show up on your open board, list, timeline and backlog right away — your filters, drag and open card stay as they are. Gantt plans follow others\' changes and never overwrite your unsaved edit.',
            'Live basics: comments and ticket replies appear instantly, avatars show who else is on an issue, board or plan, "… is typing" under the comment box, and issue descriptions can be written together like documents.',
            'Live whiteboards: draw together in real time — shapes, sticky notes and images appear for everyone with named cursors, like Miro; it keeps working offline.',
            'The notification bell rings the moment something arrives, and reading in one tab updates every other tab.',
            'Notification devices (Profile → Notifications): see every browser that gets your push notifications and send a test to any of them, with next steps for Windows, macOS, iPhone and Android.',
            'Every email Brainitive sends has one branded look: logo, dark mode and phone friendly, "Manage notifications" for members; helpdesk customers see your helpdesk\'s name. Instance admins can preview every email (Admin → Emails).',
            'Privacy: no more requests to Google Fonts or other CDNs (video and design embeds load on click), no cookie banner, and our own visit statistics run only if you allow them, a new imprint, sub-processor list and data processing agreement, and rewritten privacy policy, terms and cookie pages.',
            'Your public pages: workspaces can name their legal entity and link their own privacy policy and imprint on support pages, forms and portals; lead forms get an optional or required consent checkbox that is saved with the lead.',
            '"Download my data" (Profile → Your data): a ZIP with everything Brainitive holds about your account — profile, settings, the issues, comments, pages, whiteboards and tasks you created, time entries, notifications, devices, connected calendars and apps, and your uploaded files. The link arrives in the app and by email and works for 24 hours, only for you.',
            'Fixed: deleting your account no longer removes work you created in shared workspaces — it is handed to the workspace owner.',
            'Security: on instances that don\'t require email verification, an unconfirmed account can no longer take over the guest identity (client and feedback portals) of the same email address.',
        ]
    },
    {
        version: 'v1.43.0',
        title: 'Find anything, write together, work offline',
        tag: 'Feature',
        date: '2026-10-27',
        items: [
            'Find anything: a real search page (/search) and ⌘K results across issues, docs, comments, tickets, tasks, plans, snippets, bookmarks, your captures and file names — ranked, typo tolerant, with "phrases", -exclusions, issue keys, highlights and filters by type, project, status, assignee and date. Runs inside the database; the index builds itself after the update.',
            'Write together: several people edit the same document at once — everyone\'s cursor with their name, changes appear instantly. Documents keep working offline and sync when you\'re back; if live editing isn\'t reachable you simply edit alone as before. Draft, publish and review stay as they are.',
            'Offline mode (Profile → Offline, per device): My Day, task lists, issues and quick capture keep working without internet and sync when you\'re back online. If someone else changed the same thing meanwhile, you choose: keep mine, keep theirs or keep both.',
            'Issues: a "Meeting" button next to Attach, Create subtask and Link issue schedules a Google Meet or Microsoft Teams meeting for the issue right away.',
        ]
    },
    {
        version: 'v1.42.0',
        title: 'Work with clients, every calendar, meetings and smart links',
        tag: 'Feature',
        date: '2026-10-27',
        items: [
            'Public feedback portal (project → Feedback): customers share ideas, vote and follow them at /feedback/your-name, signing in with just their email. You approve ideas, set Planned / Building / Released with a note, merge duplicates and turn ideas into cards; followers get an email when the status changes.',
            'Client portal (project → Client portal): invite clients by email and share selected doc versions and plan snapshots at /client/your-name. They approve or request changes on the exact version they saw — a new version needs a fresh approval — and comment; your replies reach them only with "Reply to client".',
            'Document review: ask teammates to review a draft. They comment on selected text and approve or request changes; editing afterwards marks the review outdated. Admins can require an approved review before a page is published.',
            'Smart links: paste a link to an issue, doc, ticket or plan into a document or issue (or use /link or the "+" next to a block) and it shows live as a chip or card with title, status, due date and assignee. The linked item lists the document under "Referenced by".',
            'Meetings: schedule a Google Meet or Microsoft Teams meeting from a card, a document or the calendar — invitations go out from your own calendar, Join sits on the card or doc, and meeting notes are one click away.',
            'Calendars: connect Outlook / Microsoft 365, Apple iCloud and any CalDAV calendar (Fastmail, Nextcloud…), or add any calendar\'s ICS link read-only — next to Google. Read-only calendars can\'t be moved, and a calendar that fails to load shows a warning instead of disappearing.',
            'Calendar feed (Calendar → Subscribe): your due dates, list tasks, My Day and focus sessions in Apple Calendar, Google Calendar or Outlook through a private link you can reset anytime.',
            'Automations: conditions are now Field → Operator → Value (is / is not / any of / none of, has none of, contains, starts with, overdue, within the next N days, number comparisons, custom fields), with "Match all / any". The run history shows which conditions passed; existing rules were converted automatically.',
            'GitHub in one click: project settings → Integrations → Connect GitHub, pick repositories, done — no webhooks to paste. Optionally, merging an issue\'s pull request moves its card (e.g. to Done).',
            'Sign in with Microsoft (Outlook.com and Microsoft 365), next to Google and GitHub.',
            'Documents open full width; the "+" next to a block works again and inserts real bullet, numbered and check lists.',
            'Fixed: a document with an uploaded image as its icon showed a long line of code on the workspace home and project page. Fixed: a rule that assigns an issue to the person it runs as (e.g. "assign me when it moves to Testing") now notifies them — from Automation "rule name".',
            '"Star on GitHub" with the live star count on the homepage and in the account menu.',
        ]
    },
    {
        version: 'v1.41.1',
        title: 'Calendar opens again after deleting a board',
        tag: 'Fix',
        date: '2026-10-26',
        items: [
            'Calendar: no more “Something went wrong” (error 500) after a board with dated issues was deleted — the issues of deleted boards are simply left out.',
        ]
    },
    {
        version: 'v1.41.0',
        title: 'Bring your data: Trello, Jira and Notion imports, project export',
        tag: 'Feature',
        date: '2026-10-26',
        items: [
            'Import data (account menu): upload an export from another tool, check the preview — nothing is created until you confirm — and follow the progress. Imports continue where they stopped if anything goes wrong, without creating duplicates, and send no notification storm.',
            'Trello: a board’s JSON export becomes a new board — lists as columns, cards as issues in the same order, with descriptions, due dates, labels and checklists (or subtasks). Map Trello members to project members.',
            'Jira: an “Export issues → CSV (all fields)” file becomes a new board. Map statuses to columns, and types, priorities and people; epics, stories and sub-tasks stay nested; sprints, links between issues, labels, story points and comments come along.',
            'Notion: a “Markdown & CSV” export becomes pages and folders in your project — links between pages, images and files included; databases come over as tables. Everything arrives as a draft for you to review and publish.',
            'Project export (project settings): download a project — boards, issues, docs, whiteboards, plans, task lists and files — as one archive with checksums. Restore it as a new project here or on another Brainitive (Import data → Brainitive project).',
            'Every import shows what couldn’t come over (e.g. custom fields, attachments), so nothing disappears silently.',
        ]
    },
    {
        version: 'v1.40.1',
        title: 'Docker builds on a Mac',
        tag: 'Fix',
        date: '2026-10-26',
        items: [
            'Self-hosting: building the Docker image from a checkout on macOS (or Windows) no longer fails with “Cannot find module @/components/…”.',
        ]
    },
    {
        version: 'v1.40.0',
        title: 'Automations, email to helpdesk, SLAs and recurring issues',
        tag: 'Feature',
        date: '2026-10-25',
        items: [
            'Workflow automation (project page → Tools → Automations, for project admins): when an issue is created, moved, assigned, commented or past its due date, or a ticket arrives — and it matches your conditions — assign it, move it, set priority, type, due date or a label, comment, notify someone, or create an issue from a ticket. Test a rule on a real issue before switching it on; every run is logged.',
            'Recurring issues (board menu → Recurring issues): daily, weekly on chosen days, every N weeks, monthly (a day or e.g. the last weekday) or yearly — with assignee, priority, labels and a due date. Or open an issue and choose “Make this recurring”.',
            'Email to helpdesk (helpdesk settings → Email): connect your support mailbox over IMAP, and every email becomes a ticket. Replies from the ticket go back by email in the same thread; customer answers land on the ticket. Auto-replies, mailing lists and strangers replying into a ticket are kept out.',
            'Or use a forwarding address: forward your support mail to the helpdesk’s own address (needs an inbound mail provider; see the self-hosting guide).',
            'Helpdesk SLAs (helpdesk settings → SLA): first-response and resolution targets per priority, business hours, and a pause while you wait on the customer. Tickets show “Respond in 2h” or “Breached”, you can filter and sort by SLA, and you’re warned before a breach. Insights → Helpdesk shows breaches and the share of SLAs met.',
            'Project health: saving a status update without a health status now tells you right at the button, and an unsaved update survives a page refresh.',
            'AI in descriptions: “Replace” now works for every AI answer, including long prompts from “Generate prompt”.',
            'Checklists in documents no longer overlap their text.',
        ]
    },
    {
        version: 'v1.39.2',
        title: 'Docker: no app key to copy',
        tag: 'Fix',
        date: '2026-10-25',
        items: [
            'Self-hosting: the first start generates the app key itself, so cp .env.docker.example .env and docker compose up -d is all it takes. Before, a skipped copy-paste of the key left every container restarting. A .env from the old MariaDB setup now works too.',
        ]
    },
    {
        version: 'v1.39.1',
        title: 'Self-hosting with Docker works again, every timezone',
        tag: 'Fix',
        date: '2026-10-25',
        items: [
            'Self-hosting: a fresh Docker install now starts. The database is PostgreSQL (what Brainitive is built and tested on) instead of MariaDB, where setup stopped during the first start. Sign-up works before mail is set up, and the queue and scheduler containers no longer show “unhealthy”. Older installs: git pull and docker compose up -d.',
            'Workspace settings → Timezone lists every timezone in the world, with search and the current UTC offset. Before, the list ended after the first 100 (no Europe, Asia or Pacific).',
        ]
    },
    {
        version: 'v1.39.0',
        title: 'Notifications, insights, webhooks and your own sidebar',
        tag: 'Feature',
        date: '2026-10-24',
        items: [
            'A new notification inbox: the bell opens a panel with All · Unread · Mentions · Assigned, grouped by day. Mark items unread or archive them, and open them right where you are. The inbox page adds bulk actions and keyboard shortcuts (j / k / Enter / e).',
            'Choose how you hear about things: Profile → Notifications has In-app, Push and Email per category (mentions, assignments, comments, due dates, sprints, helpdesk, leads…).',
            'Notifications for what was missing: comments on issues you watch, issues due tomorrow or overdue, sprints started or completed, tasks and leads assigned to you, accepted invitations, role changes and ticket status changes. Assignments now notify however they were made (MCP, meeting notes, plan import). Re-saving a description no longer repeats @mentions.',
            'Insights for workspace admins (sidebar → Insights): throughput, cycle time, the team, project health, helpdesk and leads, and usage, across every project and for any period.',
            'Outgoing webhooks (Workspace settings → Webhooks): send signed events (issues, pages, sprints, tickets, leads, time and invoices) to your own systems, with a delivery log, redeliver and “Send test”.',
            'Customize your sidebar: hide entries you don’t use, choose which groups each project shows, and pin the boards, lists and docs you open every day. Hidden entries stay in ⌘K.',
            'The project page is easier to scan: members and quick links on top, then Pinned, Recently updated, Work, Docs and Customer-facing sections. Empty sections take one line.',
            'Bulk edit on boards: in the List view, select issues (shift-click for a range) and change status, assignee, priority, type, sprint, due date or labels in one go, or delete them.',
            'My Day: click the circle to mark a task done (and again to reopen it); done tasks move to the bottom. The circle works in My Work too.',
            'Meeting notes → issues: the meeting date now defaults to today, so deadlines like “by Friday” become due dates.',
            'Billing: moving a project to another workspace takes its unbilled hours along, and only workspace admins can change whether an entry is billable.',
            'Links to brainitive.app now show a proper preview card when you share them.',
        ]
    },
    {
        version: 'v1.38.0',
        title: 'Time tracking & client billing',
        tag: 'Feature',
        date: '2026-10-24',
        items: [
            'New Billing → Activities (workspace admins): define what your team bills — per hour (e.g. Programming €120/h) or as a fixed price (e.g. SSM setup €450) — and pick a default activity per issue type.',
            'Log time with an activity: “Log work” and the focus timer now ask what you worked on and on which day. The activity is preselected from the issue type, then your last choice.',
            'New My timesheet (sidebar): your week with day and week totals — add time on an issue or a project, edit or delete entries. Time you logged before this release is there too, and issue totals stay the same.',
            'Billing → Clients: the companies you bill, with address, VAT ID and their own VAT rate. Assign their projects on the client page or in project settings.',
            'Billing → Time: everyone’s logged time for last month (or any period) — filter by project, client, person, activity or billable, group by ticket, person, activity or day, and export exactly that to Excel.',
            'Billing → Reports: build a time report (or, in invoice mode, an invoice) for a client and period with a live preview of net, VAT and total. Leave single entries out, show or hide names and notes.',
            'Finalize to freeze a report: its hours are locked so nothing is billed twice, fixed prices are charged once per ticket, and invoice mode takes the next consecutive number. Track it as sent or paid, or void it to make the hours billable again.',
            'Download any report as an A4 PDF or Excel, or send the PDF to your client by email — replies come back to you. Billing → Settings holds your currency, VAT (net or gross), rounding, company and bank details and logo.',
            'Prices, clients and reports are only visible to workspace admins; members see activities and their own hours, never rates.',
        ]
    },
    {
        version: 'v1.37.0',
        title: 'Workload, project health and meeting notes → issues',
        tag: 'Feature',
        date: '2026-10-24',
        items: [
            'New Workload view per project (sidebar → Workload): each person’s planned hours next to their weekly capacity, week by week, with overload shown in hours.',
            'Set a weekly capacity per person and adjust single weeks for leave. Plan issues into weeks yourself — Brainitive suggests the remaining estimate but never spreads work on its own.',
            'When estimates, assignees or status change, planned hours stay put and show up under “Needs review”. Unestimated work and capacity that isn’t set are shown separately, never as zero.',
            'New Project Health view (sidebar → Health): open, completed, overdue, due-soon, unassigned and blocked work at a glance, with the issues behind every number. “Due today” is never overdue, and missing data shows as “Unknown”.',
            'Save status updates with your own On track / At risk / Off track and link each point to its issue, plan or metric — optionally drafted by your workspace’s AI, always reviewed by you.',
            'New: turn meeting notes into issues — “Extract action items” in a doc’s ⋯ menu, or paste notes on the project page. AI suggests each action with the sentence it came from; you edit, pick and create only what you want.',
            'Owners are only matched to project members, deadlines are only filled when the date is clear, possible duplicates are marked, and clicking Create twice never creates issues twice.',
            'Boards now have a single Create button (top bar) — it creates the card on the board you’re on.',
            'The homepage shows everything from the latest releases.',
        ]
    },
    {
        version: 'v1.36.0',
        title: 'Quick capture and related content',
        tag: 'Feature',
        date: '2026-10-24',
        items: [
            'New Quick capture: press C anywhere (or use the Capture button, or the round button on your phone) to save an idea without picking a project.',
            'New Captures page: your private inbox with Inbox / Processed / Archived tabs and search. Edit, archive and restore entries — only you can see them.',
            'Organize a capture into a board card, a task, a doc, a bookmark or a snippet. Check and edit the preview first, then open what was created straight from the link. Markdown, code blocks and links are kept.',
            'Organizing is safe to retry: a double click never creates two items, and if anything fails the capture stays in your inbox.',
            'Browser extension: a new Inbox tab saves the current page to your captures, to organize later.',
            'New “Related content” on issues, docs, whiteboards, helpdesk tickets and plans: link related work in a project with “Link related content”.',
            'Everything also shows “Referenced by” — what links to it, one click away. Plan rows and whiteboard cards linked to an issue show up there automatically and disappear when you unlink them.',
            'Private drafts and anything you can’t open never appear in these lists or in the picker; deleted items drop out and come back when restored.',
            'Fixes: issue numbers in a new project start at KEY-1, #item links to newly created cards open them, and “Mark as read” in notifications works on touch screens and with the keyboard.',
        ]
    },
    {
        version: 'v1.35.1',
        title: 'Fixes found by the new end-to-end tests',
        tag: 'Patch',
        date: '2026-10-23',
        items: [
            'You can @mention people in issue comments again — the member picker opens when you type @.',
            'Creating labels and issue types in project settings (and labels from an issue) closes the dialog and shows them right away.',
            'Helpdesk: the custom fields in a ticket’s sidebar show the customer’s answers and save your changes; the column menu (edit, WIP limit, delete) and attachment preview / delete work.',
            'Tickets and issues created at the same moment no longer fail, and projects with the same key in different workspaces can both take tickets.',
            'Visitors who open a lead form a few times are no longer refused when they submit it.',
            'Validation messages no longer disappear when a form is sent right after the page loads.',
            'Settings links are only shown to people who can open them.',
        ]
    },
    {
        version: 'v1.35.0',
        title: 'Saved board views',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Save how you look at a board — filters, custom-field filters, layout (board, list, timeline, calendar), list sorting and hidden list columns — as a named view (Views menu in the board toolbar).',
            'Personal views are only yours; board managers can share views with everyone on the board. “Only my issues” in a shared view shows each person their own issues.',
            'Changed something? The view shows a dot and “Save changes”. Rename, duplicate, share or delete views from the menu.',
            'Pick a view that opens by default on a board (★). “All issues” takes you back to the plain board.',
            'If a view refers to a column, field or member that no longer exists, that part is left out with a short note.',
        ]
    },
    {
        version: 'v1.34.2',
        title: 'More security hardening',
        tag: 'Security',
        date: '2026-10-23',
        items: [
            'Profile → Connected apps: see which AI apps, the browser extension and the CLI can access your account, and disconnect any of them. Changing your password disconnects them all.',
            'Invited people join a workspace once their email address is verified.',
            'Project viewers are read-only in their project, whatever their workspace role.',
            'Mentions, notifications and bookmark links only open safe addresses; file uploads and exports are stricter about what can run in your browser.',
            'Connecting an AI app shows where you will be sent back to — only continue if you started the connection yourself.',
        ]
    },
    {
        version: 'v1.34.1',
        title: 'Security hardening',
        tag: 'Security',
        date: '2026-10-23',
        items: [
            'Email verification links are now signed and expire; opening one no longer signs you in — you log in as usual afterwards. Older, unsigned links stop working: request a new one from the verification screen.',
            'Password reset emails always link to the official app address.',
            'Stronger protection against password guessing: repeated failed logins lock the account for a while, whatever network they come from.',
        ]
    },
    {
        version: 'v1.34.0',
        title: 'Lead generation forms',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'New in every project: Lead forms (Add content → Lead form). Each form has its own leads board — every submission lands there as a card, like helpdesk tickets.',
            'Start from 12 templates (demo request, contact sales, free trial, newsletter, agency brief, quote request, real estate, consultation, webinar, event lead capture, course enrolment, job interest pool) or a blank form.',
            'Build it your way: several steps, 12 question types, answer cards with icons or emoji, and logic — show a question or a whole step only for certain answers. Live preview on desktop and mobile; changes save automatically.',
            'Make it yours: colours and presets, font, corners, button style, layout (card, split with an image, full), progress bar or steps, your logo and a cover image.',
            'Share it at brainitive.app/f/your-name or embed it on your website (the snippet resizes itself). Hidden questions fill from the link (?campaign=…) and UTM tags are saved with each lead.',
            'After submit: a thank-you screen or your own page, a confirmation email to the lead and a notification to the team members you pick.',
            'Analytics: views, starts, submissions, conversion per day and how far visitors get through the steps.',
            'Webhooks: send every new lead to Zapier, Make or your CRM (signed), and let other tools push leads onto the board with a token.',
        ]
    },
    {
        version: 'v1.33.0',
        title: 'Helpdesk templates: support desks, forms, registrations and more',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Start a new helpdesk from one of 17 templates — Customer support, IT helpdesk, Bug reports, Returns, Website contact form, Quote request, Newsletter & waitlist, Partnerships, Press, Event registration, Appointment requests, Volunteers, Job applications, HR requests, Office & facilities, Feature requests and Beta sign-up.',
            'Each template brings its own board columns and form fields (dropdowns, single and multiple choice, dates, numbers, checkboxes) and shows a preview before you create it.',
            'Form texts are now yours to change in helpdesk settings: title, button label, placeholder, thank-you message — and you can hide the Subject field for forms that don’t need one.',
            'Forms can end with a thank-you message instead of the request page, and required fields are now checked when the form is sent.',
            'The floating widget now supports single choice, multiple choice and date fields, and hides attachments when you turn them off.',
        ]
    },
    {
        version: 'v1.32.0',
        title: 'Template gallery with 20 templates',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            '“New project” now opens a template gallery: browse by category (Software, Marketing, Agency & clients, HR & people, Sales, Operations & finance, Personal & team) or search.',
            'Open a template to see exactly what you get: the workflow, issue types, fields, labels, starter issues, docs, checklists and plans.',
            '16 new templates, including Bug tracking, Release & DevOps, Marketing campaign, Content calendar, Social media, Event planning, Client onboarding, Design requests, Recruiting, Employee onboarding, Sales pipeline, Budget approvals, Office & IT requests, Personal tasks, Team OKRs and Meeting notes.',
            'Templates saved in your workspace show up under “Your workspace”. You can also create a project from the command palette (⌘K → Create Project).',
        ]
    },
    {
        version: 'v1.31.0',
        title: 'Project templates',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Start a new project from a template: Software development, Scrum project, Client website delivery or Product launch — with boards and columns, starter issues, docs, checklists and a timeline.',
            'Pick a start date: due dates and plan bars in the template move with it.',
            'See what you’ll get before creating, and leave out sample issues, docs, lists or plans you don’t need.',
            'Workspace admins can save any project as a template (Project settings → General → Save as template) and update it later — projects already created stay as they are.',
            'Templates never copy comments, attachments, history, people or unpublished docs.',
        ]
    },
    {
        version: 'v1.30.0',
        title: 'Custom fields on issues',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Add your own fields to issues in Project settings → Fields: text, number, date, a yes/no checkbox or a list of options — for all boards or only some.',
            'Fill them in from the issue details. A 0 or “No” is a real value, different from leaving the field empty.',
            'Show fields as columns in the board’s List view (“Fields” button), and sort by any column with a click — empty values always go last.',
            'Filter the board by your fields from the Filter menu. Filters stay in the address, so a filtered board can be shared.',
            'Renaming an option updates every issue; removing one keeps it on the issues that already have it. Changes show up in the issue history.',
        ]
    },
    {
        version: 'v1.29.0',
        title: 'My Day and a focus timer',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'My Work now starts with “My Day”: pick what you’ll work on today with the ☀ on any task — board issues and list tasks alike — and drag them into your order.',
            'Planning never changes a task: status, due date and assignee stay as they are. Unfinished tasks only come back tomorrow when you click “Bring over”.',
            'Focus timer: press ▶ on an issue in My Day. The timer shows in the top bar on every page and keeps running across tabs and devices.',
            'Stop it to review the time (change it if you took a break, add a note) before it is logged on the issue — the same as “Log work”, and never twice.',
        ]
    },
    {
        version: 'v1.28.0',
        title: 'Lists, redesigned — with reminders',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Lists look and work like a to-do app: type a task and press Enter to add the next one; date, person, repeat and reminder are one click away.',
            'Overdue tasks are red, today’s amber. Drag tasks to put them in order; done tasks fold away under “Completed”.',
            'Click a task to open it on the side: notes, due date and time, start, assignee, repeat and reminder — everything saves as you go.',
            'Reminders: at the due time, 10 minutes, 1 hour or 1 day before — in the bell and as a browser notification. Tasks without an assignee remind the person who added them.',
            'Every morning at 8:00 you get one summary of the list tasks that are due today or overdue. You can turn it off in your profile.',
            'Reminder times now follow your own time zone (start reminders used to arrive late outside UTC).',
            'Fixed: existing due dates showed an empty date field.',
        ]
    },
    {
        version: 'v1.27.1',
        title: 'Swimlane fixes',
        tag: 'Patch',
        date: '2026-10-23',
        items: [
            'Saving swimlanes now confirms it, and “Unsaved changes” goes away after the save.',
            'If a swimlane save or a drag into a lane fails, you now see why instead of nothing happening.',
            'Saved swimlanes now show up on the board for everyone. Before, a lane choice you had once made in the board toolbar kept overriding them. Your own choice still works until the board’s swimlanes are changed again.',
            'Fix versions without a date are listed after the dated ones everywhere.',
        ]
    },
    {
        version: 'v1.27.0',
        title: 'Swimlanes on Boards',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Split the board into rows: board settings → Swimlanes. Add as many lanes as you like, each with its own conditions — priority, type, label, assignee, fix version or overdue. A card goes into the first lane that matches, everything else into the last lane.',
            'Example: “🔥 Urgent” (Highest or High) → “Bugs” → “Everything else”.',
            'Everyone can switch for themselves in the board toolbar: your custom lanes, or lanes by assignee, priority, type or fix version. Lanes collapse with one click.',
            'In the “by assignee / priority / type / fix version” lanes, dragging a card into another lane changes that field too.',
            'Fixed: a dragged card no longer stays faded when it’s dropped back where it was.',
        ]
    },
    {
        version: 'v1.26.0',
        title: 'Releases and Fix Versions',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Plan releases per project (sidebar → Releases): name, start and release date, description — with a progress bar of done, in progress and to-do issues and an overdue warning.',
            'Give issues a Fix version from the issue sidebar, or add them from the release page. Issues already shipped in another release are flagged.',
            'Release a version in one step and decide where unfinished issues go — the next release or no fix version.',
            'Release notes: generate a draft from the finished issues (Features, Improvements, Fixes — titles only), edit it, and publish it to a public page and project changelog. Later edits stay private until you publish the update.',
            'Fixed: shared docs and public pages now show headings, lists and links formatted; a wrong link method shows the proper error page instead of a server error.',
        ]
    },
    {
        version: 'v1.25.1',
        title: 'Correct Website and Contact Addresses',
        tag: 'Fix',
        date: '2026-10-23',
        items: [
            'All links, the browser extension and the privacy, terms and refund pages now point to www.brainitive.app.',
            'Contact addresses are now @brainitive.app (hello, privacy, billing, legal, security).',
        ]
    },
    {
        version: 'v1.25.0',
        title: 'GitHub: Branches, Commits, PRs and Deployments on Issues',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Connect GitHub in Project settings → Integrations: paste one webhook URL and secret into your repository — no GitHub login or tokens needed.',
            'Mention the issue key and it links itself: commits like “fix(BRAI-123): …”, branches like “feat/brai-123_fix_column” and pull request titles.',
            'A new Development section on every issue shows its branches, commits, pull requests (open, draft, merged) and where it is deployed — e.g. “production · deployed”.',
            'Copy a ready “git checkout -b …” command or commit prefix straight from the issue.',
            'Fixed: links to an issue from search, the command palette or the AI connector now open the issue instead of just the board.',
        ]
    },
    {
        version: 'v1.24.0',
        title: 'One Command Palette',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Press ⌘K (Ctrl+K) for one palette that searches and runs commands — the separate search window and Ctrl+. list are merged.',
            'Recent: the boards, documents, plans, projects and issues you opened last are one keystroke away. They are only shown while you still have access.',
            'Search now also finds boards and plans, and lists results from the project you’re in first.',
            'Type “>” (or press Ctrl+.) for commands only: go to My Work, Calendar, Inbox or another workspace, create issues, pages or snippets, switch dark mode, open help.',
            'Full keyboard use: arrows to move, Enter to open, Esc to close; works on phones from the search field at the top.',
        ]
    },
    {
        version: 'v1.23.1',
        title: 'Priorities and Board Columns Fixed',
        tag: 'Fix',
        date: '2026-10-23',
        items: [
            'Priorities work again: Highest, High, Medium, Low and Lowest are available on every board, and new issues start at Medium.',
            'New columns appear right away. “New column” on the board now creates it in place — type a name, press Enter, add the next one.',
            'Rename a column straight from its ⋯ menu on the board.',
            'Board settings: added, renamed and deleted columns show without reloading, and “Edit column” always opens with that column’s values.',
            'Two columns with the same name (or emoji-only names) no longer fail to save.',
        ]
    },
    {
        version: 'v1.23.0',
        title: 'AI Connector: Comments, Sprints and Plans',
        tag: 'Feature',
        date: '2026-10-23',
        items: [
            'Claude and ChatGPT (via the Brainitive connector) can now read a card’s comments and post updates as you — with @email mentions — without touching the description.',
            'Sprint planning through the connector: list sprints and the backlog, create and edit sprints, move issues (with their subtasks), start and complete sprints with the same rules as the backlog page.',
            'Gantt plans through the connector: read rows, bars and dependencies and make targeted edits. If you changed the plan in the browser meanwhile, nothing is overwritten; date changes on linked rows update their issues.',
            'Retries are safe: a connector that repeats a request never posts, moves or edits twice.',
            'Fixed: two people starting a sprint at the same moment could end up with two active sprints; replies can only attach to comments of the same issue.',
        ]
    },
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
                    <div className="flex items-center gap-1">
                        <Button variant="ghost" size="sm" className="h-8 gap-1.5 text-muted-foreground" asChild>
                            <a href={DOCS_URL} target="_blank" rel="noopener">
                                <BookOpen className="size-4" />
                                <span className="hidden sm:inline">Documentation</span>
                                <span className="sr-only sm:hidden">Documentation</span>
                            </a>
                        </Button>
                        <Button variant="ghost" size="icon" className="size-8 shrink-0" onClick={() => onOpenChange(false)}>
                            <X className="size-4" /><span className="sr-only">Close</span>
                        </Button>
                    </div>
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
