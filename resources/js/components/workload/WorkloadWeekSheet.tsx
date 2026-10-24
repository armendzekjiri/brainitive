import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { FLAG_LABELS, cellSummary, formatHours, hoursInput, parseHours, weekRange } from '@/lib/workload';
import { cn } from '@/lib/utils';
import type { WorkloadCandidate, WorkloadCell, WorkloadCellAllocation, WorkloadMember, WorkloadWeek } from '@/types';
import { Loader2, Search, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { MAX_WEEK_MINUTES, workloadWrite } from './workloadActions';

export interface WorkloadUrls {
    capacity: string;
    override: string;
    allocation: string;
    items: string;
    destroy: (id: number) => string;
}

interface Props {
    member: WorkloadMember;
    week: WorkloadWeek;
    cell: WorkloadCell;
    canPlan: boolean;
    urls: WorkloadUrls;
    onClose: () => void;
}

/** Remaining estimate as a sentence: "6h remaining (8h estimate − 2h logged)". */
function remainingText(item: { estimate_minutes: number | null; logged_minutes: number; remaining_minutes: number | null }): string {
    if (item.remaining_minutes === null) return 'No estimate';
    return `${formatHours(item.remaining_minutes)} remaining (${formatHours(item.estimate_minutes)} estimate − ${formatHours(item.logged_minutes)} logged)`;
}

/**
 * One person's week: capacity (with a per-week override for leave / part weeks), the planned
 * issues and — for planners — adding work with the remaining estimate as a suggestion.
 */
export function WorkloadWeekSheet({ member, week, cell, canPlan, urls, onClose }: Props) {
    return (
        <Sheet open onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>{member.name} · {week.label} {week.year}</SheetTitle>
                    <SheetDescription>{weekRange(week)} · this project only</SheetDescription>
                </SheetHeader>

                <div className="mt-4 grid grid-cols-3 gap-2 text-center" data-testid="workload-week-summary">
                    <div className="rounded-md border p-2">
                        <div className="text-xs text-muted-foreground">Available</div>
                        <div className="text-lg font-semibold tabular-nums">{cell.capacity_minutes === null ? 'Not set' : formatHours(cell.capacity_minutes)}</div>
                    </div>
                    <div className="rounded-md border p-2">
                        <div className="text-xs text-muted-foreground">Planned</div>
                        <div className="text-lg font-semibold tabular-nums">{formatHours(cell.allocated_minutes)}</div>
                    </div>
                    <div className="rounded-md border p-2">
                        <div className="text-xs text-muted-foreground">Load</div>
                        <div className="text-lg font-semibold tabular-nums">{cell.load_percent === null ? '—' : `${cell.load_percent}%`}</div>
                    </div>
                </div>
                <p className={cn('mt-2 text-sm', cell.state === 'over' ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted-foreground')}>
                    {cellSummary(cell)}
                    {cell.unestimated_minutes > 0 && ` · ${formatHours(cell.unestimated_minutes)} on issues without estimate`}
                </p>

                {canPlan && <WeekCapacityForm key={`${member.id}-${week.start}-${cell.capacity_minutes}-${cell.capacity_source}`} member={member} week={week} cell={cell} url={urls.override} />}

                <section className="mt-6">
                    <h3 className="mb-2 text-sm font-semibold">Planned work</h3>
                    {cell.allocations.length === 0 ? (
                        <p className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">Nothing planned this week.</p>
                    ) : (
                        <ul className="divide-y rounded-md border" aria-label="Planned work">
                            {cell.allocations.map((a) => (
                                <AllocationRow key={`${a.id}-${a.minutes}`} allocation={a} member={member} week={week} canPlan={canPlan} urls={urls} />
                            ))}
                        </ul>
                    )}
                </section>

                {canPlan && <AddWork member={member} week={week} urls={urls} plannedIds={cell.allocations.map((a) => a.item.id)} />}
            </SheetContent>
        </Sheet>
    );
}

function WeekCapacityForm({ member, week, cell, url }: { member: WorkloadMember; week: WorkloadWeek; cell: WorkloadCell; url: string }) {
    const [value, setValue] = useState(cell.capacity_source === 'override' ? hoursInput(cell.capacity_minutes) : '');
    const [note, setNote] = useState(cell.override_note ?? '');
    const [weeks, setWeeks] = useState('1');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const count = parseInt(weeks, 10);
    const write = (minutes: number | null) => {
        if (!(count >= 1 && count <= 26)) return setError('Apply to 1 to 26 weeks.');
        setBusy(true);
        workloadWrite('put', url, { user_id: member.id, week_start: week.start, weeks: count, minutes, note: minutes === null ? null : note.trim() || null }, { onFinish: () => setBusy(false) });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (busy) return;
        const minutes = parseHours(value);
        if (minutes === null) return setError('Use hours, e.g. 0 for leave, 16 or 7h 30m.');
        if (minutes > MAX_WEEK_MINUTES) return setError('A week has at most 168 hours.');
        write(minutes);
    };

    return (
        <form onSubmit={submit} className="mt-6 space-y-2 rounded-md border p-3">
            <div className="flex items-baseline justify-between gap-2">
                <h3 className="text-sm font-semibold">Availability this week</h3>
                <span className="text-xs text-muted-foreground">
                    Default: {member.capacity_minutes === null ? 'not set' : `${formatHours(member.capacity_minutes)} / week`}
                </span>
            </div>
            <div className="grid grid-cols-[1fr_auto] gap-2 sm:grid-cols-[1fr_1.5fr_auto]">
                <div className="space-y-1">
                    <Label htmlFor="workload-week-hours" className="text-xs">Hours</Label>
                    <Input
                        id="workload-week-hours"
                        inputMode="decimal"
                        placeholder={member.capacity_minutes === null ? 'e.g. 0 for leave' : `${hoursInput(member.capacity_minutes)} (default)`}
                        value={value}
                        aria-invalid={error !== null}
                        aria-describedby={error ? 'workload-week-error' : undefined}
                        onChange={(e) => { setValue(e.target.value); setError(null); }}
                    />
                </div>
                <div className="col-span-2 space-y-1 sm:col-span-1 sm:row-start-1 sm:col-start-2">
                    <Label htmlFor="workload-week-note" className="text-xs">Reason (optional, planners only)</Label>
                    <Input id="workload-week-note" maxLength={120} placeholder="Leave, conference…" value={note} onChange={(e) => setNote(e.target.value)} />
                </div>
                <div className="space-y-1 row-start-1 col-start-2 sm:col-start-3">
                    <Label htmlFor="workload-week-count" className="text-xs">Weeks</Label>
                    <Input id="workload-week-count" type="number" min={1} max={26} className="w-20" value={weeks} onChange={(e) => { setWeeks(e.target.value); setError(null); }} />
                </div>
            </div>
            {error && <p id="workload-week-error" role="alert" className="text-sm text-destructive">{error}</p>}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" size="sm" disabled={busy}>Set availability</Button>
                {cell.capacity_source === 'override' && (
                    <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => write(null)}>
                        Use default {count > 1 ? `for ${count} weeks` : 'this week'}
                    </Button>
                )}
            </div>
        </form>
    );
}

function AllocationRow({ allocation, member, week, canPlan, urls }: { allocation: WorkloadCellAllocation; member: WorkloadMember; week: WorkloadWeek; canPlan: boolean; urls: WorkloadUrls }) {
    const [value, setValue] = useState(hoursInput(allocation.minutes));
    const [busy, setBusy] = useState(false);
    // what was last sent: blur after Enter (or a second blur) must not send it again
    const sent = useRef(allocation.minutes);
    const { item } = allocation;

    const commit = () => {
        const minutes = parseHours(value);
        if (minutes === null || minutes > MAX_WEEK_MINUTES) {
            if (minutes !== null) toast.error('A week has at most 168 hours.');
            setValue(hoursInput(allocation.minutes));
            return;
        }
        if (busy || minutes === sent.current) return;
        sent.current = minutes;
        setBusy(true);
        workloadWrite('put', urls.allocation, {
            item_id: item.id, user_id: member.id, week_start: week.start, minutes, expected_minutes: allocation.minutes,
        }, {
            onFail: () => { sent.current = allocation.minutes; setValue(hoursInput(allocation.minutes)); },
            onFinish: () => setBusy(false),
        });
    };

    const remove = () => {
        if (busy) return;
        setBusy(true);
        workloadWrite('delete', urls.destroy(allocation.id), {}, { onFinish: () => setBusy(false) });
    };

    return (
        <li className={cn('flex items-start gap-3 p-3', !allocation.counts && 'opacity-70')}>
            <div className="min-w-0 flex-1">
                <div className="text-sm">
                    {item.url ? (
                        <a href={item.url} className="font-medium hover:underline"><span className="text-muted-foreground">{item.key}</span> {item.title}</a>
                    ) : (
                        <span className="font-medium"><span className="text-muted-foreground">{item.key}</span> {item.title}</span>
                    )}
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                    <span>{remainingText(item)}</span>
                    {item.remaining_minutes === null && <Badge variant="secondary" className="text-[10px]">No estimate</Badge>}
                    {!allocation.counts && <Badge variant="secondary" className="text-[10px]">Not counted</Badge>}
                    {allocation.flags.map((flag) => (
                        <Badge key={flag} variant="outline" className="border-amber-400/60 text-[10px] text-amber-700 dark:text-amber-300">{FLAG_LABELS[flag] ?? flag}</Badge>
                    ))}
                </div>
            </div>
            {canPlan ? (
                <div className="flex shrink-0 items-center gap-1">
                    <Input
                        aria-label={`Hours for ${item.key}`}
                        inputMode="decimal"
                        className="h-8 w-20 text-right tabular-nums"
                        value={value}
                        disabled={busy}
                        onChange={(e) => setValue(e.target.value)}
                        onBlur={commit}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.currentTarget.blur(); } }}
                    />
                    <span className="text-xs text-muted-foreground">h</span>
                    <Button
                        size="icon"
                        variant="ghost"
                        className="size-8"
                        disabled={busy}
                        aria-label={`Remove ${item.key} from this week`}
                        // don't let the click blur the hours input first (that would save, then delete)
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={remove}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            ) : (
                <span className="shrink-0 text-sm font-medium tabular-nums">{formatHours(allocation.minutes)}</span>
            )}
        </li>
    );
}

