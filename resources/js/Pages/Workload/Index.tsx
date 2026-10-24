import AppLayout from '@/Layouts/AppLayout';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { CapacityDialog } from '@/components/workload/CapacityDialog';
import { WorkloadReview } from '@/components/workload/WorkloadReview';
import { WorkloadWeekSheet, type WorkloadUrls } from '@/components/workload/WorkloadWeekSheet';
import { cellSummary, formatHours, loadWidth, weekRange } from '@/lib/workload';
import { cn } from '@/lib/utils';
import type { Project, WorkloadCell, WorkloadMember, WorkloadReviewEntry, WorkloadTeamWeek, WorkloadWeek, Workspace } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, Gauge, Info, Pencil } from 'lucide-react';
import { useState } from 'react';

interface Props {
    workspace: Workspace;
    project: Project;
    from: string;
    span: number;
    spans: number[];
    previousFrom: string;
    nextFrom: string;
    currentWeek: string;
    timezone: string;
    canPlan: boolean;
    scope: 'project';
    weeks: WorkloadWeek[];
    members: WorkloadMember[];
    team: WorkloadTeamWeek[];
    review: WorkloadReviewEntry[];
}

const BAR: Record<WorkloadCell['state'], string> = {
    ok: 'bg-sky-600 dark:bg-sky-500',
    over: 'bg-red-600 dark:bg-red-500',
    unavailable: 'bg-muted-foreground/40',
    unset: 'bg-muted-foreground/40',
};

const TEXT: Record<WorkloadCell['state'], string> = {
    ok: 'text-muted-foreground',
    over: 'font-medium text-red-600 dark:text-red-400',
    unavailable: 'text-muted-foreground',
    unset: 'text-muted-foreground italic',
};

const capacityText = (m: WorkloadMember) => (m.capacity_minutes === null ? 'Capacity not set' : `${formatHours(m.capacity_minutes)} / week`);

function initials(name: string) {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
}

/**
 * Workload planning (BRAI-75): who has room in the coming weeks — declared capacity against
 * explicitly planned hours, this project only.
 */
