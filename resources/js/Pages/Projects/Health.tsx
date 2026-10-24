import { HealthReportEditor } from '@/components/health/HealthReportEditor';
import { HealthReportSheet } from '@/components/health/HealthReportSheet';
import {
    IssueRow, METRIC_DEFINITIONS, METRIC_LABELS, StatusBadge, formatDateTime, formatDay,
} from '@/components/health/HealthBits';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { HealthItemRef, HealthMetricKey, HealthReportDetail, HealthReportSummary, Project, ProjectHealthData, Workspace } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Flag, HeartPulse, Info, Link2, RefreshCw, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    workspace: Workspace;
    project: Project;
    health: ProjectHealthData;
    reports: HealthReportSummary[];
    openReport: HealthReportDetail | null;
    aiConfigured: boolean;
    canCreate: boolean;
    timezones: string[];
}

type Tone = 'neutral' | 'warn' | 'bad';

const CARDS: { key: HealthMetricKey; hint?: (h: ProjectHealthData) => string | null; tone?: (v: number) => Tone }[] = [
    { key: 'open', hint: (h) => (h.values.completed !== null ? `${h.values.completed} completed in total` : null) },
    { key: 'completed_in_period', hint: (h) => (h.values.created_in_period !== null ? `${h.values.created_in_period} created in period` : null) },
    { key: 'overdue', tone: (v) => (v > 0 ? 'bad' : 'neutral'), hint: (h) => (h.values.no_due_date ? `${h.values.no_due_date} open without due date` : null) },
    { key: 'due_soon', tone: (v) => (v > 0 ? 'warn' : 'neutral') },
    { key: 'unassigned', tone: (v) => (v > 0 ? 'warn' : 'neutral') },
    { key: 'blocked', tone: (v) => (v > 0 ? 'bad' : 'neutral') },
];

const TONES: Record<Tone, string> = {
    neutral: 'text-foreground',
    warn: 'text-amber-600 dark:text-amber-400',
    bad: 'text-red-600 dark:text-red-400',
};

