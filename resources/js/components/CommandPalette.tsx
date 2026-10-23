/**
 * CommandPalette — the one launcher (⌘K / Ctrl+K; Ctrl+. opens it in command mode).
 *
 * Empty query: recent destinations, navigation and create commands. Typing: matching
 * commands, then search results (debounced, older requests aborted). A leading ">" shows
 * commands only. Selecting never changes data by itself: commands navigate or open the
 * existing create forms, which check permissions on submit.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { router, usePage } from '@inertiajs/react';
import { Command as CommandPrimitive } from 'cmdk';
import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import {
    AlertCircle, Bookmark as BookmarkIcon, Building2, Code, FileText, FolderOpen, GanttChart, LayoutGrid, Loader2, Search, SquareKanban, X,
} from 'lucide-react';
import type { PageProps } from '@/types';

// ── Commands ──────────────────────────────────────────────────────────────────
export interface PaletteCommand {
    id: string;
    label: string;
    description?: string;
    /** Extra words to match, e.g. "new add" for create commands. */
    keywords?: string;
    icon: React.ReactNode;
    group: string;
    action: () => void;
}

export function CmdIcon({ children, color }: { children: React.ReactNode; color?: string }) {
    return (
        <div
            className="flex size-8 shrink-0 items-center justify-center rounded-lg"
            style={{ background: color ?? 'hsl(var(--muted))', color: color ? 'white' : 'hsl(var(--muted-foreground))' }}
        >
            {children}
        </div>
    );
}

// ── Results ───────────────────────────────────────────────────────────────────
type ResultType = 'workspace' | 'project' | 'board' | 'plan' | 'snippet' | 'bookmark' | 'item' | 'page';

interface Result {
    id: number;
    type: ResultType;
    title: string;
    subtitle: string | null;
    url: string;
}

type SearchResponse = Partial<Record<'workspaces' | 'projects' | 'boards' | 'plans' | 'snippets' | 'bookmarks' | 'items' | 'pages', Result[]>>;

const RESULT_GROUPS: { key: keyof SearchResponse; heading: string }[] = [
    { key: 'items', heading: 'Issues' },
    { key: 'pages', heading: 'Documents' },
    { key: 'boards', heading: 'Boards' },
    { key: 'plans', heading: 'Plans' },
    { key: 'projects', heading: 'Projects' },
    { key: 'workspaces', heading: 'Workspaces' },
    { key: 'snippets', heading: 'Snippets' },
    { key: 'bookmarks', heading: 'Bookmarks' },
];

const RESULT_ICONS: Record<ResultType, { Icon: typeof Search; color: string }> = {
    item: { Icon: LayoutGrid, color: '#0E83DC' },
    page: { Icon: FileText, color: '#22c55e' },
    board: { Icon: SquareKanban, color: '#6366f1' },
    plan: { Icon: GanttChart, color: '#f97316' },
    project: { Icon: FolderOpen, color: '#f59e0b' },
    workspace: { Icon: Building2, color: '#64748b' },
    snippet: { Icon: Code, color: '#8b5cf6' },
    bookmark: { Icon: BookmarkIcon, color: '#0969da' },
};

function ResultIcon({ type }: { type: ResultType }) {
    const { Icon, color } = RESULT_ICONS[type];
    return <CmdIcon color={color}><Icon className="size-4" /></CmdIcon>;
}

// ── Recent destinations ───────────────────────────────────────────────────────
// Only {type, id} is stored, per user; titles come from the server on open, which drops
// anything the user can no longer see.
const RECENT_PREFIX = 'brainitive.palette.recent.';
const RECENT_TYPES: ResultType[] = ['project', 'board', 'page', 'item', 'plan'];
const MAX_RECENT = 12;

interface RecentRef { type: ResultType; id: number }

function readRecent(userId: number): RecentRef[] {
    try {
        const parsed = JSON.parse(localStorage.getItem(RECENT_PREFIX + userId) ?? '[]');
        return Array.isArray(parsed) ? parsed.filter((r) => RECENT_TYPES.includes(r?.type) && Number.isInteger(r?.id)).slice(0, MAX_RECENT) : [];
    } catch {
        return [];
    }
}

