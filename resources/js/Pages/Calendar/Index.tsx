import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { PageProps, CalendarEvent } from '@/types';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight, Calendar, Kanban, List, Clock } from 'lucide-react';
import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { Link } from '@inertiajs/react';

interface Props extends PageProps {
    events: CalendarEvent[];
    view: 'month' | 'week' | 'day';
    date: string;
}

const VIEW_LABELS = { month: 'Month', week: 'Week', day: 'Day' } as const;
const VIEWS: ('month' | 'week' | 'day')[] = ['month', 'week', 'day'];
const HOUR_HEIGHT = 60;
const START_HOUR = 6;
const END_HOUR = 22;
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

function navigateCalendar(view: string, date: string, dir: -1 | 0 | 1) {
    const d = new Date(date);
    if (dir === 0) {
        router.get(route('calendar.index'), { view, date: new Date().toISOString().split('T')[0] }, { preserveState: true });
        return;
    }
    if (view === 'month') d.setMonth(d.getMonth() + dir);
    else if (view === 'week') d.setDate(d.getDate() + dir * 7);
    else d.setDate(d.getDate() + dir);
    router.get(route('calendar.index'), { view, date: d.toISOString().split('T')[0] }, { preserveState: true });
}

function handleDrop(e: React.DragEvent, targetDate: string, targetTime?: string) {
    e.preventDefault();
    const data = e.dataTransfer.getData('application/calendar-event');
    if (!data) return;
    const { type, id } = JSON.parse(data);
    const payload: Record<string, any> = { type, id, due_date: targetDate };
    if (targetTime) payload.due_time = targetTime;
    router.patch(route('calendar.reschedule'), payload, { preserveScroll: true });
}

// ── Event chip (shared) ──────────────────────────────────────────────────
function EventChip({ event, compact = false }: { event: CalendarEvent; compact?: boolean }) {
    const color = event.color || event.project?.color || '#0E83DC';
    return (
        <Link
            href={eventHref(event)}
            draggable
            onDragStart={(e) => {
                e.dataTransfer.setData('application/calendar-event', JSON.stringify({ type: event.type, id: event.id }));
                e.dataTransfer.effectAllowed = 'move';
            }}
            className={cn(
                'group flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs leading-snug transition-colors cursor-pointer overflow-hidden',
                'hover:shadow-sm border border-transparent hover:border-border',
                event.is_completed && 'opacity-50',
            )}
            style={{ backgroundColor: `${color}15` }}
        >
            <span className="w-[3px] self-stretch shrink-0 rounded-full" style={{ background: color }} />
            <span className={cn('truncate font-medium', event.is_completed && 'line-through')}>
                {!compact && event.due_time && <span className="text-muted-foreground mr-1">{formatTime(event.due_time)}</span>}
                {event.title}
            </span>
            {!compact && event.assignee && <AssigneeAvatar user={event.assignee} size={16} className="ml-auto shrink-0" />}
        </Link>
    );
}

