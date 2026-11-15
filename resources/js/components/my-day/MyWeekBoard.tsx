import AddPlanLine from '@/components/my-day/AddPlanLine';
import PlanLineCard from '@/components/my-day/PlanLineCard';
import { idsWithMove, lineDone, MY_WORK_MIME, PLAN_LINE_MIME, shiftDate, SOMEDAY, weekRangeLabel } from '@/lib/myWeek';
import { cn } from '@/lib/utils';
import type { MyWeekPlan, PlanLine } from '@/types';
import { router } from '@inertiajs/react';
import { format, getISOWeek, parseISO } from 'date-fns';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState, type DragEvent } from 'react';
import { toast } from 'sonner';

type Day = MyWeekPlan['days'][number];
type Over = { date: string; index: number } | null;

/** Open another week (keeps the rest of My Work's URL — scope, due chip, filters). */
export function visitWeek(week: string | null) {
    const params = Object.fromEntries(new URLSearchParams(window.location.search));
    delete params.week;
    router.get(
        route('my-work.index'),
        { ...params, plan: 'week', ...(week ? { week } : {}) },
        { preserveState: true, preserveScroll: true, only: ['myWeek', 'plan'] },
    );
}

/** Title + ‹ This week › for the section header while the week is shown. */
export function WeekNav({ week, today }: { week: MyWeekPlan; today: string }) {
    const isThisWeek = week.days.some((d) => d.date === today);
    const iconButton = 'grid size-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground';
    return (
        <div className="flex items-center gap-1">
            <button type="button" onClick={() => visitWeek(shiftDate(week.start, -7))} aria-label="Previous week" className={iconButton}>
                <ChevronLeft className="size-4" />
            </button>
            <button
                type="button"
                onClick={() => visitWeek(null)}
                disabled={isThisWeek}
                className="h-7 rounded-md border border-border bg-card px-2.5 text-[11.5px] font-semibold text-foreground transition-colors hover:bg-secondary disabled:cursor-default disabled:text-muted-foreground disabled:hover:bg-card"
            >
                This week
            </button>
            <button type="button" onClick={() => visitWeek(shiftDate(week.start, 7))} aria-label="Next week" className={iconButton}>
                <ChevronRight className="size-4" />
            </button>
        </div>
    );
}

/** "Week 46 · 9 – 15 Nov 2026" */
export const weekTitle = (start: string) => `Week ${getISOWeek(parseISO(start))} · ${weekRangeLabel(start)}`;

/**
 * My Week (v1.53.0), laid out like a paper planner: Monday to Friday side by side, then Saturday,
 * Sunday and a wide "Someday" list (lines without a day). Each holds cards — free-form lines and
 * linked issues / list tasks. Lines are dragged within and between lists (one `reorder` call for the
 * target list); My Work rows below can be dropped onto a list to plan them there.
 */