function AddWork({ member, week, urls, plannedIds }: { member: WorkloadMember; week: WorkloadWeek; urls: WorkloadUrls; plannedIds: number[] }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<WorkloadCandidate[]>([]);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [selected, setSelected] = useState<WorkloadCandidate | null>(null);
    const [hours, setHours] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        const timer = window.setTimeout(() => {
            window.axios
                .get<WorkloadCandidate[]>(urls.items, { params: { user_id: member.id, q: query || undefined }, signal: controller.signal })
                .then(({ data }) => { setResults(data); setFailed(false); setLoading(false); })
                .catch((e) => {
                    if (axios.isCancel(e)) return;
                    setResults([]);
                    setFailed(true);
                    setLoading(false);
                });
        }, 200);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [query, member.id, urls.items]);

    const reset = () => { setSelected(null); setHours(''); setError(null); };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selected || busy) return;
        const minutes = parseHours(hours);
        if (minutes === null || minutes <= 0) return setError('Enter the hours to plan, e.g. 6 or 2h 30m.');
        if (minutes > MAX_WEEK_MINUTES) return setError('A week has at most 168 hours.');
        setBusy(true);
        workloadWrite('put', urls.allocation, {
            item_id: selected.id, user_id: member.id, week_start: week.start, minutes, expected_minutes: null,
        }, { onDone: reset, onFinish: () => setBusy(false) });
    };

    const candidates = results.filter((c) => !plannedIds.includes(c.id));

    return (
        <section className="mt-6" aria-labelledby="workload-add-work">
            <h3 id="workload-add-work" className="mb-2 text-sm font-semibold">Plan work</h3>
            {selected ? (
                <form onSubmit={submit} className="space-y-3 rounded-md border p-3">
                    <div className="text-sm">
                        <span className="text-muted-foreground">{selected.key}</span> <span className="font-medium">{selected.title}</span>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {remainingText(selected)}
                            {selected.open_planned_minutes > 0 && ` · ${formatHours(selected.open_planned_minutes)} already planned from this week on`}
                        </p>
                    </div>
                    {selected.remaining_minutes !== null && selected.remaining_minutes > 0 && (
                        <Button type="button" size="sm" variant="secondary" onClick={() => { setHours(hoursInput(selected.remaining_minutes)); setError(null); }}>
                            Use remaining estimate ({formatHours(selected.remaining_minutes)})
                        </Button>
                    )}
                    {selected.remaining_minutes === null && (
                        <p className="text-xs text-amber-700 dark:text-amber-300">This issue has no estimate: the hours you plan are your own guess and are shown as unestimated.</p>
                    )}
                    <div className="flex items-end gap-2">
                        <div className="flex-1 space-y-1">
                            <Label htmlFor="workload-plan-hours" className="text-xs">Hours this week</Label>
                            <Input
                                id="workload-plan-hours"
                                inputMode="decimal"
                                autoFocus
                                placeholder="e.g. 6"
                                value={hours}
                                aria-invalid={error !== null}
                                aria-describedby={error ? 'workload-plan-error' : undefined}
                                onChange={(e) => { setHours(e.target.value); setError(null); }}
                            />
                        </div>
                        <Button type="submit" disabled={busy}>Plan</Button>
                        <Button type="button" variant="ghost" disabled={busy} onClick={reset}>Cancel</Button>
                    </div>
                    {error && <p id="workload-plan-error" role="alert" className="text-sm text-destructive">{error}</p>}
                </form>
            ) : (
                <div className="rounded-md border">
                    <div className="relative border-b">
                        <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input aria-label="Search issues to plan" maxLength={100} placeholder="Search open issues by key or title" className="border-0 pl-8 shadow-none focus-visible:ring-0" value={query} onChange={(e) => setQuery(e.target.value)} />
                    </div>
                    <ul className="max-h-72 overflow-y-auto" aria-label="Issues to plan">
                        {loading && candidates.length === 0 && (
                            <li className="flex items-center gap-2 p-3 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" /> Searching…</li>
                        )}
                        {!loading && failed && <li className="p-3 text-sm text-destructive">Couldn’t load issues. Try again.</li>}
                        {!loading && !failed && candidates.length === 0 && <li className="p-3 text-sm text-muted-foreground">No open issues found.</li>}
                        {candidates.map((c) => (
                            <li key={c.id}>
                                <button type="button" onClick={() => setSelected(c)} className="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left text-sm hover:bg-muted/60">
                                    <span className="w-full truncate"><span className="text-muted-foreground">{c.key}</span> {c.title}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {c.assignee ? (c.assignee.id === member.id ? 'Assigned to them' : `Assigned to ${c.assignee.name}`) : 'Unassigned'} · {c.remaining_minutes === null ? 'no estimate' : `${formatHours(c.remaining_minutes)} remaining`}
                                        {c.open_planned_minutes > 0 && ` · ${formatHours(c.open_planned_minutes)} planned`}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