export default function WorkloadIndex(props: Props) {
    const { workspace, project, from, span, spans, previousFrom, nextFrom, currentWeek, timezone, canPlan, weeks, members, team, review } = props;
    const [open, setOpen] = useState<{ memberId: number; week: string } | null>(null);
    const [capacityFor, setCapacityFor] = useState<number | null>(null);

    const params = { workspace: workspace.slug, project: project.slug };
    const urls: WorkloadUrls = {
        capacity: route('workload.capacity.update', params),
        override: route('workload.overrides.update', params),
        allocation: route('workload.allocations.update', params),
        items: route('workload.items', params),
        destroy: (id: number) => route('workload.allocations.destroy', { ...params, allocation: id }),
    };
    const go = (query: { from?: string; weeks?: number }) => router.get(route('workload.index', { ...params, from: query.from ?? from, weeks: query.weeks ?? span }), {}, { preserveScroll: true });

    // the sheet always reads the latest props (after a save the page reloads them)
    const openMember = open ? members.find((m) => m.id === open.memberId) : undefined;
    const openWeek = open ? weeks.find((w) => w.start === open.week) : undefined;
    const openCell = openMember && open ? openMember.cells.find((c) => c.week === open.week) : undefined;
    const capacityMember = capacityFor !== null ? members.find((m) => m.id === capacityFor) ?? null : null;

    const first = weeks[0];
    const last = weeks[weeks.length - 1];

    return (
        <AppLayout>
            <Head title={`Workload · ${project.name}`} />
            <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <ProjectIcon project={project} size="md" />
                    <div className="min-w-0 flex-1">
                        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                            <Gauge className="size-5" /> Workload
                            <Badge variant="secondary" className="font-normal" data-testid="workload-scope">This project only</Badge>
                        </h1>
                        <p className="text-sm text-muted-foreground">Declared availability against planned hours, per person and week.</p>
                    </div>
                </div>

                <p className="mb-4 flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                    <Info className="mt-0.5 size-4 shrink-0" />
                    <span>
                        Totals include <strong className="font-medium text-foreground">{project.name}</strong> only — people may have work in other projects that isn’t shown here.
                        Weeks start on Monday; “this week” follows your time zone ({timezone}).
                        {!canPlan && ' Project admins plan capacity and hours; you can view them.'}
                    </span>
                </p>

                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" aria-label="Earlier weeks" onClick={() => go({ from: previousFrom })}><ChevronLeft className="size-4" /></Button>
                        <Button variant="outline" size="sm" onClick={() => go({ from: currentWeek })} disabled={from === currentWeek}>This week</Button>
                        <Button variant="outline" size="icon" aria-label="Later weeks" onClick={() => go({ from: nextFrom })}><ChevronRight className="size-4" /></Button>
                    </div>
                    {first && last && (
                        <span className="text-sm font-medium">
                            {first.label} {first.year !== last.year ? first.year : ''} – {last.label} {last.year}
                            <span className="ml-1 font-normal text-muted-foreground">({weekRange({ start: first.start, end: last.end })})</span>
                        </span>
                    )}
                    <div className="ml-auto">
                        <Select value={String(span)} onValueChange={(v) => go({ weeks: Number(v) })}>
                            <SelectTrigger className="h-9 w-[120px]" aria-label="Weeks shown"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {spans.map((s) => <SelectItem key={s} value={String(s)}>{s} weeks</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full min-w-[720px] border-collapse text-sm" aria-label="Workload per person and week">
                        <thead>
                            <tr className="border-b bg-muted/40">
                                <th scope="col" className="sticky left-0 z-10 w-56 min-w-[11rem] bg-muted px-3 py-2 text-left font-medium">Person</th>
                                {weeks.map((w) => (
                                    <th key={w.start} scope="col" className={cn('px-2 py-2 text-left font-medium', w.is_current && 'bg-sky-50 dark:bg-sky-950/40')}>
                                        <div className="flex items-center gap-1.5">
                                            {w.label}
                                            {w.is_current && <Badge className="h-4 px-1 text-[10px]">Now</Badge>}
                                        </div>
                                        <div className="text-xs font-normal text-muted-foreground">{weekRange(w)}</div>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((m) => (
                                <tr key={m.id} className="border-b last:border-b-0" data-testid={`workload-row-${m.id}`}>
                                    <th scope="row" className="sticky left-0 z-10 bg-card px-3 py-2 text-left align-top font-normal">
                                        <div className="flex items-center gap-2">
                                            {m.avatar_display ? (
                                                <img src={m.avatar_display} alt="" className="size-7 shrink-0 rounded-full object-cover" />
                                            ) : (
                                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">{initials(m.name)}</span>
                                            )}
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">{m.name}</div>
                                                {canPlan ? (
                                                    <button type="button" onClick={() => setCapacityFor(m.id)} className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" aria-label={`Edit weekly capacity of ${m.name}: ${capacityText(m)}`}>
                                                        {capacityText(m)} <Pencil className="size-3" />
                                                    </button>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">{capacityText(m)}</span>
                                                )}
                                            </div>
                                        </div>
                                        <div className="mt-1.5 text-xs text-muted-foreground" data-testid="workload-open-work">
                                            {m.work.estimated_items + m.work.unestimated_items === 0 ? 'No open issues assigned' : (
                                                <>
                                                    {m.work.estimated_items > 0 && <span>{formatHours(m.work.remaining_minutes)} left on {m.work.estimated_items} estimated</span>}
                                                    {m.work.estimated_items > 0 && m.work.unestimated_items > 0 && ' · '}
                                                    {m.work.unestimated_items > 0 && <span className="text-amber-700 dark:text-amber-300">{m.work.unestimated_items} without estimate</span>}
                                                    {m.work.unplanned_minutes > 0 && <span className="block">{formatHours(m.work.unplanned_minutes)} not planned yet</span>}
                                                </>
                                            )}
                                        </div>
                                    </th>
                                    {m.cells.map((cell, i) => {
                                        const w = weeks[i]!;
                                        return (
                                            <td key={cell.week} className={cn('p-1.5 align-top', w.is_current && 'bg-sky-50/60 dark:bg-sky-950/20')}>
                                                <button
                                                    type="button"
                                                    onClick={() => setOpen({ memberId: m.id, week: cell.week })}
                                                    aria-label={`${m.name}, ${w.label}: ${formatHours(cell.allocated_minutes)} planned, ${cellSummary(cell)}`}
                                                    data-state={cell.state}
                                                    className={cn(
                                                        'w-full rounded-md border px-2 py-1.5 text-left transition-colors hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                        cell.state === 'over' && 'border-red-300 bg-red-50/70 dark:border-red-500/40 dark:bg-red-950/30',
                                                        cell.state === 'unset' && 'border-dashed',
                                                        cell.state === 'unavailable' && 'bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,hsl(var(--muted))_6px,hsl(var(--muted))_8px)]',
                                                    )}
                                                >
                                                    <div className="flex items-baseline justify-between gap-1 tabular-nums">
                                                        <span className="font-medium">{formatHours(cell.allocated_minutes)}</span>
                                                        <span className="text-xs text-muted-foreground">/ {cell.capacity_minutes === null ? '—' : formatHours(cell.capacity_minutes)}</span>
                                                    </div>
                                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                                        <div className={cn('h-full rounded-full', BAR[cell.state])} style={{ width: `${loadWidth(cell)}%` }} />
                                                    </div>
                                                    <div className={cn('mt-1 truncate text-[11px]', TEXT[cell.state])}>
                                                        {cellSummary(cell)}
                                                    </div>
                                                    {(cell.unestimated_minutes > 0 || cell.capacity_source === 'override') && (
                                                        <div className="mt-0.5 truncate text-[11px] text-muted-foreground">
                                                            {cell.capacity_source === 'override' && (cell.override_note || 'Adjusted week')}
                                                            {cell.capacity_source === 'override' && cell.unestimated_minutes > 0 && ' · '}
                                                            {cell.unestimated_minutes > 0 && `${formatHours(cell.unestimated_minutes)} unestimated`}
                                                        </div>
                                                    )}
                                                </button>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t bg-muted/40 text-xs text-muted-foreground">
                                <th scope="row" className="sticky left-0 z-10 bg-muted px-3 py-2 text-left font-medium">Project total</th>
                                {team.map((t) => (
                                    <td key={t.week} className="px-2 py-2 tabular-nums">
                                        <div><span className="font-medium text-foreground">{formatHours(t.allocated_minutes)}</span> / {formatHours(t.capacity_minutes)}</div>
                                        {(t.over > 0 || t.unset > 0) && (
                                            <div>
                                                {t.over > 0 && <span className="text-red-600 dark:text-red-400">{t.over} over</span>}
                                                {t.over > 0 && t.unset > 0 && ' · '}
                                                {t.unset > 0 && `${t.unset} not set`}
                                            </div>
                                        )}
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div className="mt-6 space-y-4">
                    <WorkloadReview review={review} canPlan={canPlan} urls={urls} />

                    <Collapsible className="rounded-lg border">
                        <CollapsibleTrigger className="group flex w-full items-center justify-between px-4 py-3 text-sm font-medium">
                            How it’s calculated
                            <ChevronDown className="size-4 transition-transform group-data-[state=open]:rotate-180" />
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <ul className="list-disc space-y-1.5 px-4 pb-4 pl-8 text-sm text-muted-foreground">
                                <li><strong className="text-foreground">Load</strong> = planned hours ÷ available hours in that week. Over capacity shows the hours above what’s available.</li>
                                <li><strong className="text-foreground">Available</strong> = the person’s weekly capacity for this project, or the hours set for that week (leave = 0h). “Capacity not set” means nobody declared it — it’s not the same as 0h.</li>
                                <li><strong className="text-foreground">Planned hours</strong> are only what a planner put into a week. Nothing is spread over weeks automatically.</li>
                                <li><strong className="text-foreground">Remaining estimate</strong> = original estimate − logged time (never below 0). It’s offered as a suggestion when planning; logged time is subtracted once and never from planned hours.</li>
                                <li><strong className="text-foreground">Issues without estimate</strong> are listed separately and never counted as 0h of work.</li>
                                <li><strong className="text-foreground">Parents and subtasks</strong>: plan an issue or its subtasks, not both, so the same work isn’t counted twice. A parent whose subtasks have estimates is counted through them.</li>
                                <li><strong className="text-foreground">Done issues</strong> count up to the week they were finished. Deleted issues don’t count.</li>
                                <li>When an issue’s estimate, assignee or status changes, planned hours stay as they are and show up under “Needs review”.</li>
                                <li>Project and workspace admins plan; everyone in the project can view.</li>
                            </ul>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </div>

            {openMember && openWeek && openCell && (
                <WorkloadWeekSheet member={openMember} week={openWeek} cell={openCell} canPlan={canPlan} urls={urls} onClose={() => setOpen(null)} />
            )}
            {canPlan && capacityMember && (
                <CapacityDialog key={capacityMember.id} member={capacityMember} url={urls.capacity} onOpenChange={(o) => !o && setCapacityFor(null)} />
            )}
        </AppLayout>
    );
}