// ── Month view ───────────────────────────────────────────────────────────
function MonthView({ events, date }: { events: CalendarEvent[]; date: string }) {
    const d = new Date(date);
    const year = d.getFullYear();
    const month = d.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startOffset = (firstDay.getDay() + 6) % 7;
    const totalCells = startOffset + lastDay.getDate();
    const rows = Math.ceil(totalCells / 7);
    const today = new Date().toISOString().split('T')[0];

    const eventsByDate = new Map<string, CalendarEvent[]>();
    events.forEach((ev) => {
        const key = ev.due_date || ev.start_date;
        if (!key) return;
        if (!eventsByDate.has(key)) eventsByDate.set(key, []);
        eventsByDate.get(key)!.push(ev);
    });

    const cells: { date: Date; iso: string; inMonth: boolean }[] = [];
    for (let i = 0; i < rows * 7; i++) {
        const cellDate = new Date(year, month, 1 - startOffset + i);
        cells.push({ date: cellDate, iso: cellDate.toISOString().split('T')[0], inMonth: cellDate.getMonth() === month });
    }

    return (
        <div className="flex-1 overflow-auto">
            <div className="grid grid-cols-7 border-b border-border sticky top-0 bg-background z-10">
                {DAY_NAMES.map((n) => (
                    <div key={n} className="px-2 py-1.5 text-[11px] font-semibold text-muted-foreground text-center uppercase tracking-wide">{n}</div>
                ))}
            </div>
            <div className="grid grid-cols-7 auto-rows-[minmax(100px,1fr)]">
                {cells.map(({ iso, date: cellDate, inMonth }) => {
                    const dayEvents = eventsByDate.get(iso) || [];
                    const isToday = iso === today;
                    return (
                        <div
                            key={iso}
                            className={cn(
                                'border-b border-r border-border p-1 min-h-[100px] transition-colors',
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
                            <div className="space-y-0.5">
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
}: {
    columns: { key: string; label: string; iso: string }[];
    allDayByCol: Map<string, CalendarEvent[]>;
    timedByCol: Map<string, CalendarEvent[]>;
}) {
    const today = new Date().toISOString().split('T')[0];
    const hours = Array.from({ length: END_HOUR - START_HOUR }, (_, i) => START_HOUR + i);

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
                                className="border-l border-border p-1 min-h-[32px]"
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
                <div className="flex relative" style={{ height: (END_HOUR - START_HOUR) * HOUR_HEIGHT }}>
                    {/* Time labels */}
                    <div className="w-14 shrink-0 relative">
                        {hours.map((h) => (
                            <div
                                key={h}
                                className="absolute right-2 text-[10px] text-muted-foreground -translate-y-1/2"
                                style={{ top: (h - START_HOUR) * HOUR_HEIGHT }}
                            >
                                {h % 12 || 12}{h < 12 ? 'a' : 'p'}
                            </div>
                        ))}
                    </div>

                    {/* Columns */}
                    <div className="flex-1 grid relative" style={{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }}>
                        {columns.map((col) => {
                            const items = timedByCol.get(col.key) || [];
                            return (
                                <div key={col.key} className={cn('border-l border-border relative', col.iso === today && 'bg-primary/[0.02]')}>
                                    {/* Hour lines */}
                                    {hours.map((h) => (
                                        <div
                                            key={h}
                                            className="absolute inset-x-0 border-t border-border/60"
                                            style={{ top: (h - START_HOUR) * HOUR_HEIGHT }}
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

                                    {/* Timed events */}
                                    {items.map((ev) => {
                                        const t = ev.due_time || ev.start_time || '08:00';
                                        const mins = timeToMinutes(t);
                                        const top = ((mins / 60) - START_HOUR) * HOUR_HEIGHT;
                                        const endT = ev.due_time && ev.start_time ? timeToMinutes(ev.due_time) : mins + 30;
                                        const height = Math.max(((endT - mins) / 60) * HOUR_HEIGHT, 24);
                                        return (
                                            <div key={`${ev.type}-${ev.id}`} className="absolute inset-x-1 z-10" style={{ top, height }}>
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
    const d = new Date(date);
    const dayOfWeek = (d.getDay() + 6) % 7;
    const monday = new Date(d);
    monday.setDate(d.getDate() - dayOfWeek);

    const columns = Array.from({ length: 7 }, (_, i) => {
        const day = new Date(monday);
        day.setDate(monday.getDate() + i);
        const iso = day.toISOString().split('T')[0];
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

    return <TimeGrid columns={columns} allDayByCol={allDayByCol} timedByCol={timedByCol} />;
}

// ── Day view ─────────────────────────────────────────────────────────────
function DayView({ events, date }: { events: CalendarEvent[]; date: string }) {
    const d = new Date(date);
    const iso = d.toISOString().split('T')[0];
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

    return <TimeGrid columns={columns} allDayByCol={allDayByCol} timedByCol={timedByCol} />;
}

// ── Main page ────────────────────────────────────────────────────────────
export default function CalendarIndex({ events, view, date }: Props) {
    const d = new Date(date);
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
                        <Button variant="ghost" size="icon" className="size-8" onClick={() => navigateCalendar(view, date, -1)}>
                            <ChevronLeft className="size-4" />
                        </Button>
                        <Button variant="ghost" size="sm" className="h-8 px-3 text-sm font-medium" onClick={() => navigateCalendar(view, date, 0)}>
                            Today
                        </Button>
                        <Button variant="ghost" size="icon" className="size-8" onClick={() => navigateCalendar(view, date, 1)}>
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                    <h1 className="text-lg font-bold tracking-tight">{headerLabel}</h1>
                    <div className="flex-1" />
                    <div className="inline-flex p-[3px] bg-secondary border border-border rounded-[7px] gap-[1px]">
                        {VIEWS.map((v) => {
                            const icons = { month: Calendar, week: Kanban, day: Clock };
                            const Ic = icons[v];
                            return (
                                <button
                                    key={v}
                                    onClick={() => router.get(route('calendar.index'), { view: v, date }, { preserveState: true })}
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

                {/* View content */}
                {view === 'month' && <MonthView events={events} date={date} />}
                {view === 'week' && <WeekView events={events} date={date} />}
                {view === 'day' && <DayView events={events} date={date} />}
            </div>
        </AppLayout>
    );
}
