import AppLayout from '@/Layouts/AppLayout';
import { Head, router, Link } from '@inertiajs/react';
import { PageProps, CalendarEvent } from '@/types';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    ChevronLeft, ChevronRight, Calendar, Kanban, Clock, Filter, Check, Repeat,
} from 'lucide-react';
import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { useState, useMemo, useEffect } from 'react';

type ProjectFilterOption = {
    id: number;
    name: string;
    color?: string | null;
    workspace?: { id: number; name: string } | null;
};

type Filters = {
    projects: number[];
    me_only: boolean;
    show_completed: boolean;
    sources: string[];
};

interface Props extends PageProps {
    events: CalendarEvent[];
    view: 'month' | 'week' | 'day';
    date: string;
    projectsForFilter: ProjectFilterOption[];
    filters: Filters;
}

const VIEW_LABELS = { month: 'Month', week: 'Week', day: 'Day' } as const;
const VIEWS: ('month' | 'week' | 'day')[] = ['month', 'week', 'day'];
const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function eventHref(e: CalendarEvent): string {
    if (e.type === 'board_item') return route('boards.items.show', e.href_params);
    return route('task-lists.show', e.href_params);
}

function timeToMinutes(time: string): number {
    const [h, m] = time.split(':').map(Number);
    return h * 60 + (m || 0);
}

function formatTime(time: string): string {
    const [h, m] = time.substring(0, 5).split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const hr = h % 12 || 12;
    return `${hr}${m ? ':' + String(m).padStart(2, '0') : ''} ${ampm}`;
}

function daysBetween(a: Date, b: Date): number {
    return Math.round((b.getTime() - a.getTime()) / 86400000);
}

function isoDate(d: Date): string {
    return d.toISOString().split('T')[0];
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
    if (f.sources && f.sources.length < 2) out.sources = f.sources;
    return out;
}

function handleDrop(e: React.DragEvent, targetDate: string, targetTime?: string) {
    e.preventDefault();
    const data = e.dataTransfer.getData('application/calendar-event');
    if (!data) return;
    const parsed = JSON.parse(data);
    if (parsed.is_recurring_instance) return; // can't reschedule a virtual occurrence
    const payload: Record<string, any> = { type: parsed.type, id: parsed.id, due_date: targetDate };
    if (targetTime) payload.due_time = targetTime;
    router.patch(route('calendar.reschedule'), payload, { preserveScroll: true });
}

