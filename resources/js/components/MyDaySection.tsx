import { DUE_TONE_CLASSES, dueInfo } from '@/components/lists/taskDates';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { MyDayPlan, MyWorkItem, PageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { CheckCircle2, GripVertical, History, Play, Sun, Timer, X } from 'lucide-react';
import { useState, type DragEvent } from 'react';
import { toast } from 'sonner';

type Row = MyDayPlan['rows'][number];
const keyOf = (item: MyWorkItem) => `${item.work_type}:${item.id}`;

function href(row: Row): string | null {
    const ws = row.project.workspace?.slug;
    if (!ws) return null;
    return row.item.work_type === 'list'
        ? route('task-lists.show', [ws, row.project.slug, row.item.source.slug]) + `#task-${row.item.id}`
        : route('boards.items.show', [ws, row.project.slug, row.item.source.slug, row.item.id]);
}

/**
 * Today's hand-picked plan on top of My Work (BRAI-74). The order is the user's; nothing here
 * changes a task's status or due date. Board items can be timed (one timer per person).
 */
export default function MyDaySection({ plan }: { plan: MyDayPlan }) {
    const timer = usePage<PageProps>().props.activeTimer;
    // A dragged order shows right away and is dropped once the server answered.
    const [optimistic, setOptimistic] = useState<Row[] | null>(null);
    const rows = optimistic ?? plan.rows;
    const [drag, setDrag] = useState<{ key: string; over: string | null } | null>(null);

    const options = { preserveScroll: true, preserveState: true } as const;
    const done = (item: MyWorkItem) => !!item.completed_at || item.status === 'done';
    const doneCount = rows.filter((r) => done(r.item)).length;

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

    const move = (fromKey: string, toKey: string) => {
        const from = rows.findIndex((r) => keyOf(r.item) === fromKey);
        const to = rows.findIndex((r) => keyOf(r.item) === toKey);
        if (from < 0 || to < 0 || from === to) return;
        const next = [...rows];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved); // dragging down lands below the target, up lands above
        setOptimistic(next);
        router.post(route('my-day.reorder'), { items: next.map((r) => ({ work_type: r.item.work_type, work_id: r.item.id })) }, {
            ...options,
            onError: () => toast.error('Could not save the order.'),
            onFinish: () => setOptimistic(null),
        });
    };
    const dragProps = (key: string) => ({
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
        <section aria-labelledby="my-day-title" className="overflow-hidden rounded-[10px] border border-amber-300/50 bg-card shadow-[0_1px_0_rgba(20,32,44,0.04)] dark:border-amber-500/25">
            <header className="flex flex-wrap items-center gap-3 border-b border-border/70 bg-amber-50/60 px-3.5 py-[11px] dark:bg-amber-500/[0.06]">
                <Sun className="size-4 text-amber-500" />
                <div className="min-w-0">
                    <h2 id="my-day-title" className="text-[13.5px] font-bold tracking-[-0.01em]">My Day</h2>
                    <p className="text-[11px] text-muted-foreground">{format(new Date(`${plan.date}T12:00:00`), 'EEEE, MMMM d')}</p>
                </div>
                {rows.length > 0 && (
                    <span className="text-xs text-muted-foreground">{doneCount} of {rows.length} done</span>
                )}
                {plan.carryOverCount > 0 && (
                    <Button size="sm" variant="outline" className="ml-auto h-7 gap-1.5 text-xs" onClick={carryOver}>
                        <History className="size-3.5" />
                        Bring over {plan.carryOverCount} unfinished
                    </Button>
                )}
            </header>

            {rows.length === 0 ? (
                <p className="px-3.5 py-5 text-sm text-muted-foreground">
                    Nothing planned yet. Pick what you’ll work on today with <Sun className="mx-0.5 inline size-3.5 align-[-2px] text-amber-500" /> on any task below — due dates and status stay as they are.
                </p>
            ) : (
                <ol>
                    {rows.map((row) => {
                        const item = row.item;
                        const key = keyOf(item);
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
                                <span className={cn('grid size-4 shrink-0 place-items-center rounded-full border', isDone ? 'border-brand-green text-brand-green' : 'border-border')} aria-hidden>
                                    {isDone && <CheckCircle2 className="size-4" />}
                                </span>
                                <span className="sr-only">{isDone ? 'Done:' : 'Open:'}</span>
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
            )}
        </section>
    );
}