export default function MyWeekBoard({ week, today }: { week: MyWeekPlan; today: string }) {
    // the seven days + Someday, handled alike (Someday's "date" is SOMEDAY)
    const [optimistic, setOptimistic] = useState<Day[] | null>(null);
    const lists = optimistic ?? [...week.days, { date: SOMEDAY, lines: week.someday }];
    const days = lists;
    const weekDates = week.days.map((d) => d.date);
    const [drag, setDrag] = useState<{ id: number; from: string } | null>(null);
    const [over, setOver] = useState<Over>(null);

    const accepts = (e: DragEvent) => !!drag || e.dataTransfer.types.includes(MY_WORK_MIME);
    const overAt = (e: DragEvent, target: Over) => {
        if (!accepts(e)) return;
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = drag ? 'move' : 'copy';
        if (over?.date !== target?.date || over?.index !== target?.index) setOver(target);
    };
    /**
     * Where a dragged line would land in the list under the pointer: before the first card of the
     * pointer's column whose middle is below it, else after that column's last card. Works for the
     * single-column days and the multi-column Someday; gaps between cards count too.
     */
    const dropIndexAt = (e: DragEvent): number => {
        const cards = Array.from((e.currentTarget as HTMLElement).querySelectorAll<HTMLElement>('[data-line-id]'));
        let lastInColumn = -1;
        for (let i = 0; i < cards.length; i++) {
            const box = cards[i].getBoundingClientRect();
            if (e.clientX < box.left || e.clientX > box.right) continue;
            if (e.clientY < box.top + box.height / 2) return i;
            lastInColumn = i;
        }
        return lastInColumn >= 0 ? lastInColumn + 1 : cards.length;
    };
    const clear = () => {
        setDrag(null);
        setOver(null);
    };

    const drop = (e: DragEvent, date: string, index: number) => {
        e.preventDefault();
        e.stopPropagation();
        const work = e.dataTransfer.getData(MY_WORK_MIME);
        const moving = drag;
        clear();

        if (!moving && work) {
            const [work_type, id] = work.split(':');
            router.post(route('my-day.entries.store'), { plan_date: date, work_type, work_id: Number(id) }, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => toast.success(date === SOMEDAY ? 'Added to Someday.' : `Planned for ${format(parseISO(date), 'EEEE')}.`),
                onError: () => toast.error('Could not plan it.'),
            });
            return;
        }
        if (!moving) return;

        const target = days.find((d) => d.date === date);
        const source = days.find((d) => d.date === moving.from);
        if (!target || !source || !source.lines.some((l) => l.id === moving.id)) return;
        const current = target.lines.map((l) => l.id);
        const ids = idsWithMove(current, moving.id, index);
        if (moving.from === date && ids.join() === current.join()) return;

        const byId = new Map<number, PlanLine>([...source.lines, ...target.lines].map((l) => [l.id, l]));
        setOptimistic(
            days.map((d) => {
                if (d.date === date) return { ...d, lines: ids.map((id) => byId.get(id)!).filter(Boolean) };
                if (d.date === moving.from) return { ...d, lines: d.lines.filter((l) => l.id !== moving.id) };
                return d;
            }),
        );
        router.post(route('my-day.entries.reorder'), { plan_date: date, ids }, {
            preserveScroll: true,
            preserveState: true,
            onError: () => toast.error('Could not move it.'),
            onFinish: () => setOptimistic(null),
        });
    };

    const panel = (day: Day, variant: 'weekday' | 'weekend' | 'someday' = 'weekday') => {
        const someday = variant === 'someday';
        const date = someday ? new Date() : parseISO(day.date);
        const isToday = day.date === today;
        const isPast = !someday && day.date < today;
        const doneCount = day.lines.filter(lineDone).length;
        const total = day.lines.length;
        const dropIndex = over?.date === day.date ? over.index : null;
        const marker = (i: number) =>
            dropIndex === i ? <li key={`drop-${i}`} aria-hidden className="-my-[3px] h-0.5 rounded-full bg-primary [break-after:avoid]" /> : null;
        const title = someday ? 'Someday' : format(date, 'EEEE');

        return (
            <section
                key={day.date}
                {...(someday ? { 'data-list': SOMEDAY } : { 'data-date': day.date })}
                aria-label={someday ? 'Someday' : format(date, 'EEEE d MMMM')}
                onDragOver={(e) => overAt(e, { date: day.date, index: drag ? dropIndexAt(e) : -1 })}
                onDragLeave={(e) => {
                    if (!e.currentTarget.contains(e.relatedTarget as Node)) setOver(null);
                }}
                onDrop={(e) => drop(e, day.date, dropIndex !== null && dropIndex >= 0 ? dropIndex : day.lines.length)}
                className={cn(
                    'flex min-w-0 gap-2 rounded-lg border bg-muted/40 py-2 pl-1.5 pr-2 transition-colors dark:bg-white/[0.025]',
                    isToday ? 'border-primary/40 bg-primary/[0.035] dark:bg-primary/[0.06]' : 'border-transparent',
                    dropIndex !== null && 'border-primary/60 bg-primary/[0.06]',
                    variant === 'weekday' ? 'sm:min-h-[250px]' : 'sm:min-h-[170px]',
                    someday && 'sm:col-span-2 xl:col-span-3',
                )}
            >
                {/* the title runs up the left edge, read bottom to top (like a paper planner) */}
                <header className="flex shrink-0 rotate-180 items-center gap-2 py-1 [writing-mode:vertical-rl]">
                    <span
                        className={cn(
                            'text-[17px] font-black uppercase leading-none tracking-[0.02em]',
                            isToday ? 'text-primary' : isPast ? 'text-muted-foreground/45' : someday ? 'text-muted-foreground' : 'text-foreground',
                        )}
                    >
                        {title}
                    </span>
                    {!someday && (
                        <span className={cn('text-[11px] font-semibold leading-none', isToday ? 'text-primary' : 'text-muted-foreground')}>
                            {isToday ? `Today · ${format(date, 'd MMM')}` : format(date, 'd MMM')}
                        </span>
                    )}
                    {total > 0 && (
                        <span className="text-[10.5px] font-semibold leading-none tabular-nums text-muted-foreground/80" title={someday ? undefined : `${doneCount} of ${total} done`}>
                            {someday ? total : `${doneCount}/${total}`}
                        </span>
                    )}
                </header>
                {/* progress of the day, filling from the bottom */}
                <div className={cn('flex w-[3px] shrink-0 flex-col justify-end overflow-hidden rounded-full bg-border/60', (someday || total === 0) && 'invisible')} aria-hidden>
                    <div className="w-full rounded-full bg-brand-green transition-[height] duration-300" style={{ height: total ? `${(doneCount / total) * 100}%` : 0 }} />
                </div>

                <div className="flex min-w-0 flex-1 flex-col">
                    <ol className={cn(someday ? 'gap-x-2 sm:columns-2 xl:columns-3 [&>li]:mb-1.5 [&>li]:break-inside-avoid' : 'flex flex-col gap-1.5')}>
                        {day.lines.flatMap((line, i) => [
                            marker(i),
                            <PlanLineCard
                                key={line.id}
                                line={line}
                                date={day.date}
                                weekDates={weekDates}
                                draggable
                                onDragStart={(e) => {
                                    if ((e.target as HTMLElement).closest('textarea,input')) return e.preventDefault();
                                    e.dataTransfer.effectAllowed = 'move';
                                    e.dataTransfer.setData(PLAN_LINE_MIME, String(line.id));
                                    setDrag({ id: line.id, from: day.date });
                                }}
                                onDragEnd={clear}
                                className={cn(drag?.id === line.id && 'opacity-40')}
                            />,
                        ])}
                        {marker(day.lines.length)}
                    </ol>
                    <AddPlanLine
                        date={day.date}
                        label={someday ? 'Add a line to Someday' : `Add a line on ${title}`}
                        className={cn(total > 0 && 'mt-1', someday && 'sm:max-w-[calc(50%-4px)] xl:max-w-[calc(33.333%-6px)]')}
                    />
                </div>
            </section>
        );
    };

    return (
        <div className="grid grid-cols-1 gap-2 p-2.5 sm:grid-cols-2 xl:grid-cols-5">
            {lists.slice(0, 5).map((day) => panel(day))}
            {lists.slice(5, 7).map((day) => panel(day, 'weekend'))}
            {lists[7] && panel(lists[7], 'someday')}
        </div>
    );
}
