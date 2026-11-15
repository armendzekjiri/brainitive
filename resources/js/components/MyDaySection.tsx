import { DUE_TONE_CLASSES, dueInfo } from '@/components/lists/taskDates';
import AddPlanLine from '@/components/my-day/AddPlanLine';
import MyWeekBoard, { visitWeek, WeekNav, weekTitle } from '@/components/my-day/MyWeekBoard';
import { EditableNoteTitle, NoteDoneToggle, PlanLineMenu } from '@/components/my-day/PlanLineCard';
import { Button } from '@/components/ui/button';
import WorkDoneToggle from '@/components/WorkDoneToggle';
import { noteColorClass, shiftDate } from '@/lib/myWeek';
import { cn } from '@/lib/utils';
import type { MyDayPlan, MyWeekPlan, MyWorkItem, PageProps, PlanLine } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { CalendarDays, GripVertical, History, Play, Sun, Timer, X } from 'lucide-react';
import { useState, type DragEvent } from 'react';
import { toast } from 'sonner';

type Row = MyDayPlan['rows'][number];
/** Today's plan as one list: linked work (`row`) and free-form lines (`note`), by position. */
type DayLine = { id: number; position: number; row?: Row; note?: PlanLine };

function href(row: Row): string | null {
    const ws = row.project.workspace?.slug;
    if (!ws) return null;
    return row.item.work_type === 'list'
        ? route('task-lists.show', [ws, row.project.slug, row.item.source.slug]) + `#task-${row.item.id}`
        : route('boards.items.show', [ws, row.project.slug, row.item.source.slug, row.item.id]);
}

/** Day | Week switch in the section header (the week is loaded only when asked for). */
function PlanModeSwitch({ mode }: { mode: 'day' | 'week' }) {
    const showDay = () => {
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        delete params.plan;
        delete params.week;
        router.get(route('my-work.index'), params, { preserveState: true, preserveScroll: true, only: ['myWeek', 'plan'] });
    };
    const base = 'inline-flex h-[24px] items-center gap-1 rounded-[5px] px-2 text-[11.5px] font-semibold transition-colors';
    const on = 'bg-card text-foreground shadow-[0_1px_0_rgba(20,32,44,0.04),0_1px_2px_rgba(0,0,0,0.04)]';
    const off = 'text-muted-foreground hover:text-foreground';
    return (
        <div className="inline-flex rounded-[7px] border border-border bg-secondary p-[2px]" role="group" aria-label="Plan view">
            <button type="button" aria-pressed={mode === 'day'} onClick={() => mode !== 'day' && showDay()} className={cn(base, mode === 'day' ? on : off)}>
                <Sun className="size-3.5" /> Day
            </button>
            <button type="button" aria-pressed={mode === 'week'} onClick={() => mode !== 'week' && visitWeek(null)} className={cn(base, mode === 'week' ? on : off)}>
                <CalendarDays className="size-3.5" /> Week
            </button>
        </div>
    );
}

/**
 * Today's hand-picked plan on top of My Work (BRAI-74) — or, switched to Week, the whole week
 * (My Week, v1.53.0). The order is the user's, except that done lines sink to the bottom of the
 * day. The circle completes / reopens a task (WorkDoneToggle) or ticks a free-form line; due dates
 * are never changed here. Board items can be timed (one timer per person).
 */