export default function ProjectHealth({ workspace, project, health, reports, openReport, aiConfigured, canCreate, timezones }: Props) {
    const [from, setFrom] = useState(health.period.from);
    const [to, setTo] = useState(health.period.to);
    const [tz, setTz] = useState(health.timezone);
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        setFrom(health.period.from);
        setTo(health.period.to);
        setTz(health.timezone);
    }, [health.period.from, health.period.to, health.timezone]);

    const query = { tz: health.timezone, from: health.period.from, to: health.period.to };
    const pageUrl = (extra: Record<string, string | number> = {}) => route('projects.health', { workspace: workspace.slug, project: project.slug, ...query, ...extra });

    const apply = () => router.get(route('projects.health', { workspace: workspace.slug, project: project.slug, tz, from, to }), {}, { preserveScroll: true, preserveState: true });
    const refresh = () => router.reload({ only: ['health'], onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) });
    const openSaved = (id: number) => router.get(pageUrl({ report: id }), {}, { only: ['openReport'], preserveScroll: true, preserveState: true });
    const closeSaved = () => router.get(pageUrl(), {}, { only: ['openReport'], preserveScroll: true, preserveState: true });

    const value = (key: HealthMetricKey) => health.values[key];

    return (
        <AppLayout>
            <Head title={`Health · ${project.name}`} />
            <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-center gap-3">
                    <ProjectIcon project={project} size="md" />
                    <div className="min-w-0 flex-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold"><HeartPulse className="size-5" /> Project health</h1>
                        <p className="text-sm text-muted-foreground">Live numbers from this project’s boards and plans, with the issues behind them.</p>
                    </div>
                </div>

                {/* period, time zone, as-of */}
                <Card>
                    <CardContent className="flex flex-col gap-4 p-4 lg:flex-row lg:items-end lg:justify-between">
                        <form className="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-end" onSubmit={(e) => { e.preventDefault(); apply(); }}>
                            <div className="space-y-1">
                                <Label htmlFor="health-from" className="text-xs">From</Label>
                                <Input id="health-from" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} className="h-9 sm:w-40" />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="health-to" className="text-xs">To</Label>
                                <Input id="health-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} className="h-9 sm:w-40" />
                            </div>
                            <div className="col-span-2 space-y-1 sm:col-span-1">
                                <Label htmlFor="health-tz" className="text-xs">Time zone</Label>
                                <Select value={tz} onValueChange={setTz}>
                                    <SelectTrigger id="health-tz" className="h-9 sm:w-56"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {[...new Set([...timezones, Intl.DateTimeFormat().resolvedOptions().timeZone])].filter(Boolean).map((zone) => (
                                            <SelectItem key={zone} value={zone}>{zone}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button type="submit" variant="secondary" className="col-span-2 h-9 sm:col-span-1">Apply</Button>
                        </form>
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <span data-testid="health-as-of">
                                Data as of <time dateTime={health.as_of} className="font-medium text-foreground">{formatDateTime(health.as_of, health.timezone)}</time> ({health.timezone})
                            </span>
                            <Button variant="ghost" size="icon" className="size-8" onClick={refresh} aria-label="Refresh numbers" disabled={refreshing}>
                                <RefreshCw className={cn('size-4', refreshing && 'animate-spin')} />
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* metric cards */}
                <section aria-label="Metrics" className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    {CARDS.map(({ key, hint, tone }) => {
                        const v = value(key);
                        const note = v === null ? health.unknown[key] : hint?.(health);
                        return (
                            <div key={key} role="group" aria-label={METRIC_LABELS[key]} className="rounded-xl border bg-card p-4">
                                <p className="text-xs font-medium text-muted-foreground">{METRIC_LABELS[key]}</p>
                                {v === null
                                    ? <p data-testid="metric-value" className="mt-1 text-2xl font-semibold text-muted-foreground">Unknown</p>
                                    : <p data-testid="metric-value" className={cn('mt-1 text-2xl font-semibold tabular-nums', TONES[tone?.(v) ?? 'neutral'])}>{v}</p>}
                                {note && <p data-testid="metric-note" className="mt-1 text-xs text-muted-foreground">{note}</p>}
                            </div>
                        );
                    })}
                </section>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="min-w-0 space-y-6">
                        <IssueLists health={health} />
                        <Milestones health={health} />
                    </div>

                    <Card className="h-fit">
                        <CardHeader className="pb-3"><CardTitle className="text-base">Status updates</CardTitle></CardHeader>
                        <CardContent>
                            {reports.length === 0
                                ? <p className="text-sm text-muted-foreground">No status updates yet.{canCreate ? ' Write the first one below.' : ''}</p>
                                : (
                                    <ul aria-label="Saved status updates" className="-mx-2 space-y-1">
                                        {reports.map((r) => (
                                            <li key={r.id}>
                                                <button type="button" onClick={() => openSaved(r.id)} className="w-full rounded-md px-2 py-2 text-left hover:bg-muted">
                                                    <span className="flex items-center justify-between gap-2">
                                                        <StatusBadge status={r.status} />
                                                        <span className="text-xs text-muted-foreground">{formatDateTime(r.created_at, health.timezone)}</span>
                                                    </span>
                                                    <span className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                        {r.author ?? 'Former member'}
                                                        {r.ai_assisted && <><span aria-hidden>·</span><Sparkles className="size-3" /> AI-assisted</>}
                                                    </span>
                                                    {r.summary && <span className="mt-1 line-clamp-2 block text-sm">{r.summary}</span>}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                        </CardContent>
                    </Card>
                </div>

                {canCreate
                    ? <HealthReportEditor workspace={workspace} project={project} health={health} aiConfigured={aiConfigured} />
                    : <p className="text-sm text-muted-foreground">Project editors and admins write status updates.</p>}

                <Collapsible>
                    <CollapsibleTrigger className="group flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ChevronRight className="size-4 transition-transform group-data-[state=open]:rotate-90" />
                        <Info className="size-4" /> How these numbers are counted
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <dl className="mt-3 grid gap-3 rounded-xl border bg-card p-4 text-sm sm:grid-cols-2">
                            {METRIC_DEFINITIONS.map((d) => (
                                <div key={d.term}>
                                    <dt className="font-medium">{d.term}</dt>
                                    <dd className="text-muted-foreground">{d.text}</dd>
                                </div>
                            ))}
                        </dl>
                    </CollapsibleContent>
                </Collapsible>
            </div>

            <HealthReportSheet workspace={workspace} project={project} report={openReport} query={query} onClose={closeSaved} />
        </AppLayout>
    );
}

function IssueLists({ health }: { health: ProjectHealthData }) {
    const { lists, values, unknown, today } = health;
    const tabs: { key: 'overdue' | 'due_soon' | 'blocked' | 'unassigned' | 'completed'; label: string; count: number | null; empty: string }[] = [
        { key: 'overdue', label: 'Overdue', count: values.overdue, empty: 'Nothing overdue.' },
        { key: 'due_soon', label: 'Due soon', count: values.due_soon, empty: 'Nothing due in the next 7 days.' },
        { key: 'blocked', label: 'Blocked', count: values.blocked, empty: 'No open issue is blocked by an open issue.' },
        { key: 'unassigned', label: 'Unassigned', count: values.unassigned, empty: 'Every open issue has an assignee.' },
        { key: 'completed', label: 'Completed', count: values.completed_in_period, empty: 'Nothing completed in this period.' },
    ];

    const more = (count: number | null, shown: number) => count !== null && count > shown && (
        <p className="border-t px-3 py-2 text-xs text-muted-foreground">Showing {shown} of {count}.</p>
    );

    return (
        <Card>
            <Tabs defaultValue="overdue">
                <CardHeader className="pb-0">
                    <div className="-mx-1 overflow-x-auto px-1 pb-1">
                        <TabsList>
                            {tabs.map((t) => <TabsTrigger key={t.key} value={t.key}>{t.label} ({t.count ?? '?'})</TabsTrigger>)}
                        </TabsList>
                    </div>
                </CardHeader>
                <CardContent className="pt-3">
                    {tabs.map((t) => (
                        <TabsContent key={t.key} value={t.key} className="mt-0">
                            {t.count === null
                                ? <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">Unknown — {unknown[t.key === 'completed' ? 'completed_in_period' : t.key] ?? 'not enough data.'}</p>
                                : t.key === 'blocked'
                                    ? lists.blocked.length === 0
                                        ? <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">{t.empty}</p>
                                        : (
                                            <ul className="divide-y rounded-lg border">
                                                {lists.blocked.map((b) => (
                                                    <IssueRow key={b.item.ref} item={b.item} today={today} extra={
                                                        <span className="flex w-full flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                                            <Link2 className="size-3" /> blocked by
                                                            {b.blockers.map((x: HealthItemRef) => (
                                                                <Link key={x.ref} href={x.url} className="rounded bg-muted px-1.5 py-0.5 hover:underline">
                                                                    {x.key}{x.external ? ' (other project)' : ''}
                                                                </Link>
                                                            ))}
                                                        </span>
                                                    } />
                                                ))}
                                                {more(t.count, lists.blocked.length)}
                                            </ul>
                                        )
                                    : lists[t.key].length === 0
                                        ? <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">{t.empty}</p>
                                        : (
                                            <ul className="divide-y rounded-lg border">
                                                {lists[t.key].map((item) => <IssueRow key={item.ref} item={item} today={t.key === 'completed' ? undefined : today} />)}
                                                {more(t.count, lists[t.key].length)}
                                            </ul>
                                        )}
                        </TabsContent>
                    ))}
                </CardContent>
            </Tabs>
        </Card>
    );
}

function Milestones({ health }: { health: ProjectHealthData }) {
    const { values, unknown, lists } = health;
    const known = values.milestones_upcoming !== null;

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base"><Flag className="size-4" /> Plan milestones</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {!known
                    ? <p className="text-sm text-muted-foreground">Not available — {unknown.milestones_upcoming}</p>
                    : (
                        <>
                            <p className="text-sm text-muted-foreground">
                                {values.milestones_upcoming} upcoming in the next 30 days · {values.milestones_missed} missed ·{' '}
                                {values.plan_rows_open} open plan row{values.plan_rows_open === 1 ? '' : 's'} not on a board ({values.plan_rows_late} late)
                            </p>
                            {lists.milestones.length === 0
                                ? <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">No open milestones in the next 30 days.</p>
                                : (
                                    <ul className="divide-y rounded-lg border" aria-label="Milestones">
                                        {lists.milestones.map((m) => (
                                            <li key={`${m.plan_id}-${m.bar}`} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 text-sm">
                                                <Link href={m.url} className="min-w-0 flex-1 hover:underline">
                                                    <span className="break-words">{m.name}</span>
                                                    <span className="ml-2 text-xs text-muted-foreground">{m.plan}</span>
                                                </Link>
                                                <span className={cn('text-xs', m.state === 'missed' ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted-foreground')}>
                                                    {m.state === 'missed' ? 'Missed · ' : ''}{formatDay(m.date)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                        </>
                    )}
            </CardContent>
        </Card>
    );
}
