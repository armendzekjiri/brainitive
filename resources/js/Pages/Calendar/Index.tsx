import AppLayout from '@/Layouts/AppLayout';
import { Head, router, Link } from '@inertiajs/react';
import { PageProps, CalendarEvent, CalendarConnectionRow } from '@/types';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    ChevronLeft, ChevronRight, Calendar, Kanban, Clock, Filter, Check, Repeat, Plus, Trash2, ExternalLink, Settings,
} from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { useState, useMemo, useEffect, useRef } from 'react';

type ProjectFilterOption = {
    id: number;
    name: string;
    color?: string | null;
    workspace?: { id: number; name: string } | null;
};

type AccessibleProject = {
    id: number;
    name: string;
    slug: string;
    color?: string | null;
    workspace: { id: number; name: string; slug: string } | null;
    boards: { id: number; name: string; slug: string; first_column_id: number | null }[];
    task_lists: { id: number; name: string; slug: string }[];
};

type Filters = {
    projects: number[];
    me_only: boolean;
    show_completed: boolean;
    sources: string[];
    connections: number[];
};

type QuickCreateState = { date: string; time: string | null } | null;

interface Props extends PageProps {
    events: CalendarEvent[];
    view: 'month' | 'week' | 'day';
    date: string;
    projectsForFilter: ProjectFilterOption[];
    accessibleProjects: AccessibleProject[];
    connections: CalendarConnectionRow[];
    filters: Filters;
}

const VIEW_LABELS = { month: 'Month', week: 'Week', day: 'Day' } as const;
const VIEWS: ('month' | 'week' | 'day')[] = ['month', 'week', 'day'];
const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function eventHref(e: CalendarEvent): string {
    if (e.type === 'board_item') return route('boards.items.show', e.href_params);
    if (e.type === 'task_list_item') return route('task-lists.show', e.href_params);
    return e.external_url || '#';
}

function timeToMinutes(time: string): number {
    const [h, m] = time.split(':').map(Number);
    return h * 60 + (m || 0);
}

function formatTime(time: string): string {
    // 24-hour format
    return time.substring(0, 5);
}

function minutesToHHMM(mins: number): string {
    const m = ((mins % 1440) + 1440) % 1440;
    const hh = String(Math.floor(m / 60)).padStart(2, '0');
    const mm = String(m % 60).padStart(2, '0');
    return `${hh}:${mm}`;
}

function daysBetween(a: Date, b: Date): number {
    return Math.round((b.getTime() - a.getTime()) / 86400000);
}