function writeRecent(userId: number, refs: RecentRef[]) {
    try {
        localStorage.setItem(RECENT_PREFIX + userId, JSON.stringify(refs.slice(0, MAX_RECENT)));
    } catch {
        // storage unavailable (private mode) — recents just aren't remembered
    }
}

export function rememberRecent(userId: number | undefined, ref: RecentRef) {
    if (!userId || !RECENT_TYPES.includes(ref.type)) return;
    writeRecent(userId, [ref, ...readRecent(userId).filter((r) => !(r.type === ref.type && r.id === ref.id))]);
}

/** Records visits to projects, boards, documents and plans; drops other accounts' history. */
export function useRecentTracking() {
    const userId = usePage<PageProps>().props.auth?.user?.id;

    useEffect(() => {
        if (!userId) return;
        try {
            for (let i = localStorage.length - 1; i >= 0; i--) {
                const key = localStorage.key(i);
                if (key?.startsWith(RECENT_PREFIX) && key !== RECENT_PREFIX + userId) localStorage.removeItem(key);
            }
        } catch {
            // ignore
        }

        const track = (page: { component: string; props: Record<string, unknown> }) => {
            const prop = (name: string) => (page.props[name] as { id?: number } | undefined)?.id;
            const ref: RecentRef | null =
                page.component === 'Boards/Show' && prop('board') ? { type: 'board', id: prop('board')! }
                : page.component === 'Pages/Show' && prop('page') ? { type: 'page', id: prop('page')! }
                : page.component === 'Plans/Show' && prop('plan') ? { type: 'plan', id: prop('plan')! }
                : page.component === 'Projects/Show' && prop('project') ? { type: 'project', id: prop('project')! }
                : null;
            if (ref) rememberRecent(userId, ref);
        };

        return router.on('navigate', (event) => track(event.detail.page as never));
    }, [userId]);
}

// ── Palette ───────────────────────────────────────────────────────────────────
interface CommandPaletteProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Pre-filled query, e.g. ">" to open in command mode. */
    initialQuery?: string;
    commands?: PaletteCommand[];
}

const DEBOUNCE_MS = 200;
const MIN_QUERY_LENGTH = 2;
const MAX_COMMANDS_WHILE_SEARCHING = 5;

const matches = (cmd: PaletteCommand, q: string) => {
    const haystack = `${cmd.label} ${cmd.description ?? ''} ${cmd.keywords ?? ''} ${cmd.group}`.toLowerCase();
    return q.toLowerCase().split(/\s+/).filter(Boolean).every((word) => haystack.includes(word));
};