// ── Filter popover ───────────────────────────────────────────────────────
function FilterBar({
    projectsForFilter, filters, view, date,
}: {
    projectsForFilter: ProjectFilterOption[];
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
        (filters.sources && filters.sources.length < 2 ? 1 : 0);

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
                            onClick={() => apply({ projects: [], me_only: false, show_completed: true, sources: ['board', 'list'] })}
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
                    <div className="flex gap-1.5">
                        {(['board', 'list'] as const).map((src) => {
                            const active = filters.sources?.includes(src);
                            return (
                                <button
                                    key={src}
                                    onClick={() => {
                                        const next = active
                                            ? filters.sources.filter((s) => s !== src)
                                            : [...(filters.sources || []), src];
                                        apply({ ...filters, sources: next.length === 0 ? ['board', 'list'] : next });
                                    }}
                                    className={cn(
                                        'flex-1 px-2.5 py-1.5 rounded-full text-xs border transition-colors capitalize',
                                        active ? 'border-primary bg-primary/10 text-primary font-semibold' : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    {src === 'board' ? 'Board items' : 'List items'}
                                </button>
                            );
                        })}
                    </div>
                </div>

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
function EventChip({ event, compact = false, multiDay = false }: { event: CalendarEvent; compact?: boolean; multiDay?: boolean }) {
    const color = event.color || event.project?.color || '#0E83DC';
    return (
        <Link
            href={eventHref(event)}
            draggable={!event.is_recurring_instance}
            onDragStart={(e) => {
                e.dataTransfer.setData('application/calendar-event', JSON.stringify({ type: event.type, id: event.id, is_recurring_instance: event.is_recurring_instance }));
                e.dataTransfer.effectAllowed = 'move';
            }}
            className={cn(
                'group flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs leading-snug transition-colors cursor-pointer overflow-hidden',
                'hover:shadow-sm border border-transparent hover:border-border',
                event.is_completed && 'opacity-50',
                multiDay && 'rounded-none first:rounded-l-md last:rounded-r-md',
            )}
            style={{ backgroundColor: `${color}15` }}
            title={`${event.title}${event.project ? ' — ' + event.project.name : ''}`}
        >
            {!multiDay && <span className="w-[3px] self-stretch shrink-0 rounded-full" style={{ background: color }} />}
            <span className={cn('truncate font-medium flex items-center gap-1', event.is_completed && 'line-through')}>
                {event.is_recurring_instance && <Repeat className="size-2.5 shrink-0 opacity-70" />}
                {!compact && event.due_time && <span className="text-muted-foreground mr-1">{formatTime(event.due_time)}</span>}
                {event.title}
            </span>
            {!compact && event.assignee && <AssigneeAvatar user={event.assignee} size={16} className="ml-auto shrink-0" />}
        </Link>
    );
}

// ── Multi-day bar (month view) ───────────────────────────────────────────
function MultiDayBar({ event, span }: { event: CalendarEvent; span: number }) {
    const color = event.color || event.project?.color || '#0E83DC';
    return (
        <Link
            href={eventHref(event)}
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

// ── Month view ───────────────────────────────────────────────────────────
function MonthView({ events, date }: { events: CalendarEvent[]; date: string }) {
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
                                'border-b border-r border-border p-1 min-h-[110px] transition-colors relative',
                                !inMonth && 'bg-muted/30 text-muted-foreground',
                            )}
                            onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }}
                            onDrop={(e) => handleDrop(e, iso)}
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
                                {dayEvents.slice(0, 3).map((ev) => <EventChip key={`${ev.type}-${ev.id}`} event={ev} compact />)}
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
    startHour,
    endHour,
}: {
    columns: { key: string; label: string; iso: string }[];
    allDayByCol: Map<string, CalendarEvent[]>;
    timedByCol: Map<string, CalendarEvent[]>;
    startHour: number;
    endHour: number;
}) {
    const today = isoDate(new Date());
    const now = useNow();
    const hours = Array.from({ length: endHour - startHour }, (_, i) => startHour + i);
    const HOUR_HEIGHT = 60;

    // Now-line position
    const nowMins = now.getHours() * 60 + now.getMinutes();
    const nowTop = ((nowMins / 60) - startHour) * HOUR_HEIGHT;
    const showNowLine = now.getHours() >= startHour && now.getHours() < endHour;

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
                                className="border-l border-border p-1 min-h-[32px] space-y-0.5"
                                onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }}
                                onDrop={(e) => handleDrop(e, col.iso)}
                            >
                                {items.map((ev) => <EventChip key={`${ev.type}-${ev.id}`} event={ev} compact />)}
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
            <div className="flex-1 overflow-auto">
                <div className="flex relative" style={{ height: (endHour - startHour) * HOUR_HEIGHT }}>
                    {/* Time labels */}
                    <div className="w-14 shrink-0 relative">
                        {hours.map((h) => (
                            <div
                                key={h}
                                className="absolute right-2 text-[10px] text-muted-foreground -translate-y-1/2"
                                style={{ top: (h - startHour) * HOUR_HEIGHT }}
                            >
                                {h % 12 || 12}{h < 12 ? 'a' : 'p'}
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
                                            className="absolute inset-x-0 border-t border-border/60"
                                            style={{ top: (h - startHour) * HOUR_HEIGHT, height: HOUR_HEIGHT }}
                                            onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; }}
                                            onDrop={(e) => {
                                                const rect = e.currentTarget.getBoundingClientRect();
                                                const y = e.clientY - rect.top;
                                                const mins = Math.round(y / (HOUR_HEIGHT / 2)) * 30;
                                                const totalMins = h * 60 + mins;
                                                const hh = String(Math.floor(totalMins / 60)).padStart(2, '0');
                                                const mm = String(totalMins % 60).padStart(2, '0');
                                                handleDrop(e, col.iso, `${hh}:${mm}`);
                                            }}
                                        >
                                            <div className="h-[30px]" />
                                            <div className="h-[30px] border-t border-border/30" />
                                        </div>
                                    ))}

                                    {/* Now line — only on today's column */}
                                    {isTodayCol && showNowLine && (
                                        <div
                                            className="absolute inset-x-0 z-20 pointer-events-none"
                                            style={{ top: nowTop }}
                                        >
                                            <div className="absolute -left-1 -translate-y-1/2 size-2 rounded-full bg-red-500" />
                                            <div className="h-px bg-red-500" />
                                        </div>
                                    )}

                                    {/* Timed events */}
                                    {items.map((ev) => {
                                        const t = ev.due_time || ev.start_time || '08:00';
                                        const mins = timeToMinutes(t);
                                        const top = ((mins / 60) - startHour) * HOUR_HEIGHT;
                                        const endT = ev.due_time && ev.start_time ? timeToMinutes(ev.due_time) : mins + 30;
                                        const height = Math.max(((endT - mins) / 60) * HOUR_HEIGHT, 24);
                                        return (
                                            <div key={`${ev.type}-${ev.id}-${ev.due_date}`} className="absolute inset-x-1 z-10" style={{ top, height }}>
                                                <EventChip event={ev} />
                                            </div>
                                        );
                                    })}
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
function WeekView({ events, date }: { events: CalendarEvent[]; date: string }) {
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

    const { startHour, endHour } = autoTimeRange(events);
    return <TimeGrid columns={columns} allDayByCol={allDayByCol} timedByCol={timedByCol} startHour={startHour} endHour={endHour} />;
}

// ── Day view ─────────────────────────────────────────────────────────────
function DayView({ events, date }: { events: CalendarEvent[]; date: string }) {
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

    const { startHour, endHour } = autoTimeRange(events);
    return <TimeGrid columns={columns} allDayByCol={allDayByCol} timedByCol={timedByCol} startHour={startHour} endHour={endHour} />;
}

function autoTimeRange(events: CalendarEvent[]): { startHour: number; endHour: number } {
    let minH = 6;
    let maxH = 22;
    events.forEach((ev) => {
        const t = ev.due_time || ev.start_time;
        if (!t) return;
        const h = parseInt(t.substring(0, 2), 10);
        if (h < minH) minH = h;
        if (h + 1 > maxH) maxH = Math.min(h + 1, 24);
    });
    return { startHour: minH, endHour: maxH };
}

// ── Main page ────────────────────────────────────────────────────────────
export default function CalendarIndex({ events, view, date, projectsForFilter, filters }: Props) {
    const d = parseISO(date);
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
                    <FilterBar projectsForFilter={projectsForFilter} filters={filters} view={view} date={date} />
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

                {view === 'month' && <MonthView events={events} date={date} />}
                {view === 'week' && <WeekView events={events} date={date} />}
                {view === 'day' && <DayView events={events} date={date} />}
            </div>
        </AppLayout>
    );
}