function isoDate(d: Date): string {
    // Use LOCAL date components, not toISOString() (which uses UTC and shifts the
    // date by ±1 in non-UTC timezones — that's why Thursday 28 was rendering as
    // Friday 29 for CET users).
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/**
 * Decide the chip's status: 'done', 'overdue', or 'normal'. Overdue = not
 * completed AND due_date in the past (or due_date===today AND due_time has passed).
 */
function eventStatus(event: CalendarEvent): 'done' | 'overdue' | 'normal' {
    if (event.is_completed) return 'done';
    const dueStr = event.due_date;
    if (!dueStr) return 'normal';
    const today = isoDate(new Date());
    if (dueStr < today) return 'overdue';
    if (dueStr === today && event.due_time) {
        const now = new Date();
        const nowMins = now.getHours() * 60 + now.getMinutes();
        const [h, m] = event.due_time.substring(0, 5).split(':').map(Number);
        const dueMins = h * 60 + (m || 0);
        if (dueMins < nowMins) return 'overdue';
    }
    return 'normal';
}

/** Status → colors. Returns base hex (background tint) + text color. */
function statusColor(event: CalendarEvent): { base: string; text: string } {
    const s = eventStatus(event);
    if (s === 'done') return { base: '#16A34A', text: '#16A34A' };       // green
    if (s === 'overdue') return { base: '#DC2626', text: '#DC2626' };    // red
    return { base: '#0E83DC', text: '#0E83DC' };                          // brand blue
}

function parseISO(s: string): Date {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
}

function navigateCalendar(view: string, date: string, dir: -1 | 0 | 1, filters: Filters) {
    const d = new Date(date);
    let target: string;
    if (dir === 0) {
        target = isoDate(new Date());
    } else {
        if (view === 'month') d.setMonth(d.getMonth() + dir);
        else if (view === 'week') d.setDate(d.getDate() + dir * 7);
        else d.setDate(d.getDate() + dir);
        target = isoDate(d);
    }
    router.get(route('calendar.index'), { view, date: target, ...filtersToParams(filters) }, { preserveState: true });
}

function filtersToParams(f: Filters): Record<string, any> {
    const out: Record<string, any> = {};
    if (f.projects?.length) out.projects = f.projects;
    if (f.me_only) out.me_only = 1;
    if (!f.show_completed) out.show_completed = 0;
    if (f.sources && f.sources.length < 3) out.sources = f.sources;
    if (f.connections?.length) out.connections = f.connections;
    return out;
}

function browserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

function handleDrop(e: React.DragEvent, targetDate: string, targetTime?: string) {
    e.preventDefault();
    const data = e.dataTransfer.getData('application/calendar-event');
    if (!data) return;
    const parsed = JSON.parse(data);
    if (parsed.is_recurring_instance) return; // can't reschedule a virtual occurrence
    const payload: Record<string, any> = {
        type: parsed.type,
        id: parsed.id,
        due_date: targetDate,
        timezone: browserTimezone(),
    };
    if (targetTime) payload.due_time = targetTime;
    if (parsed.connection_id) payload.connection_id = parsed.connection_id;
    router.patch(route('calendar.reschedule'), payload, { preserveScroll: true });
}

// ── Filter popover ───────────────────────────────────────────────────────
function FilterBar({
    projectsForFilter, connections, filters, view, date,
}: {
    projectsForFilter: ProjectFilterOption[];
    connections: CalendarConnectionRow[];
    filters: Filters;
    view: string;
    date: string;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const activeCount =
        (filters.projects?.length ? 1 : 0) +
        (filters.me_only ? 1 : 0) +
        (!filters.show_completed ? 1 : 0) +
        (filters.sources && filters.sources.length < 3 ? 1 : 0) +
        (filters.connections?.length ? 1 : 0);

    const apply = (next: Filters) => {
        router.get(route('calendar.index'), { view, date, ...filtersToParams(next) }, { preserveState: true });
    };

    const filteredProjects = useMemo(
        () => projectsForFilter.filter((p) => !search || p.name.toLowerCase().includes(search.toLowerCase())),
        [projectsForFilter, search],
    );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button className={cn(
                    'inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-xs font-medium border transition-colors',
                    activeCount > 0 ? 'bg-accent border-transparent text-accent-foreground' : 'bg-card border-border text-muted-foreground hover:bg-secondary',
                )}>
                    <Filter className="size-3" />
                    {activeCount ? `Filter (${activeCount})` : 'Filter'}
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-4 space-y-4" align="end">
                <div className="flex items-center justify-between">
                    <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Filters</p>
                    {activeCount > 0 && (
                        <button
                            type="button"
                            className="text-xs text-primary hover:underline"
                            onClick={() => apply({ projects: [], me_only: false, show_completed: true, sources: ['board', 'list', 'google'], connections: [] })}
                        >
                            Clear all
                        </button>
                    )}
                </div>

                {/* Toggles */}
                <div className="space-y-2">
                    <button
                        onClick={() => apply({ ...filters, me_only: !filters.me_only })}
                        className="flex items-center justify-between w-full text-left rounded-md px-2.5 py-1.5 text-xs hover:bg-muted"
                    >
                        <span>Assigned to me only</span>
                        {filters.me_only && <Check className="size-3.5 text-primary" />}
                    </button>
                    <button
                        onClick={() => apply({ ...filters, show_completed: !filters.show_completed })}
                        className="flex items-center justify-between w-full text-left rounded-md px-2.5 py-1.5 text-xs hover:bg-muted"
                    >
                        <span>Show completed</span>
                        {filters.show_completed && <Check className="size-3.5 text-primary" />}
                    </button>
                </div>

                {/* Sources */}
                <div className="space-y-1.5">
                    <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Source</p>
                    <div className="flex gap-1.5 flex-wrap">
                        {(['board', 'list', 'google'] as const).map((src) => {
                            const active = filters.sources?.includes(src);
                            const label = src === 'board' ? 'Board' : src === 'list' ? 'Lists' : 'Google';
                            return (
                                <button
                                    key={src}
                                    onClick={() => {
                                        const next = active
                                            ? filters.sources.filter((s) => s !== src)
                                            : [...(filters.sources || []), src];
                                        apply({ ...filters, sources: next.length === 0 ? ['board', 'list', 'google'] : next });
                                    }}
                                    className={cn(
                                        'flex-1 px-2.5 py-1.5 rounded-full text-xs border transition-colors',
                                        active ? 'border-primary bg-primary/10 text-primary font-semibold' : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    {label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Connected calendars (per-calendar toggle) */}
                {connections.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Connected calendars</p>
                        <div className="space-y-0.5 max-h-40 overflow-y-auto">
                            {connections.map((c) => {
                                const active = filters.connections?.length === 0 || filters.connections?.includes(c.id);
                                return (
                                    <button
                                        key={c.id}
                                        onClick={() => {
                                            const all = connections.map((x) => x.id);
                                            const current = filters.connections?.length ? filters.connections : all;
                                            const next = current.includes(c.id)
                                                ? current.filter((id) => id !== c.id)
                                                : [...current, c.id];
                                            apply({ ...filters, connections: next.length === all.length ? [] : next });
                                        }}
                                        className={cn(
                                            'flex items-center gap-2 w-full px-2.5 py-1.5 rounded-md text-xs transition-colors',
                                            active ? 'bg-primary/5' : 'hover:bg-muted opacity-50',
                                        )}
                                    >
                                        <span className="size-2 rounded-full shrink-0" style={{ background: c.external_calendar_color || '#4285F4' }} />
                                        <span className="truncate flex-1 text-left">{c.external_calendar_name}</span>
                                        <span className="text-[10px] text-muted-foreground truncate">{c.account_email}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* Projects */}
                {projectsForFilter.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Projects</p>
                        <Input placeholder="Search projects…" value={search} onChange={(e) => setSearch(e.target.value)} className="h-7 text-xs" />
                        <div className="max-h-40 overflow-y-auto space-y-0.5 pt-1">
                            {filteredProjects.map((p) => {
                                const active = filters.projects?.includes(p.id);
                                return (
                                    <button
                                        key={p.id}
                                        onClick={() => {
                                            const next = active
                                                ? (filters.projects || []).filter((id) => id !== p.id)
                                                : [...(filters.projects || []), p.id];
                                            apply({ ...filters, projects: next });
                                        }}
                                        className={cn(
                                            'flex items-center justify-between w-full px-2.5 py-1.5 rounded-md text-xs transition-colors',
                                            active ? 'bg-primary/10 text-primary font-semibold' : 'hover:bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <span className="flex items-center gap-2 min-w-0">
                                            <span className="size-2 rounded-full shrink-0" style={{ background: p.color || '#94a3b8' }} />
                                            <span className="truncate">{p.name}</span>
                                            {p.workspace && <span className="text-[10px] text-muted-foreground/70 truncate">· {p.workspace.name}</span>}
                                        </span>
                                        {active && <Check className="size-3 shrink-0" />}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}

// ── Event chip (shared) ──────────────────────────────────────────────────
function EventChip({
    event, compact = false, multiDay = false, fill = false, onOpenGoogle,
}: {
    event: CalendarEvent;
    compact?: boolean;
    multiDay?: boolean;
    /** When true (time-grid usage), the chip fills its parent's height. */
    fill?: boolean;
    onOpenGoogle?: (eventId: string, connectionId: number, kind: 'event' | 'task') => void;
}) {
    const { base: color } = statusColor(event);
    const timeLabel = event.start_time && event.due_time
        ? `${formatTime(event.start_time)}–${formatTime(event.due_time)}`
        : event.due_time
            ? formatTime(event.due_time)
            : event.start_time
                ? formatTime(event.start_time)
                : null;

    const isGoogle = event.type === 'google_event' || event.type === 'google_task';
    const googleKind: 'event' | 'task' = event.type === 'google_task' ? 'task' : 'event';

    const sharedDragProps = {
        'data-event': 'true' as const,
        draggable: !event.is_recurring_instance,
        onDragStart: (e: React.DragEvent) => {
            e.dataTransfer.setData('application/calendar-event', JSON.stringify({
                type: event.type,
                id: event.id,
                is_recurring_instance: event.is_recurring_instance,
                connection_id: event.connection_id ?? null,
            }));
            e.dataTransfer.effectAllowed = 'move';
        },
        className: cn(
            'group flex gap-1 rounded-md px-1.5 py-0.5 text-xs leading-snug transition-colors cursor-pointer overflow-hidden w-full text-left',
            'hover:shadow-sm border border-transparent hover:border-border',
            event.is_completed && 'opacity-50',
            multiDay && 'rounded-none first:rounded-l-md last:rounded-r-md',
            fill ? 'h-full items-start' : 'items-center',
        ),
        style: { backgroundColor: `${color}15` },
        title: `${event.title}${event.project ? ' — ' + event.project.name : ''}`,
    };

    const inner = (
        <>
            {!multiDay && <span className="w-[3px] self-stretch shrink-0 rounded-full" style={{ background: color }} />}
            {(event.type === 'google_event' || event.type === 'google_task') && (
                <span
                    className="size-3.5 rounded-sm grid place-items-center shrink-0 text-[8px] font-bold text-white"
                    style={{ background: event.color || '#4285F4' }}
                    title={event.source?.name
                        ? `${event.type === 'google_task' ? 'Google Tasks' : 'Google Calendar'}: ${event.source.name}`
                        : event.type === 'google_task' ? 'Google Tasks' : 'Google Calendar'}
                >
                    {event.type === 'google_task' ? '✓' : 'G'}
                </span>
            )}
            <span className={cn('min-w-0 flex-1 flex flex-col gap-0.5', !fill && 'flex-row items-center')}>
                {fill && timeLabel && <span className="text-[10px] text-muted-foreground font-mono tabular-nums">{timeLabel}</span>}
                <span className={cn('truncate font-medium flex items-center gap-1', event.is_completed && 'line-through')}>
                    {event.is_recurring_instance && <Repeat className="size-2.5 shrink-0 opacity-70" />}
                    {!compact && !fill && event.due_time && <span className="text-muted-foreground mr-1">{formatTime(event.due_time)}</span>}
                    {event.title}
                </span>
            </span>
            {!compact && event.assignee && <AssigneeAvatar user={event.assignee} size={16} className="shrink-0 mt-0.5" />}
        </>
    );

    if (isGoogle && onOpenGoogle) {
        return (
            <button
                type="button"
                onClick={(e) => { e.stopPropagation(); onOpenGoogle(String(event.id), event.connection_id!, googleKind); }}
                {...sharedDragProps}
            >
                {inner}
            </button>
        );
    }

    return (
        <Link href={eventHref(event)} {...sharedDragProps}>
            {inner}
        </Link>
    );
}

// ── Quick-create modal ───────────────────────────────────────────────────
function QuickCreateModal({
    state, onClose, accessibleProjects, connections,
}: {
    state: QuickCreateState;
    onClose: () => void;
    accessibleProjects: AccessibleProject[];
    connections: CalendarConnectionRow[];
}) {
    const open = !!state;

    // Auto-select first project (and its first board) on mount.
    const defaultProject = accessibleProjects[0];

    const [type, setType] = useState<'board' | 'list' | 'google'>('board');
    const [title, setTitle] = useState('');
    const [projectId, setProjectId] = useState<number | null>(defaultProject?.id ?? null);
    const [boardId, setBoardId] = useState<number | null>(defaultProject?.boards[0]?.id ?? null);
    const [taskListId, setTaskListId] = useState<number | null>(defaultProject?.task_lists[0]?.id ?? null);
    const calendarConns = useMemo(() => connections.filter((c) => c.kind === 'calendar'), [connections]);
    const taskConns = useMemo(() => connections.filter((c) => c.kind === 'tasks'), [connections]);
    const [googleKind, setGoogleKind] = useState<'event' | 'task'>('event');
    const [connectionId, setConnectionId] = useState<number | null>(calendarConns[0]?.id ?? null);
    const [dateInput, setDateInput] = useState('');
    const [timeInput, setTimeInput] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (!state) return;
        setTitle('');
        setDateInput(state.date);
        setTimeInput(state.time ?? '');
        setSubmitting(false);
        // Pick default project + child on open
        const firstProject = accessibleProjects[0];
        if (firstProject) {
            setProjectId(firstProject.id);
            setBoardId(firstProject.boards[0]?.id ?? null);
            setTaskListId(firstProject.task_lists[0]?.id ?? null);
            if (firstProject.boards.length === 0 && firstProject.task_lists.length > 0) setType('list');
            else setType('board');
        }
        // Default to event if any calendar connection exists; else task.
        if (calendarConns.length > 0) {
            setGoogleKind('event');
            setConnectionId(calendarConns[0].id);
        } else if (taskConns.length > 0) {
            setGoogleKind('task');
            setConnectionId(taskConns[0].id);
        } else {
            setConnectionId(null);
        }
    }, [state, accessibleProjects, calendarConns, taskConns]);

    const project = useMemo(() => accessibleProjects.find((p) => p.id === projectId) ?? null, [accessibleProjects, projectId]);

    const hasGoogleSource = (googleKind === 'task' ? taskConns.length : calendarConns.length) > 0;
    const canSubmit = title.trim() && dateInput && (
        (type === 'board' && project && boardId)
        || (type === 'list' && project && taskListId)
        || (type === 'google' && connectionId && hasGoogleSource)
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!canSubmit) return;
        setSubmitting(true);

        if (type === 'google') {
            const conn = connections.find((c) => c.id === connectionId);
            if (!conn) { setSubmitting(false); return; }
            const payload: Record<string, any> = { title: title.trim(), date: dateInput, timezone: browserTimezone() };
            if (timeInput) payload.time = timeInput;
            const routeName = googleKind === 'task'
                ? 'calendar.connections.tasks.create'
                : 'calendar.connections.events.create';
            router.post(
                route(routeName, conn.id),
                payload,
                {
                    preserveScroll: true,
                    onSuccess: () => { setSubmitting(false); onClose(); },
                    onError: () => setSubmitting(false),
                },
            );
            return;
        }

        if (!project) { setSubmitting(false); return; }

        if (type === 'board') {
            const board = project.boards.find((b) => b.id === boardId);
            if (!board || !board.first_column_id) {
                setSubmitting(false);
                return;
            }
            const payload: Record<string, any> = {
                title: title.trim(),
                column_id: board.first_column_id,
                due_date: dateInput,
            };
            if (timeInput) payload.due_time = timeInput;
            router.post(
                route('boards.items.store', [project.workspace!.slug, project.slug, board.slug]),
                payload,
                {
                    preserveScroll: true,
                    onSuccess: () => { setSubmitting(false); onClose(); },
                    onError: () => setSubmitting(false),
                },
            );
        } else {
            const list = project.task_lists.find((l) => l.id === taskListId);
            if (!list) {
                setSubmitting(false);
                return;
            }
            const payload: Record<string, any> = {
                title: title.trim(),
                due_date: dateInput,
            };
            if (timeInput) payload.due_time = timeInput;
            router.post(
                route('task-lists.items.store', [project.workspace!.slug, project.slug, list.slug]),
                payload,
                {
                    preserveScroll: true,
                    onSuccess: () => { setSubmitting(false); onClose(); },
                    onError: () => setSubmitting(false),
                },
            );
        }
    };

    if (!state) return null;

    const hasBoards = project ? project.boards.length > 0 : false;
    const hasLists = project ? project.task_lists.length > 0 : false;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Quick create</DialogTitle>
                    <DialogDescription>
                        Add a new {type === 'board' ? 'board item' : 'list item'} on{' '}
                        {new Date(state.date).toLocaleDateString('en', { weekday: 'short', month: 'short', day: 'numeric' })}
                        {state.time ? ` at ${state.time}` : ''}.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    {/* Type chooser */}
                    <div className="inline-flex w-full p-[3px] bg-secondary border border-border rounded-md gap-[1px]">
                        <button
                            type="button"
                            onClick={() => setType('board')}
                            disabled={!hasBoards}
                            className={cn(
                                'flex-1 h-8 px-3 text-xs font-semibold rounded-[5px] transition-colors',
                                type === 'board' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                !hasBoards && 'opacity-40 cursor-not-allowed',
                            )}
                        >
                            Board
                        </button>
                        <button
                            type="button"
                            onClick={() => setType('list')}
                            disabled={!hasLists}
                            className={cn(
                                'flex-1 h-8 px-3 text-xs font-semibold rounded-[5px] transition-colors',
                                type === 'list' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                !hasLists && 'opacity-40 cursor-not-allowed',
                            )}
                        >
                            List
                        </button>
                        <button
                            type="button"
                            onClick={() => setType('google')}
                            disabled={connections.length === 0}
                            className={cn(
                                'flex-1 h-8 px-3 text-xs font-semibold rounded-[5px] transition-colors',
                                type === 'google' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                connections.length === 0 && 'opacity-40 cursor-not-allowed',
                            )}
                        >
                            Google
                        </button>
                    </div>

                    {/* Title */}
                    <div className="space-y-1.5">
                        <Label htmlFor="qc-title">Title</Label>
                        <Input
                            id="qc-title"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder="What needs doing?"
                            autoFocus
                        />
                    </div>

                    {/* Google: event vs task sub-toggle, then calendar/list picker */}
                    {type === 'google' && (
                        <>
                            <div className="inline-flex w-full p-[3px] bg-secondary border border-border rounded-md gap-[1px]">
                                <button
                                    type="button"
                                    onClick={() => { setGoogleKind('event'); setConnectionId(calendarConns[0]?.id ?? null); }}
                                    disabled={calendarConns.length === 0}
                                    className={cn(
                                        'flex-1 h-8 px-3 text-xs font-semibold rounded-[5px] transition-colors',
                                        googleKind === 'event' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                        calendarConns.length === 0 && 'opacity-40 cursor-not-allowed',
                                    )}
                                >
                                    Event
                                </button>
                                <button
                                    type="button"
                                    onClick={() => { setGoogleKind('task'); setConnectionId(taskConns[0]?.id ?? null); }}
                                    disabled={taskConns.length === 0}
                                    className={cn(
                                        'flex-1 h-8 px-3 text-xs font-semibold rounded-[5px] transition-colors',
                                        googleKind === 'task' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                        taskConns.length === 0 && 'opacity-40 cursor-not-allowed',
                                    )}
                                >
                                    Task
                                </button>
                            </div>
                            <div className="space-y-1.5">
                                <Label>{googleKind === 'task' ? 'Task list' : 'Calendar'}</Label>
                                <Select value={connectionId ? String(connectionId) : ''} onValueChange={(v) => setConnectionId(parseInt(v, 10))}>
                                    <SelectTrigger><SelectValue placeholder={`Select ${googleKind === 'task' ? 'list' : 'calendar'}…`} /></SelectTrigger>
                                    <SelectContent>
                                        {(googleKind === 'task' ? taskConns : calendarConns).map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                <span className="flex items-center gap-2">
                                                    <span className="size-2 rounded-full" style={{ background: c.external_calendar_color || '#4285F4' }} />
                                                    {c.external_calendar_name}
                                                    <span className="text-[10px] text-muted-foreground">· {c.account_email}</span>
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </>
                    )}

                    {/* Project (Brainitive only) */}
                    {type !== 'google' && (
                    <>
                    <div className="space-y-1.5">
                        <Label>Project</Label>
                        <Select
                            value={projectId ? String(projectId) : ''}
                            onValueChange={(v) => {
                                const id = parseInt(v, 10);
                                setProjectId(id);
                                const p = accessibleProjects.find((x) => x.id === id);
                                setBoardId(p?.boards[0]?.id ?? null);
                                setTaskListId(p?.task_lists[0]?.id ?? null);
                            }}
                        >
                            <SelectTrigger><SelectValue placeholder="Select project…" /></SelectTrigger>
                            <SelectContent>
                                {accessibleProjects.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        <span className="flex items-center gap-2">
                                            <span className="size-2 rounded-full" style={{ background: p.color || '#94a3b8' }} />
                                            {p.name}
                                            {p.workspace && <span className="text-[10px] text-muted-foreground">· {p.workspace.name}</span>}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Board OR List picker */}
                    {type === 'board' && project && (
                        <div className="space-y-1.5">
                            <Label>Board</Label>
                            <Select value={boardId ? String(boardId) : ''} onValueChange={(v) => setBoardId(parseInt(v, 10))}>
                                <SelectTrigger><SelectValue placeholder="Select board…" /></SelectTrigger>
                                <SelectContent>
                                    {project.boards.map((b) => (
                                        <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {project.boards.length === 0 && (
                                <p className="text-xs text-muted-foreground">This project has no boards yet.</p>
                            )}
                        </div>
                    )}
                    {type === 'list' && project && (
                        <div className="space-y-1.5">
                            <Label>List</Label>
                            <Select value={taskListId ? String(taskListId) : ''} onValueChange={(v) => setTaskListId(parseInt(v, 10))}>
                                <SelectTrigger><SelectValue placeholder="Select list…" /></SelectTrigger>
                                <SelectContent>
                                    {project.task_lists.map((l) => (
                                        <SelectItem key={l.id} value={String(l.id)}>{l.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {project.task_lists.length === 0 && (
                                <p className="text-xs text-muted-foreground">This project has no lists yet.</p>
                            )}
                        </div>
                    )}
                    </>
                    )}

                    {/* Date + time */}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="qc-date">Date</Label>
                            <Input id="qc-date" type="date" value={dateInput} onChange={(e) => setDateInput(e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="qc-time">Time</Label>
                            <Input id="qc-time" type="time" value={timeInput} onChange={(e) => setTimeInput(e.target.value)} placeholder="(none)" />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Cancel</Button>
                        <Button type="submit" disabled={!canSubmit || submitting}>{submitting ? 'Creating…' : 'Create'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ── Multi-day bar (month view) ───────────────────────────────────────────
function MultiDayBar({ event, span }: { event: CalendarEvent; span: number }) {
    const { base: color } = statusColor(event);
    return (
        <Link
            href={eventHref(event)}
            data-event="true"
            className={cn(
                'absolute left-0.5 right-0.5 h-[18px] rounded-md px-1.5 text-[11px] font-medium leading-[18px] truncate transition-shadow',
                'hover:shadow-sm border border-transparent hover:border-border cursor-pointer',
                event.is_completed && 'opacity-50',
            )}
            style={{ backgroundColor: `${color}25`, color, width: `calc(${span * 100}% - 4px)` }}
            title={event.title}
        >
            <span className={cn('truncate inline-flex items-center gap-1', event.is_completed && 'line-through')}>
                <span className="size-1.5 rounded-full shrink-0" style={{ background: color }} />
                {event.title}
            </span>
        </Link>
    );
}

// ── "Now" line ───────────────────────────────────────────────────────────
function useNow(intervalMs = 60_000) {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const t = setInterval(() => setNow(new Date()), intervalMs);
        return () => clearInterval(t);
    }, [intervalMs]);
    return now;
}

// ── Resizable timed event ───────────────────────────────────────────────
function TimedEvent({
    event, top, height, hourHeight, snapMinutes, dateIso, laneIdx, laneCount, onOpenGoogle,
}: {
    event: CalendarEvent;
    top: number;
    height: number;
    hourHeight: number;
    snapMinutes: number;
    dateIso: string;
    /** Horizontal position within an overlap group, 0-based. */
    laneIdx: number;
    /** Total number of side-by-side lanes for this overlap group. */
    laneCount: number;
    onOpenGoogle?: (eventId: string, connectionId: number, kind: 'event' | 'task') => void;
}) {
    const [drag, setDrag] = useState<{ top: number; height: number } | null>(null);
    const resizing = drag !== null;

    /** Common drag-resize handler. `edge` = which edge is being dragged. */
    const startResize = (e: React.MouseEvent, edge: 'top' | 'bottom') => {
        if (event.is_recurring_instance) return;
        e.preventDefault();
        e.stopPropagation();
        const startY = e.clientY;
        const startTop = top;
        const startHeight = height;
        const pxPerMin = hourHeight / 60;
        const minHeight = snapMinutes * pxPerMin;

        let liveTop = startTop;
        let liveHeight = startHeight;

        const onMove = (ev: MouseEvent) => {
            const delta = ev.clientY - startY;
            if (edge === 'bottom') {
                const raw = Math.max(minHeight, startHeight + delta);
                const mins = Math.round(raw / pxPerMin / snapMinutes) * snapMinutes;
                liveHeight = mins * pxPerMin;
            } else {
                // Top edge: the top moves with the cursor; bottom (= startTop+startHeight) stays.
                const bottom = startTop + startHeight;
                const rawTop = Math.min(bottom - minHeight, Math.max(0, startTop + delta));
                const minsTop = Math.round(rawTop / pxPerMin / snapMinutes) * snapMinutes;
                liveTop = Math.max(0, minsTop * pxPerMin);
                liveHeight = bottom - liveTop;
            }
            setDrag({ top: liveTop, height: liveHeight });
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            setDrag(null);

            const startMins = Math.round(liveTop / pxPerMin);
            const durationMins = Math.round(liveHeight / pxPerMin);
            const endMins = Math.min(startMins + durationMins, 1440 - 1);
            const payload: Record<string, any> = {
                type: event.type,
                id: event.id,
                due_date: dateIso,
                start_date: dateIso,
                start_time: minutesToHHMM(startMins),
                due_time: minutesToHHMM(endMins),
                timezone: browserTimezone(),
            };
            if (event.connection_id) payload.connection_id = event.connection_id;
            router.patch(route('calendar.reschedule'), payload, { preserveScroll: true });
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    };

    const displayTop = resizing ? drag!.top : top;
    const displayHeight = resizing ? drag!.height : height;

    // Horizontal lane layout: each lane gets equal width, slight gap between.
    const widthPct = 100 / laneCount;
    const leftPct = widthPct * laneIdx;

    return (
        <div
            data-event="true"
            className="absolute z-10 group/event"
            style={{
                top: displayTop,
                height: displayHeight,
                left: `calc(${leftPct}% + 2px)`,
                width: `calc(${widthPct}% - 4px)`,
            }}
        >
            {/* Top resize handle */}
            {!event.is_recurring_instance && (
                <div
                    onMouseDown={(e) => startResize(e, 'top')}
                    className={cn(
                        'absolute inset-x-0 top-0 h-1.5 cursor-row-resize z-10',
                        'opacity-0 group-hover/event:opacity-100 transition-opacity',
                        'bg-primary/30 hover:bg-primary/60',
                        resizing && 'opacity-100 bg-primary/70',
                    )}
                    title="Drag to start earlier"
                />
            )}
            <EventChip event={event} fill onOpenGoogle={onOpenGoogle} />
            {/* Bottom resize handle */}
            {!event.is_recurring_instance && (
                <div
                    onMouseDown={(e) => startResize(e, 'bottom')}
                    className={cn(
                        'absolute inset-x-0 bottom-0 h-1.5 cursor-row-resize z-10',
                        'opacity-0 group-hover/event:opacity-100 transition-opacity',
                        'bg-primary/30 hover:bg-primary/60',
                        resizing && 'opacity-100 bg-primary/70',
                    )}
                    title="Drag to extend"
                />
            )}
        </div>
    );
}

/**
 * Greedy lane assignment for overlapping events. Returns a parallel array of
 * { laneIdx, laneCount } where laneCount is the number of simultaneous lanes
 * active during this event's time range.
 */
function layoutLanes(items: { startMins: number; endMins: number }[]): { laneIdx: number; laneCount: number }[] {
    if (items.length === 0) return [];
    const sorted = items.map((it, originalIdx) => ({ ...it, originalIdx })).sort((a, b) => a.startMins - b.startMins);
    const lanes: number[] = []; // lanes[i] = endMins of the event currently in lane i
    const placement = new Array(items.length).fill(0).map(() => ({ laneIdx: 0, laneCount: 1 }));

    sorted.forEach((it) => {
        let laneIdx = lanes.findIndex((endMins) => endMins <= it.startMins);
        if (laneIdx === -1) {
            laneIdx = lanes.length;
            lanes.push(it.endMins);
        } else {
            lanes[laneIdx] = it.endMins;
        }
        placement[it.originalIdx].laneIdx = laneIdx;
    });

    // Compute laneCount per event = max lanes used during ANY overlapping event's time
    sorted.forEach((a) => {
        let count = 1;
        sorted.forEach((b) => {
            if (a.originalIdx === b.originalIdx) return;
            const overlaps = b.startMins < a.endMins && b.endMins > a.startMins;
            if (overlaps) {
                count = Math.max(count, placement[b.originalIdx].laneIdx + 1);
            }
        });
        count = Math.max(count, placement[a.originalIdx].laneIdx + 1);
        placement[a.originalIdx].laneCount = count;
    });

    return placement;
}

// ── Month view ───────────────────────────────────────────────────────────
function MonthView({ events, date, onQuickCreate, onOpenGoogle }: { events: CalendarEvent[]; date: string; onQuickCreate: (s: NonNullable<QuickCreateState>) => void; onOpenGoogle: (eventId: string, connectionId: number, kind: "event" | "task") => void }) {
    const d = parseISO(date);
    const year = d.getFullYear();
    const month = d.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startOffset = (firstDay.getDay() + 6) % 7;
    const totalCells = startOffset + lastDay.getDate();
    const rows = Math.ceil(totalCells / 7);
    const today = isoDate(new Date());

    // Separate single-day from multi-day
    const singleDayByDate = new Map<string, CalendarEvent[]>();
    const multiDayEvents: { event: CalendarEvent; start: Date; end: Date }[] = [];
    events.forEach((ev) => {
        if (ev.start_date && ev.due_date && ev.start_date !== ev.due_date) {
            multiDayEvents.push({ event: ev, start: parseISO(ev.start_date), end: parseISO(ev.due_date) });
        } else {
            const key = ev.due_date || ev.start_date;
            if (!key) return;
            if (!singleDayByDate.has(key)) singleDayByDate.set(key, []);
            singleDayByDate.get(key)!.push(ev);
        }
    });

    const cells: { date: Date; iso: string; inMonth: boolean }[] = [];
    for (let i = 0; i < rows * 7; i++) {
        const cellDate = new Date(year, month, 1 - startOffset + i);
        cells.push({ date: cellDate, iso: isoDate(cellDate), inMonth: cellDate.getMonth() === month });
    }

    return (
        <div className="flex-1 overflow-auto">
            <div className="grid grid-cols-7 border-b border-border sticky top-0 bg-background z-10">
                {DAY_NAMES.map((n) => (
                    <div key={n} className="px-2 py-1.5 text-[11px] font-semibold text-muted-foreground text-center uppercase tracking-wide">{n}</div>
                ))}
            </div>
            <div className="grid grid-cols-7 auto-rows-[minmax(110px,1fr)]">
                {cells.map(({ iso, date: cellDate, inMonth }, cellIdx) => {
                    const dayEvents = singleDayByDate.get(iso) || [];
                    const isToday = iso === today;
                    const rowStart = cellIdx % 7 === 0;

                    // Multi-day bars that START on this cell
                    const startingBars = rowStart
                        ? multiDayEvents.filter((m) => {
                            const cellStart = cells[cellIdx].date;
                            const rowEnd = cells[Math.min(cellIdx + 6, cells.length - 1)].date;
                            return m.end >= cellStart && m.start <= rowEnd && m.start < cellStart;
                        })
                        : [];
                    const startingHere = multiDayEvents.filter((m) => isoDate(m.start) === iso);

                    return (
                        <div
                            key={iso}
                            className={cn(
                                'border-b border-r border-border p-1 min-h-[110px] transition-colors relative cursor-pointer hover:bg-muted/20',
                                !inMonth && 'bg-muted/30 text-muted-foreground',
                            )}
                            onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }}
                            onDrop={(e) => handleDrop(e, iso)}
                            onClick={(e) => {
                                // Skip if click landed on an event chip
                                if ((e.target as Element).closest('[data-event]')) return;
                                onQuickCreate({ date: iso, time: null });
                            }}
                        >
                            <div className={cn(
                                'text-xs font-medium mb-0.5 w-6 h-6 flex items-center justify-center rounded-full',
                                isToday && 'bg-primary text-primary-foreground',
                            )}>
                                {cellDate.getDate()}
                            </div>

                            {/* Multi-day bars (only render at start of row or start of event) */}
                            <div className="absolute left-0 right-0 top-7 space-y-0.5 pl-0.5 pr-0.5">
                                {[...startingBars, ...startingHere].slice(0, 3).map(({ event, start, end }) => {
                                    const startCell = isoDate(start) === iso ? cellIdx : cellIdx;
                                    const dayOfWeek = startCell % 7;
                                    const maxSpan = 7 - dayOfWeek;
                                    const span = Math.min(daysBetween(start, end) + 1 - (isoDate(start) === iso ? 0 : daysBetween(start, cellDate)), maxSpan);
                                    return <MultiDayBar key={`${event.type}-${event.id}-${iso}`} event={event} span={span} />;
                                })}
                            </div>

                            {/* Single-day events */}
                            <div className="space-y-0.5 mt-[26px]">
                                {dayEvents.slice(0, 3).map((ev) => <EventChip key={`${ev.type}-${ev.id}`} event={ev} compact onOpenGoogle={onOpenGoogle} />)}
                                {dayEvents.length > 3 && <div className="text-[10px] text-muted-foreground px-1.5">+{dayEvents.length - 3} more</div>}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// ── Time grid (shared by week + day) ─────────────────────────────────────
function TimeGrid({
    columns,
    allDayByCol,
    timedByCol,
    dateKey,
    onQuickCreate,
    onOpenGoogle,
}: {
    columns: { key: string; label: string; iso: string }[];
    allDayByCol: Map<string, CalendarEvent[]>;
    timedByCol: Map<string, CalendarEvent[]>;
    dateKey: string;
    onQuickCreate: (s: NonNullable<QuickCreateState>) => void;
    onOpenGoogle: (eventId: string, connectionId: number, kind: 'event' | 'task') => void;
}) {
    const today = isoDate(new Date());
    const now = useNow();
    const startHour = 0;
    const endHour = 24;
    const hours = Array.from({ length: endHour - startHour }, (_, i) => startHour + i);
    const HOUR_HEIGHT = 60;
    const SNAP_MINUTES = 15;

    const scrollRef = useRef<HTMLDivElement>(null);

    // Auto-scroll so the current time is centered when the visible date changes.
    // The columns array always includes today when the user is on this view, or the
    // selected day for day-view — we scroll to "now" regardless so users land near
    // where they want to be.
    useEffect(() => {
        const el = scrollRef.current;
        if (!el) return;
        const nowMins = now.getHours() * 60 + now.getMinutes();
        const nowTop = (nowMins / 60) * HOUR_HEIGHT;
        const target = Math.max(0, nowTop - el.clientHeight / 2);
        el.scrollTo({ top: target, behavior: 'auto' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dateKey]);

    // Now-line position
    const nowMins = now.getHours() * 60 + now.getMinutes();
    const nowTop = ((nowMins / 60) - startHour) * HOUR_HEIGHT;

    return (
        <div className="flex-1 flex flex-col overflow-hidden">
            {/* All-day row */}
            <div className="flex border-b border-border shrink-0">
                <div className="w-14 shrink-0 px-2 py-1 text-[10px] text-muted-foreground text-right">All day</div>
                <div className="flex-1 grid" style={{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }}>
                    {columns.map((col) => {
                        const items = allDayByCol.get(col.key) || [];
                        return (
                            <div
                                key={col.key}
                                className="border-l border-border p-1 min-h-[32px] space-y-0.5 cursor-pointer hover:bg-muted/30"
                                onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }}
                                onDrop={(e) => handleDrop(e, col.iso)}
                                onClick={(e) => {
                                    if ((e.target as Element).closest('[data-event]')) return;
                                    onQuickCreate({ date: col.iso, time: null });
                                }}
                            >
                                {items.map((ev) => <EventChip key={`${ev.type}-${ev.id}`} event={ev} compact onOpenGoogle={onOpenGoogle} />)}
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Column headers */}
            <div className="flex border-b border-border shrink-0 sticky top-0 bg-background z-10">
                <div className="w-14 shrink-0" />
                <div className="flex-1 grid" style={{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }}>
                    {columns.map((col) => (
                        <div key={col.key} className={cn('border-l border-border px-2 py-1.5 text-center', col.iso === today && 'bg-primary/5')}>
                            <div className="text-[11px] font-semibold text-muted-foreground uppercase">{col.label}</div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Time slots */}
            <div ref={scrollRef} className="flex-1 overflow-auto">
                <div className="flex relative" style={{ height: (endHour - startHour) * HOUR_HEIGHT }}>
                    {/* Time labels (24h format) */}
                    <div className="w-14 shrink-0 relative">
                        {hours.map((h) => (
                            <div
                                key={h}
                                className="absolute right-2 text-[10px] font-mono tabular-nums text-muted-foreground -translate-y-1/2"
                                style={{ top: (h - startHour) * HOUR_HEIGHT }}
                            >
                                {String(h).padStart(2, '0')}:00
                            </div>
                        ))}
                    </div>

                    {/* Columns */}
                    <div className="flex-1 grid relative" style={{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }}>
                        {columns.map((col) => {
                            const items = timedByCol.get(col.key) || [];
                            const isTodayCol = col.iso === today;
                            return (
                                <div key={col.key} className={cn('border-l border-border relative', isTodayCol && 'bg-primary/[0.02]')}>
                                    {hours.map((h) => (
                                        <div
                                            key={h}
                                            className="absolute inset-x-0 border-t border-border/60 cursor-pointer"
                                            style={{ top: (h - startHour) * HOUR_HEIGHT, height: HOUR_HEIGHT }}
                                            onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }}
                                            onDrop={(e) => {
                                                const rect = e.currentTarget.getBoundingClientRect();
                                                const y = e.clientY - rect.top;
                                                const minsWithinHour = Math.round((y / HOUR_HEIGHT) * 60 / SNAP_MINUTES) * SNAP_MINUTES;
                                                const totalMins = h * 60 + Math.min(minsWithinHour, 59);
                                                const hh = String(Math.floor(totalMins / 60) % 24).padStart(2, '0');
                                                const mm = String(totalMins % 60).padStart(2, '0');
                                                handleDrop(e, col.iso, `${hh}:${mm}`);
                                            }}
                                            onClick={(e) => {
                                                if ((e.target as Element).closest('[data-event]')) return;
                                                const rect = e.currentTarget.getBoundingClientRect();
                                                const y = e.clientY - rect.top;
                                                const minsWithinHour = Math.round((y / HOUR_HEIGHT) * 60 / SNAP_MINUTES) * SNAP_MINUTES;
                                                const totalMins = h * 60 + Math.min(minsWithinHour, 59);
                                                const hh = String(Math.floor(totalMins / 60) % 24).padStart(2, '0');
                                                const mm = String(totalMins % 60).padStart(2, '0');
                                                onQuickCreate({ date: col.iso, time: `${hh}:${mm}` });
                                            }}
                                        >
                                            {/* 15-min subdivision lines */}
                                            <div className="h-[15px]" />
                                            <div className="h-[15px] border-t border-border/20" />
                                            <div className="h-[15px] border-t border-border/30" />
                                            <div className="h-[15px] border-t border-border/20" />
                                        </div>
                                    ))}

                                    {/* Now line — only on today's column */}
                                    {isTodayCol && (
                                        <div
                                            className="absolute inset-x-0 z-20 pointer-events-none"
                                            style={{ top: nowTop }}
                                        >
                                            <div className="absolute -left-1 -translate-y-1/2 size-2 rounded-full bg-red-500" />
                                            <div className="h-px bg-red-500" />
                                        </div>
                                    )}

                                    {/* Timed events — compute layout with side-by-side lanes for overlaps */}
                                    {(() => {
                                        const computed = items.map((ev) => {
                                            const startStr = ev.start_time || ev.due_time || '08:00';
                                            const startMins = timeToMinutes(startStr);
                                            const endMins = ev.due_time && ev.start_time
                                                ? timeToMinutes(ev.due_time)
                                                : startMins + 30;
                                            const top = ((startMins / 60) - startHour) * HOUR_HEIGHT;
                                            const height = Math.max(((endMins - startMins) / 60) * HOUR_HEIGHT, 24);
                                            return { ev, top, height, startMins, endMins };
                                        });
                                        const lanes = layoutLanes(computed.map((c) => ({ startMins: c.startMins, endMins: c.endMins })));
                                        return computed.map((c, i) => (
                                            <TimedEvent
                                                key={`${c.ev.type}-${c.ev.id}-${c.ev.due_date}`}
                                                event={c.ev}
                                                top={c.top}
                                                height={c.height}
                                                hourHeight={HOUR_HEIGHT}
                                                snapMinutes={SNAP_MINUTES}
                                                dateIso={col.iso}
                                                laneIdx={lanes[i].laneIdx}
                                                laneCount={lanes[i].laneCount}
                                                onOpenGoogle={onOpenGoogle}
                                            />
                                        ));
                                    })()}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Week view ────────────────────────────────────────────────────────────
function WeekView({ events, date, onQuickCreate, onOpenGoogle }: { events: CalendarEvent[]; date: string; onQuickCreate: (s: NonNullable<QuickCreateState>) => void; onOpenGoogle: (eventId: string, connectionId: number, kind: "event" | "task") => void }) {
    const d = parseISO(date);
    const dayOfWeek = (d.getDay() + 6) % 7;
    const monday = new Date(d);
    monday.setDate(d.getDate() - dayOfWeek);

    const columns = Array.from({ length: 7 }, (_, i) => {
        const day = new Date(monday);
        day.setDate(monday.getDate() + i);
        const iso = isoDate(day);
        return { key: iso, label: `${DAY_NAMES[i]} ${day.getDate()}`, iso };
    });

    const allDayByCol = new Map<string, CalendarEvent[]>();
    const timedByCol = new Map<string, CalendarEvent[]>();
    columns.forEach((c) => { allDayByCol.set(c.key, []); timedByCol.set(c.key, []); });

    events.forEach((ev) => {
        const key = ev.due_date || ev.start_date;
        if (!key || !allDayByCol.has(key)) return;
        if (ev.due_time || ev.start_time) timedByCol.get(key)!.push(ev);
        else allDayByCol.get(key)!.push(ev);
    });

    return <TimeGrid columns={columns} allDayByCol={allDayByCol} timedByCol={timedByCol} dateKey={date} onQuickCreate={onQuickCreate} onOpenGoogle={onOpenGoogle} />;
}

// ── Day view ─────────────────────────────────────────────────────────────
function DayView({ events, date, onQuickCreate, onOpenGoogle }: { events: CalendarEvent[]; date: string; onQuickCreate: (s: NonNullable<QuickCreateState>) => void; onOpenGoogle: (eventId: string, connectionId: number, kind: "event" | "task") => void }) {
    const d = parseISO(date);
    const iso = isoDate(d);
    const dayName = d.toLocaleDateString('en', { weekday: 'long', month: 'long', day: 'numeric' });

    const columns = [{ key: iso, label: dayName, iso }];
    const allDayByCol = new Map<string, CalendarEvent[]>();
    const timedByCol = new Map<string, CalendarEvent[]>();
    allDayByCol.set(iso, []);
    timedByCol.set(iso, []);

    events.forEach((ev) => {
        const key = ev.due_date || ev.start_date;
        if (key !== iso) return;
        if (ev.due_time || ev.start_time) timedByCol.get(iso)!.push(ev);
        else allDayByCol.get(iso)!.push(ev);
    });

    return <TimeGrid columns={columns} allDayByCol={allDayByCol} timedByCol={timedByCol} dateKey={date} onQuickCreate={onQuickCreate} onOpenGoogle={onOpenGoogle} />;
}

// ── Google event editor dialog ───────────────────────────────────────────
type GoogleEventDetails = {
    id: string;
    summary?: string;
    description?: string;
    location?: string;
    start?: { date?: string; dateTime?: string; timeZone?: string };
    end?: { date?: string; dateTime?: string; timeZone?: string };
    htmlLink?: string;
};

function GoogleEventDialog({
    eventId, connectionId, onClose,
}: {
    eventId: string | null;
    connectionId: number | null;
    onClose: () => void;
}) {
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [details, setDetails] = useState<GoogleEventDetails | null>(null);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [location, setLocation] = useState('');
    const [allDay, setAllDay] = useState(false);
    const [startDate, setStartDate] = useState('');
    const [startTime, setStartTime] = useState('');
    const [endDate, setEndDate] = useState('');
    const [endTime, setEndTime] = useState('');

    const open = !!eventId && !!connectionId;

    useEffect(() => {
        if (!open || !eventId || !connectionId) return;
        setLoading(true);
        fetch(route('calendar.connections.events.show', [connectionId, eventId]), { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
                const ev: GoogleEventDetails = data.event;
                setDetails(ev);
                setTitle(ev?.summary || '');
                setDescription(ev?.description || '');
                setLocation(ev?.location || '');
                if (ev?.start?.date) {
                    setAllDay(true);
                    setStartDate(ev.start.date);
                    setStartTime('');
                    // Google all-day end is exclusive — subtract a day for display
                    const e = ev.end?.date ? new Date(ev.end.date) : new Date(ev.start.date);
                    e.setDate(e.getDate() - 1);
                    setEndDate(e.toISOString().split('T')[0]);
                    setEndTime('');
                } else if (ev?.start?.dateTime) {
                    setAllDay(false);
                    setStartDate(ev.start.dateTime.substring(0, 10));
                    setStartTime(ev.start.dateTime.substring(11, 16));
                    setEndDate(ev.end?.dateTime?.substring(0, 10) || ev.start.dateTime.substring(0, 10));
                    setEndTime(ev.end?.dateTime?.substring(11, 16) || '');
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [eventId, connectionId, open]);

    if (!open) return null;

    const save = () => {
        if (!eventId || !connectionId) return;
        setSaving(true);
        const payload: Record<string, any> = {
            title,
            description,
            location,
            start_date: startDate,
            end_date: endDate,
            timezone: browserTimezone(),
        };
        if (!allDay) {
            payload.start_time = startTime || null;
            payload.end_time = endTime || null;
        } else {
            payload.start_time = null;
            payload.end_time = null;
        }
        router.patch(route('calendar.connections.events.update', [connectionId, eventId]), payload, {
            preserveScroll: true,
            onSuccess: () => { setSaving(false); onClose(); },
            onError: () => setSaving(false),
        });
    };

    const remove = () => {
        if (!eventId || !connectionId) return;
        if (!window.confirm('Delete this event from Google Calendar?')) return;
        router.delete(route('calendar.connections.events.destroy', [connectionId, eventId]), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Google event</DialogTitle>
                    <DialogDescription>Changes are saved directly to Google Calendar.</DialogDescription>
                </DialogHeader>
                {loading ? (
                    <p className="text-sm text-muted-foreground py-8 text-center">Loading…</p>
                ) : (
                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="ge-title">Title</Label>
                            <Input id="ge-title" value={title} onChange={(e) => setTitle(e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="ge-location">Location</Label>
                            <Input id="ge-location" value={location} onChange={(e) => setLocation(e.target.value)} placeholder="Address, room, video link…" />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="ge-desc">Description</Label>
                            <textarea
                                id="ge-desc"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                rows={4}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                placeholder="Notes, agenda, links…"
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={allDay} onChange={(e) => setAllDay(e.target.checked)} />
                            All day
                        </label>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="ge-sd">Start date</Label>
                                <Input id="ge-sd" type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
                            </div>
                            {!allDay && (
                                <div className="space-y-1.5">
                                    <Label htmlFor="ge-st">Start time</Label>
                                    <Input id="ge-st" type="time" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
                                </div>
                            )}
                            <div className="space-y-1.5">
                                <Label htmlFor="ge-ed">End date</Label>
                                <Input id="ge-ed" type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                            </div>
                            {!allDay && (
                                <div className="space-y-1.5">
                                    <Label htmlFor="ge-et">End time</Label>
                                    <Input id="ge-et" type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
                                </div>
                            )}
                        </div>
                        {details?.htmlLink && (
                            <a href={details.htmlLink} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                                <ExternalLink className="size-3" />Open in Google Calendar
                            </a>
                        )}
                    </div>
                )}
                <div className="flex items-center justify-between pt-2">
                    <Button variant="ghost" className="text-destructive hover:text-destructive" onClick={remove} disabled={loading || saving}>
                        <Trash2 className="size-3.5 mr-1.5" />Delete
                    </Button>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose} disabled={saving}>Cancel</Button>
                        <Button onClick={save} disabled={loading || saving || !title.trim()}>{saving ? 'Saving…' : 'Save'}</Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

// ── Google task editor dialog ────────────────────────────────────────────
type GoogleTaskDetails = {
    id: string;
    title?: string;
    notes?: string;
    due?: string;
    status?: 'needsAction' | 'completed';
    completed?: string;
};

function GoogleTaskDialog({
    taskId, connectionId, onClose,
}: {
    taskId: string | null;
    connectionId: number | null;
    onClose: () => void;
}) {
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [title, setTitle] = useState('');
    const [notes, setNotes] = useState('');
    const [dueDate, setDueDate] = useState('');
    const [dueTime, setDueTime] = useState('');
    const [completed, setCompleted] = useState(false);

    const open = !!taskId && !!connectionId;

    useEffect(() => {
        if (!open || !taskId || !connectionId) return;
        setLoading(true);
        fetch(route('calendar.connections.tasks.show', [connectionId, taskId]), { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
                const t: GoogleTaskDetails = data.task;
                setTitle(t?.title || '');
                setNotes(t?.notes || '');
                setCompleted(t?.status === 'completed');
                if (t?.due) {
                    const d = new Date(t.due);
                    const y = d.getFullYear();
                    const m = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    setDueDate(`${y}-${m}-${day}`);
                    const hh = String(d.getHours()).padStart(2, '0');
                    const mm = String(d.getMinutes()).padStart(2, '0');
                    // If exactly 00:00 UTC, treat as "no time"
                    const utc = new Date(t.due);
                    if (utc.getUTCHours() === 0 && utc.getUTCMinutes() === 0) {
                        setDueTime('');
                    } else {
                        setDueTime(`${hh}:${mm}`);
                    }
                } else {
                    setDueDate('');
                    setDueTime('');
                }
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [taskId, connectionId, open]);

    if (!open) return null;

    const save = () => {
        if (!taskId || !connectionId) return;
        setSaving(true);
        const payload: Record<string, any> = {
            title,
            description: notes,
            completed,
            timezone: browserTimezone(),
            date: dueDate || null,
            time: dueTime || null,
        };
        router.patch(route('calendar.connections.tasks.update', [connectionId, taskId]), payload, {
            preserveScroll: true,
            onSuccess: () => { setSaving(false); onClose(); },
            onError: () => setSaving(false),
        });
    };

    const remove = () => {
        if (!taskId || !connectionId) return;
        if (!window.confirm('Delete this task?')) return;
        router.delete(route('calendar.connections.tasks.destroy', [connectionId, taskId]), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit Google task</DialogTitle>
                    <DialogDescription>Changes are saved directly to Google Tasks (and visible in Google Assistant).</DialogDescription>
                </DialogHeader>
                {loading ? (
                    <p className="text-sm text-muted-foreground py-8 text-center">Loading…</p>
                ) : (
                    <div className="space-y-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={completed} onChange={(e) => setCompleted(e.target.checked)} />
                            Completed
                        </label>
                        <div className="space-y-1.5">
                            <Label htmlFor="gt-title">Title</Label>
                            <Input id="gt-title" value={title} onChange={(e) => setTitle(e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="gt-notes">Notes</Label>
                            <textarea
                                id="gt-notes"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={4}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                placeholder="Notes…"
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="gt-date">Due date</Label>
                                <Input id="gt-date" type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="gt-time">Due time</Label>
                                <Input id="gt-time" type="time" value={dueTime} onChange={(e) => setDueTime(e.target.value)} />
                            </div>
                        </div>
                    </div>
                )}
                <div className="flex items-center justify-between pt-2">
                    <Button variant="ghost" className="text-destructive hover:text-destructive" onClick={remove} disabled={loading || saving}>
                        <Trash2 className="size-3.5 mr-1.5" />Delete
                    </Button>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose} disabled={saving}>Cancel</Button>
                        <Button onClick={save} disabled={loading || saving || !title.trim()}>{saving ? 'Saving…' : 'Save'}</Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

// ── Connections menu (Add calendar / Manage) ─────────────────────────────
type PickerData = {
    provider: string;
    account_email: string;
    account_name: string | null;
    calendars: { id: string; summary: string; backgroundColor: string | null; primary: boolean; access_role: string }[];
    task_lists: { id: string; title: string }[];
};

function ConnectionsMenu({ connections }: { connections: CalendarConnectionRow[] }) {
    const [manageOpen, setManageOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button className="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-xs font-medium border border-border bg-card text-muted-foreground hover:bg-secondary transition-colors">
                        <Plus className="size-3" />Add calendar
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuItem onClick={() => window.location.href = route('calendar.connections.google.redirect')}>
                        <span className="size-4 rounded grid place-items-center text-[10px] font-bold bg-[#4285F4] text-white mr-2">G</span>
                        Google Calendar
                    </DropdownMenuItem>
                    {connections.length > 0 && <DropdownMenuSeparator />}
                    {connections.length > 0 && (
                        <DropdownMenuItem onClick={() => setManageOpen(true)}>
                            <Settings className="size-4 mr-2" />
                            Manage connected ({connections.length})
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={manageOpen} onOpenChange={setManageOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Connected calendars</DialogTitle>
                        <DialogDescription>
                            Calendars you've connected from external providers. Disconnecting removes the calendar's events from this page.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1">
                        {connections.length === 0 && <p className="text-sm text-muted-foreground py-4 text-center">No calendars connected yet.</p>}
                        {connections.map((c) => (
                            <div key={c.id} className="flex items-center gap-2 px-2 py-2 rounded-md hover:bg-muted/50">
                                <span className="size-3 rounded-full shrink-0" style={{ background: c.external_calendar_color || '#4285F4' }} />
                                <div className="min-w-0 flex-1">
                                    <div className="text-sm font-medium truncate flex items-center gap-1.5">
                                        {c.external_calendar_name}
                                        <span className="text-[9px] font-semibold uppercase tracking-wide text-muted-foreground px-1 py-0.5 rounded bg-muted">{c.kind === 'tasks' ? 'Tasks' : 'Calendar'}</span>
                                    </div>
                                    <div className="text-[11px] text-muted-foreground truncate">{c.account_email}</div>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 text-destructive hover:text-destructive"
                                    onClick={() => {
                                        if (!window.confirm(`Disconnect "${c.external_calendar_name}"?`)) return;
                                        router.delete(route('calendar.connections.destroy', c.id), { preserveScroll: true });
                                    }}
                                    title="Disconnect"
                                >
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

// ── Calendar picker dialog (after OAuth) ─────────────────────────────────
function CalendarPickerDialog({ data, onClose }: { data: PickerData | null; onClose: () => void }) {
    const [selectedCalendars, setSelectedCalendars] = useState<string[]>([]);
    const [selectedLists, setSelectedLists] = useState<string[]>([]);
    useEffect(() => {
        if (data) {
            const primary = data.calendars.find((c) => c.primary)?.id;
            setSelectedCalendars(primary ? [primary] : []);
            // Auto-select the default task list (first one — Google always orders default first)
            setSelectedLists(data.task_lists?.[0] ? [data.task_lists[0].id] : []);
        }
    }, [data]);

    if (!data) return null;

    const totalPicked = selectedCalendars.length + selectedLists.length;

    const submit = () => {
        if (totalPicked === 0) return;
        router.post(route('calendar.connections.store'), {
            external_calendar_ids: selectedCalendars,
            external_task_list_ids: selectedLists,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                window.history.replaceState({}, '', route('calendar.index'));
                onClose();
            },
        });
    };

    return (
        <Dialog open={!!data} onOpenChange={(o) => { if (!o) onClose(); }}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Choose what to add</DialogTitle>
                    <DialogDescription>
                        Pick which of <strong>{data.account_email}</strong>'s calendars and task lists to show on Brainitive.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4 max-h-96 overflow-y-auto">
                    {data.calendars.length > 0 && (
                        <div>
                            <p className="px-1 mb-1 text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Calendars</p>
                            <div className="space-y-1">
                                {data.calendars.map((c) => {
                                    const active = selectedCalendars.includes(c.id);
                                    return (
                                        <button
                                            key={c.id}
                                            type="button"
                                            onClick={() => setSelectedCalendars(active ? selectedCalendars.filter((s) => s !== c.id) : [...selectedCalendars, c.id])}
                                            className={cn(
                                                'flex items-center gap-2 w-full px-2.5 py-2 rounded-md text-sm transition-colors',
                                                active ? 'bg-primary/10' : 'hover:bg-muted',
                                            )}
                                        >
                                            <span className="size-3 rounded-full shrink-0" style={{ background: c.backgroundColor || '#4285F4' }} />
                                            <span className="flex-1 text-left truncate">{c.summary}</span>
                                            {c.primary && <span className="text-[10px] text-muted-foreground">primary</span>}
                                            {active && <Check className="size-4 text-primary shrink-0" />}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                    {data.task_lists.length > 0 && (
                        <div>
                            <p className="px-1 mb-1 text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">Task lists</p>
                            <div className="space-y-1">
                                {data.task_lists.map((l) => {
                                    const active = selectedLists.includes(l.id);
                                    return (
                                        <button
                                            key={l.id}
                                            type="button"
                                            onClick={() => setSelectedLists(active ? selectedLists.filter((s) => s !== l.id) : [...selectedLists, l.id])}
                                            className={cn(
                                                'flex items-center gap-2 w-full px-2.5 py-2 rounded-md text-sm transition-colors',
                                                active ? 'bg-primary/10' : 'hover:bg-muted',
                                            )}
                                        >
                                            <span className="size-3 rounded-sm shrink-0 border-2 border-current" />
                                            <span className="flex-1 text-left truncate">{l.title}</span>
                                            <span className="text-[10px] text-muted-foreground">tasks</span>
                                            {active && <Check className="size-4 text-primary shrink-0" />}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={submit} disabled={totalPicked === 0}>Add {totalPicked}</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

// ── Main page ────────────────────────────────────────────────────────────
export default function CalendarIndex({ events, view, date, projectsForFilter, accessibleProjects, connections, filters }: Props) {
    const d = parseISO(date);
    const [quickCreate, setQuickCreate] = useState<QuickCreateState>(null);
    const [pickerData, setPickerData] = useState<PickerData | null>(null);
    const [googleEvent, setGoogleEvent] = useState<{ id: string; connectionId: number } | null>(null);
    const [googleTask, setGoogleTask] = useState<{ id: string; connectionId: number } | null>(null);
    const openGoogle = (id: string, connectionId: number, kind: 'event' | 'task') => {
        if (kind === 'task') setGoogleTask({ id, connectionId });
        else setGoogleEvent({ id, connectionId });
    };

    // If the URL has ?picking=google, fetch the pending session state and show picker.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('picking') === 'google') {
            fetch(route('calendar.connections.pending'), { credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => { if (data.pending) setPickerData(data.pending); });
        }
    }, []);
    const headerLabel = view === 'month'
        ? d.toLocaleDateString('en', { month: 'long', year: 'numeric' })
        : view === 'week'
            ? (() => {
                const dayOfWeek = (d.getDay() + 6) % 7;
                const mon = new Date(d); mon.setDate(d.getDate() - dayOfWeek);
                const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
                return `${mon.toLocaleDateString('en', { month: 'short', day: 'numeric' })} – ${sun.toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' })}`;
            })()
            : d.toLocaleDateString('en', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

    return (
        <AppLayout>
            <Head title="Calendar" />
            <div className="flex flex-col h-full overflow-hidden">
                {/* Header */}
                <div className="flex items-center gap-3 px-5 py-3 border-b border-border/60 shrink-0 flex-wrap">
                    <div className="flex items-center gap-1.5">
                        <Button variant="ghost" size="icon" className="size-8" onClick={() => navigateCalendar(view, date, -1, filters)}>
                            <ChevronLeft className="size-4" />
                        </Button>
                        <Button variant="ghost" size="sm" className="h-8 px-3 text-sm font-medium" onClick={() => navigateCalendar(view, date, 0, filters)}>
                            Today
                        </Button>
                        <Button variant="ghost" size="icon" className="size-8" onClick={() => navigateCalendar(view, date, 1, filters)}>
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                    <h1 className="text-lg font-bold tracking-tight">{headerLabel}</h1>
                    <div className="flex-1" />
                    <FilterBar projectsForFilter={projectsForFilter} connections={connections} filters={filters} view={view} date={date} />
                    <ConnectionsMenu connections={connections} />
                    <div className="inline-flex p-[3px] bg-secondary border border-border rounded-[7px] gap-[1px]">
                        {VIEWS.map((v) => {
                            const icons = { month: Calendar, week: Kanban, day: Clock };
                            const Ic = icons[v];
                            return (
                                <button
                                    key={v}
                                    onClick={() => router.get(route('calendar.index'), { view: v, date, ...filtersToParams(filters) }, { preserveState: true })}
                                    className={cn(
                                        'h-[22px] px-[9px] text-[11.5px] font-semibold text-muted-foreground rounded-[5px] inline-flex items-center gap-[5px] transition-colors',
                                        view === v && 'bg-card text-foreground shadow-[0_1px_0_rgba(20,32,44,0.04),0_1px_2px_rgba(0,0,0,0.04)]',
                                        view !== v && 'hover:text-foreground',
                                    )}
                                >
                                    <Ic className="size-3" />
                                    <span className="hidden sm:inline">{VIEW_LABELS[v]}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {view === 'month' && <MonthView events={events} date={date} onQuickCreate={setQuickCreate} onOpenGoogle={openGoogle} />}
                {view === 'week' && <WeekView events={events} date={date} onQuickCreate={setQuickCreate} onOpenGoogle={openGoogle} />}
                {view === 'day' && <DayView events={events} date={date} onQuickCreate={setQuickCreate} onOpenGoogle={openGoogle} />}

                <QuickCreateModal
                    state={quickCreate}
                    onClose={() => setQuickCreate(null)}
                    accessibleProjects={accessibleProjects}
                    connections={connections}
                />

                <CalendarPickerDialog data={pickerData} onClose={() => setPickerData(null)} />

                <GoogleEventDialog
                    eventId={googleEvent?.id ?? null}
                    connectionId={googleEvent?.connectionId ?? null}
                    onClose={() => setGoogleEvent(null)}
                />

                <GoogleTaskDialog
                    taskId={googleTask?.id ?? null}
                    connectionId={googleTask?.connectionId ?? null}
                    onClose={() => setGoogleTask(null)}
                />
            </div>
        </AppLayout>
    );
}