export function CommandPalette({ open, onOpenChange, initialQuery = '', commands = [] }: CommandPaletteProps) {
    const { auth, currentProjectId } = usePage<PageProps>().props;
    const userId = auth?.user?.id;
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResponse>({});
    const [status, setStatus] = useState<'idle' | 'loading' | 'error' | 'done'>('idle');
    const [recent, setRecent] = useState<Result[]>([]);
    const [retry, setRetry] = useState(0);
    const requestSeq = useRef(0);
    const inputRef = useRef<HTMLInputElement>(null);

    const commandMode = query.startsWith('>');
    const searchText = (commandMode ? query.slice(1) : query).trim();

    // Reset while rendering the opening frame, so the previous query never flashes.
    const [wasOpen, setWasOpen] = useState(open);
    if (open !== wasOpen) {
        setWasOpen(open);
        if (open) {
            setQuery(initialQuery);
            setResults({});
            setStatus('idle');
        }
    }

    // On open: focus the input and load recent destinations (server-checked).
    useEffect(() => {
        if (!open) return;
        setTimeout(() => inputRef.current?.focus(), 30);

        if (!userId) return;
        const refs = readRecent(userId);
        if (refs.length === 0) {
            setRecent([]);
            return;
        }
        const controller = new AbortController();
        axios.post(route('command-palette.recent'), { entries: refs }, { signal: controller.signal })
            .then(({ data }) => {
                const entries: Result[] = data.entries ?? [];
                setRecent(entries);
                // Forget what is gone or no longer accessible.
                writeRecent(userId, entries.map((e) => ({ type: e.type, id: e.id })));
            })
            .catch(() => setRecent([]));
        return () => controller.abort();
    }, [open, initialQuery, userId]);

    // Debounced search; a newer query aborts the older request and stale answers are ignored.
    useEffect(() => {
        if (!open || commandMode || searchText.length < MIN_QUERY_LENGTH) {
            setResults({});
            setStatus('idle');
            return;
        }
        const seq = ++requestSeq.current;
        const controller = new AbortController();
        setStatus('loading');
        const timer = setTimeout(() => {
            axios.get(route('search'), { params: { q: searchText, project: currentProjectId ?? undefined }, signal: controller.signal })
                .then(({ data }) => {
                    if (seq !== requestSeq.current) return;
                    setResults(data);
                    setStatus('done');
                })
                .catch((error) => {
                    if (axios.isCancel(error) || seq !== requestSeq.current) return;
                    setStatus('error');
                });
        }, DEBOUNCE_MS);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, commandMode, searchText, currentProjectId, retry]);

    const visibleCommands = useMemo(() => {
        if (!searchText) return commandMode ? commands : [];
        const found = commands.filter((c) => matches(c, searchText));
        return commandMode ? found : found.slice(0, MAX_COMMANDS_WHILE_SEARCHING);
    }, [commands, searchText, commandMode]);

    const close = useCallback(() => onOpenChange(false), [onOpenChange]);

    const runCommand = (cmd: PaletteCommand) => {
        close();
        setTimeout(() => cmd.action(), 60); // let the dialog close (and restore focus) first
    };

    const openResult = (result: Result) => {
        rememberRecent(userId, { type: result.type, id: result.id });
        close();
        router.visit(result.url);
    };

    const resultCount = RESULT_GROUPS.reduce((n, g) => n + (results[g.key]?.length ?? 0), 0);
    const emptyQuery = searchText === '';
    const commandGroups = Array.from(new Set(commands.map((c) => c.group)));
    const nothingFound =
        !emptyQuery && visibleCommands.length === 0 && (commandMode || (status === 'done' && resultCount === 0) || searchText.length < MIN_QUERY_LENGTH);

    const commandRow = (cmd: PaletteCommand) => (
        <CommandItem key={cmd.id} value={`cmd:${cmd.id}`} onSelect={() => runCommand(cmd)} className="flex items-center gap-3 cursor-pointer rounded-lg px-2 py-2 aria-selected:bg-accent">
            {cmd.icon}
            <div className="flex min-w-0 flex-col">
                <span className="text-sm font-medium leading-snug">{cmd.label}</span>
                {cmd.description && <span className="truncate text-xs text-muted-foreground">{cmd.description}</span>}
            </div>
        </CommandItem>
    );

    const resultRow = (result: Result, prefix: string) => (
        <CommandItem key={`${prefix}${result.type}-${result.id}`} value={`${prefix}${result.type}:${result.id}`} onSelect={() => openResult(result)} className="flex items-center gap-3 cursor-pointer rounded-lg px-2 py-2 aria-selected:bg-accent">
            <ResultIcon type={result.type} />
            <div className="flex min-w-0 flex-col">
                <span className="truncate text-sm font-medium leading-snug">{result.title}</span>
                {result.subtitle && <span className="truncate text-xs text-muted-foreground">{result.subtitle}</span>}
            </div>
        </CommandItem>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="top-[12%] translate-y-0 overflow-hidden p-0 gap-0 shadow-2xl w-[calc(100vw-2rem)] max-w-[640px] [&>button]:hidden focus:outline-none"
            >
                <DialogTitle className="sr-only">Command palette</DialogTitle>
                <DialogDescription className="sr-only">
                    Search issues, documents, boards and projects, or type “&gt;” for commands. Use the arrow keys to move and Enter to open.
                </DialogDescription>
                <Command
                    shouldFilter={false}
                    loop
                    vimBindings={false} // cmdk's Ctrl+K "up" would swallow the toggle shortcut
                    className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-[11px] [&_[cmdk-group-heading]]:font-semibold [&_[cmdk-group-heading]]:uppercase [&_[cmdk-group-heading]]:tracking-wide [&_[cmdk-group-heading]]:text-muted-foreground/70 [&_[cmdk-group]]:px-2"
                >
                    <div className="flex items-center gap-2 border-b px-4 py-3">
                        {status === 'loading' ? <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" /> : <Search className="size-4 shrink-0 text-muted-foreground/60" />}
                        <CommandPrimitive.Input
                            ref={inputRef}
                            aria-label="Search or type a command"
                            placeholder={commandMode ? 'Type a command…' : 'Search or type > for commands…'}
                            value={query}
                            onValueChange={setQuery}
                            className="flex-1 border-0 bg-transparent text-sm shadow-none outline-none ring-0 placeholder:text-muted-foreground/50 focus:outline-none focus:ring-0"
                        />
                        <kbd className="hidden h-5 select-none items-center rounded border border-border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground sm:inline-flex">ESC</kbd>
                        <button
                            type="button"
                            onClick={close}
                            className="ml-1 flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground/60 transition-colors hover:bg-muted hover:text-foreground"
                            aria-label="Close"
                        >
                            <X className="size-3.5" />
                        </button>
                    </div>

                    <CommandList className="max-h-[min(60vh,480px)] overflow-y-auto overflow-x-hidden py-2">
                        {emptyQuery && !commandMode && recent.length > 0 && (
                            <CommandGroup heading="Recent">
                                {recent.slice(0, 6).map((r) => resultRow(r, 'recent:'))}
                            </CommandGroup>
                        )}

                        {emptyQuery
                            ? commandGroups.map((group) => (
                                <CommandGroup key={group} heading={group}>
                                    {commands.filter((c) => c.group === group).map(commandRow)}
                                </CommandGroup>
                            ))
                            : visibleCommands.length > 0 && <CommandGroup heading="Commands">{visibleCommands.map(commandRow)}</CommandGroup>}

                        {!commandMode && !emptyQuery && status === 'done' && RESULT_GROUPS.map(({ key, heading }) =>
                            (results[key]?.length ?? 0) > 0 && (
                                <CommandGroup key={key} heading={heading}>
                                    {results[key]!.map((r) => resultRow(r, ''))}
                                </CommandGroup>
                            ),
                        )}

                        {!commandMode && status === 'loading' && resultCount === 0 && (
                            <div className="px-4 py-6 text-center text-sm text-muted-foreground" role="status">Searching…</div>
                        )}

                        {!commandMode && status === 'error' && (
                            <div className="flex flex-col items-center gap-2 px-4 py-6 text-center text-sm text-muted-foreground" role="alert">
                                <span className="flex items-center gap-1.5"><AlertCircle className="size-4" /> Search didn’t work. Check your connection.</span>
                                <button type="button" className="text-primary hover:underline" onClick={() => setRetry((n) => n + 1)}>Try again</button>
                            </div>
                        )}

                        {nothingFound && status !== 'error' && (
                            <div className="px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                                {!commandMode && searchText.length < MIN_QUERY_LENGTH
                                    ? 'Keep typing to search…'
                                    : <>Nothing found for “{searchText}”.{!commandMode && ' Type > to search commands.'}</>}
                            </div>
                        )}
                    </CommandList>

                    <div className="flex select-none items-center gap-4 border-t px-4 py-2 text-[11px] text-muted-foreground/70">
                        <span><kbd className="font-mono font-medium text-muted-foreground">↑↓</kbd> move</span>
                        <span><kbd className="font-mono font-medium text-muted-foreground">↵</kbd> open</span>
                        <span className="hidden sm:inline"><kbd className="font-mono font-medium text-muted-foreground">&gt;</kbd> commands</span>
                        <span className="ml-auto hidden sm:inline"><kbd className="font-mono font-medium text-muted-foreground">⌘K</kbd> toggle</span>
                    </div>
                </Command>
            </DialogContent>
        </Dialog>
    );
}