export default function MyDaySection({ plan, mode = 'day', week = null }: { plan: MyDayPlan; mode?: 'day' | 'week'; week?: MyWeekPlan | null }) {
    const timer = usePage<PageProps>().props.activeTimer;
    const isWeek = mode === 'week' && !!week;
    // A dragged order shows right away and is dropped once the server answered.
    const [optimistic, setOptimistic] = useState<DayLine[] | null>(null);
    const done = (item: MyWorkItem) => !!item.completed_at || item.status === 'done';
    const lineIsDone = (line: DayLine) => (line.row ? done(line.row.item) : !!line.note?.done);
    const planned: DayLine[] = [
        ...plan.rows.map((row) => ({ id: row.entry_id, position: row.position, row })),
        ...(plan.notes ?? []).map((note) => ({ id: note.id, position: note.position, note })),
    ].sort((a, b) => a.position - b.position || a.id - b.id);
    // done lines sink to the bottom (stable: the user's order within open and within done)
    const rows = [...(optimistic ?? planned)].sort((a, b) => Number(lineIsDone(a)) - Number(lineIsDone(b)));
    const [drag, setDrag] = useState<{ key: number; over: number | null } | null>(null);

    const options = { preserveScroll: true, preserveState: true } as const;
    const doneCount = rows.filter(lineIsDone).length;
    // "Move to" targets of a line: the next days from today
    const weekDates = Array.from({ length: 7 }, (_, i) => shiftDate(plan.date, i));

    const remove = (item: MyWorkItem) =>
        router.delete(route('my-day.destroy'), { ...options, data: { work_type: item.work_type, work_id: item.id }, onError: () => toast.error('Could not update My Day.') });

    const startTimer = (item: MyWorkItem) =>
        router.post(route('work-timer.start'), { item_id: item.id }, {
            ...options,
            onSuccess: () => toast.success(`Timer started on ${item.key}.`),
            onError: (errors) => toast.error(errors.timer ?? errors.item_id ?? 'Could not start the timer.'),
        });

    const carryOver = () =>
        router.post(route('my-day.carry-over'), {}, {
            ...options,
            onSuccess: (page) => toast.success((page.props as PageProps).flash?.success ?? 'Brought over.'),
        });

    const move = (fromKey: number, toKey: number) => {
        const from = rows.findIndex((r) => r.id === fromKey);
        const to = rows.findIndex((r) => r.id === toKey);
        if (from < 0 || to < 0 || from === to) return;
        const next = [...rows];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved); // dragging down lands below the target, up lands above
        setOptimistic(next);
        router.post(route('my-day.entries.reorder'), { plan_date: plan.date, ids: next.map((r) => r.id) }, {
            ...options,
            onError: () => toast.error('Could not save the order.'),
            onFinish: () => setOptimistic(null),
        });
    };
    const dragProps = (key: number) => ({
        draggable: true,
        onDragStart: (e: DragEvent) => {
            e.dataTransfer.effectAllowed = 'move';
            setDrag({ key, over: null });
        },
        onDragOver: (e: DragEvent) => {
            if (!drag) return;
            e.preventDefault();
            if (drag.over !== key) setDrag({ ...drag, over: key });
        },
        onDrop: (e: DragEvent) => {
            e.preventDefault();
            if (drag) move(drag.key, key);
            setDrag(null);
        },
        onDragEnd: () => setDrag(null),
    });

    return (
        <section
            aria-labelledby="my-day-title"
            className={cn(
                'overflow-hidden rounded-[10px] border bg-card shadow-[0_1px_0_rgba(20,32,44,0.04)]',
                isWeek ? 'border-border' : 'border-amber-300/50 dark:border-amber-500/25',
            )}
        >
            <header className={cn('flex flex-wrap items-center gap-3 border-b border-border/70 px-3.5 py-[11px]', !isWeek && 'bg-amber-50/60 dark:bg-amber-500/[0.06]')}>
                {isWeek ? <CalendarDays className="size-4 text-primary" /> : <Sun className="size-4 text-amber-500" />}
                <div className="min-w-0">
                    <h2 id="my-day-title" className="text-[13.5px] font-bold tracking-[-0.01em]">{isWeek ? 'My Week' : 'My Day'}</h2>
                    <p className="text-[11px] text-muted-foreground">{isWeek ? weekTitle(week.start) : format(new Date(`${plan.date}T12:00:00`), 'EEEE, MMMM d')}</p>
                </div>
                <PlanModeSwitch mode={mode} />
                {isWeek && <WeekNav week={week} today={plan.date} />}
                {mode === 'day' && rows.length > 0 && (
                    <span className="text-xs text-muted-foreground">{doneCount} of {rows.length} done</span>
                )}
                {plan.carryOverCount > 0 && (
                    <Button size="sm" variant="outline" className="ml-auto h-7 gap-1.5 text-xs" onClick={carryOver}>
                        <History className="size-3.5" />
                        Bring over {plan.carryOverCount} unfinished
                    </Button>
                )}
            </header>

            {isWeek ? (
                <MyWeekBoard week={week} today={plan.date} />
            ) : rows.length === 0 ? (
                <div className="px-3.5 pb-3 pt-4">
                    <p className="text-sm text-muted-foreground">
                        Nothing planned yet. Write a line below, or pick tasks with <Sun className="mx-0.5 inline size-3.5 align-[-2px] text-amber-500" /> on any task below — due dates and status stay as they are.
                    </p>
                    <AddPlanLine date={plan.date} label="Add a line to today" className="-mx-2 mt-2" />
                </div>
            ) : (
                <>
                <ol>
                    {rows.map((line) => {
                        const key = line.id;
                        if (line.note) {
                            const note = line.note;
                            const stripe = noteColorClass(note.color);
                            return (
                                <li
                                    key={key}
                                    {...dragProps(key)}
                                    className={cn(
                                        'group relative flex items-center gap-2.5 border-b border-border/70 px-3.5 py-2 text-[12.5px] last:border-b-0 hover:bg-background',
                                        drag?.key === key && 'opacity-40',
                                        drag?.over === key && drag.key !== key && 'bg-primary/5',
                                    )}
                                >
                                    {stripe && <span aria-hidden className={cn('absolute inset-y-1 left-0 w-[3px] rounded-r', stripe)} />}
                                    <GripVertical className="size-4 shrink-0 cursor-grab text-muted-foreground/0 group-hover:text-muted-foreground/60" aria-hidden />
                                    <NoteDoneToggle line={note} />
                                    <span className="w-16 shrink-0 text-[11px] text-muted-foreground">Note</span>
                                    <span className="min-w-0 flex-1">
                                        <EditableNoteTitle line={note} />
                                    </span>
                                    <PlanLineMenu line={note} date={plan.date} weekDates={weekDates} />
                                </li>
                            );
                        }
                        const row = line.row!;
                        const item = row.item;
                        const url = href(row);
                        const isDone = done(item);
                        const due = dueInfo({ due_date: item.due_date ?? null, due_time: null, status: isDone ? 'done' : 'todo' });
                        const timing = item.work_type === 'board' && timer?.item?.id === item.id;
                        return (
                            <li
                                key={key}
                                {...dragProps(key)}
                                className={cn(
                                    'group relative flex items-center gap-2.5 border-b border-border/70 px-3.5 py-2 text-[12.5px] last:border-b-0 hover:bg-background',
                                    drag?.key === key && 'opacity-40',
                                    drag?.over === key && drag.key !== key && 'bg-primary/5',
                                )}
                            >
                                <GripVertical className="size-4 shrink-0 cursor-grab text-muted-foreground/0 group-hover:text-muted-foreground/60" aria-hidden />
                                {row.project.workspace?.slug ? (
                                    <WorkDoneToggle item={item} workspaceSlug={row.project.workspace.slug} projectSlug={row.project.slug} />
                                ) : (
                                    <span className="sr-only">{isDone ? 'Done:' : 'Open:'}</span>
                                )}
                                <span className="w-16 shrink-0 font-mono text-[11px] text-muted-foreground">{item.key}</span>
                                <span className="min-w-0 flex-1">
                                    {url ? (
                                        <Link href={url} className={cn('block truncate font-medium hover:text-primary', isDone && 'text-muted-foreground line-through')}>{item.title}</Link>
                                    ) : (
                                        <span className="block truncate font-medium">{item.title}</span>
                                    )}
                                    <span className="block truncate text-[11px] text-muted-foreground">
                                        {row.project.name} · {item.source.name}
                                    </span>
                                </span>
                                {due && <span className={cn('hidden shrink-0 text-[11.5px] font-semibold sm:inline', DUE_TONE_CLASSES[due.tone])}>{due.label}</span>}
                                {item.work_type === 'board' && !isDone && (
                                    timing ? (
                                        <span className="inline-flex h-7 shrink-0 items-center gap-1 rounded-full bg-brand-green/10 px-2 text-[11px] font-semibold text-brand-green">
                                            <Timer className="size-3.5" /> Timing
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => startTimer(item)}
                                            disabled={!!timer}
                                            title={timer ? 'Another timer is running' : 'Start focus timer'}
                                            aria-label={`Start focus timer on ${item.key}`}
                                            className="grid size-7 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-brand-green disabled:opacity-30"
                                        >
                                            <Play className="size-4" />
                                        </button>
                                    )
                                )}
                                <button
                                    type="button"
                                    onClick={() => remove(item)}
                                    aria-label={`Remove “${item.title}” from My Day`}
                                    title="Remove from My Day"
                                    className="grid size-7 shrink-0 place-items-center rounded-md text-muted-foreground transition-opacity hover:bg-secondary hover:text-foreground md:opacity-0 md:focus-visible:opacity-100 md:group-hover:opacity-100"
                                >
                                    <X className="size-4" />
                                </button>
                            </li>
                        );
                    })}
                </ol>
                <AddPlanLine date={plan.date} label="Add a line to today" className="border-t border-border/70 px-1.5 py-1" />
                </>
            )}
        </section>
    );
}
