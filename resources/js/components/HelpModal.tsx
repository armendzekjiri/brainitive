import React, { useState } from 'react';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { LayoutGrid, FileText, PencilRuler, BookMarked, BrainCircuit, X, History, Sparkles } from 'lucide-react';
import { ScrollArea } from '@/components/ui/scroll-area';

interface HelpModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

type TabType = 'welcome' | 'changelog';

const CHANGELOG = [
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
                <div className="flex items-center justify-between px-4 pt-4 pb-0 gap-2">
                    <div className="flex items-center gap-1 bg-muted/50 rounded-lg p-1">
                        <button
                            type="button"
                            onClick={() => setActiveTab('welcome')}
                            className={cn(
                                'flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150',
                                activeTab === 'welcome'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            )}
                        >
                            <BrainCircuit className={cn("size-4", activeTab === 'welcome' ? 'text-primary' : '')} />
                            Welcome
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('changelog')}
                            className={cn(
                                'flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150',
                                activeTab === 'changelog'
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            )}
                        >
                            <History className={cn("size-4", activeTab === 'changelog' ? 'text-primary' : '')} />
                            Changelog
                        </button>
                    </div>
                    <Button variant="ghost" size="icon" className="size-8 shrink-0" onClick={() => onOpenChange(false)}>
                        <X className="size-4" /><span className="sr-only">Close</span>
                    </Button>
                </div>

                {/* Main Content Area */}
                <div className="flex flex-col">
                    <div className="px-5 py-6 h-[80vh] overflow-y-auto">

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

                                <div className="grid sm:grid-cols-2 gap-4">
                                    <div className="flex items-start gap-4 p-4 rounded-xl border bg-card/50 shadow-sm hover:shadow-md transition-shadow">
                                        <div className="p-2.5 bg-blue-500/10 rounded-lg text-blue-500 shrink-0">
                                            <LayoutGrid className="size-5" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-sm">Kanban Boards</h4>
                                            <p className="text-xs text-muted-foreground mt-1 leading-snug">Manage tasks and project workflows intuitively with dynamic, drag-and-drop boards.</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-4 p-4 rounded-xl border bg-card/50 shadow-sm hover:shadow-md transition-shadow">
                                        <div className="p-2.5 bg-emerald-500/10 rounded-lg text-emerald-500 shrink-0">
                                            <FileText className="size-5" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-sm">Rich Text Documents</h4>
                                            <p className="text-xs text-muted-foreground mt-1 leading-snug">Create highly interactive pages with rich formatting, slash commands, and table of contents.</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-4 p-4 rounded-xl border bg-card/50 shadow-sm hover:shadow-md transition-shadow">
                                        <div className="p-2.5 bg-violet-500/10 rounded-lg text-violet-500 shrink-0">
                                            <PencilRuler className="size-5" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-sm">Excalidraw Whiteboards</h4>
                                            <p className="text-xs text-muted-foreground mt-1 leading-snug">Visualize your ideas, sketches, and diagrams on an infinite collaborative canvas.</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-4 p-4 rounded-xl border bg-card/50 shadow-sm hover:shadow-md transition-shadow">
                                        <div className="p-2.5 bg-amber-500/10 rounded-lg text-amber-500 shrink-0">
                                            <BookMarked className="size-5" />
                                        </div>
                                        <div>
                                            <h4 className="font-semibold text-sm">Bookmarks & Snippets</h4>
                                            <p className="text-xs text-muted-foreground mt-1 leading-snug">Save your favorite links, APIs, and essential code snippets in one central place.</p>
                                        </div>
                                    </div>
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
                                                <div className="w-[calc(100%-4rem)] md:w-[calc(50%-2.5rem)] p-4 rounded-xl border bg-card/50 shadow-sm">
                                                    <div className="flex items-center justify-between mb-1">
                                                        <h3 className={cn("font-bold", isLatest ? "text-primary" : "text-foreground")}>
                                                            {log.version} • {log.title}
                                                        </h3>
                                                        <span className={cn(
                                                            "text-xs px-2 py-0.5 rounded-full font-medium ml-2",
                                                            isLatest ? "bg-muted text-muted-foreground" : "text-muted-foreground"
                                                        )}>
                                                            {log.tag}
                                                        </span>
                                                    </div>
                                                    <span className="text-xs font-mono text-muted-foreground bg-muted/50 px-1.5 py-0.5 rounded inline-block mb-1">
                                                        {log.date}
                                                    </span>
                                                    <ul className="text-sm text-muted-foreground space-y-1.5 list-disc list-inside mt-2">
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
