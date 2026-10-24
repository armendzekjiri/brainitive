import { useEffect, useState, type ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ChartColumn, Download } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { InsightsPageProps, InsightsPeriod, InsightsTab } from '@/types';

/**
 * Shell of every Workspace → Insights page (BRAI-105, workspace admins): title, tabs, the period and
 * project filters and the CSV export of the current tab. Every filter change reloads the page with
 * the new query string; the tabs keep it.
 */
const TABS: { tab: InsightsTab; route: string; label: string }[] = [
    { tab: 'overview', route: 'insights.overview', label: 'Overview' },
    { tab: 'team', route: 'insights.team', label: 'Team' },
    { tab: 'projects', route: 'insights.projects', label: 'Projects' },
    { tab: 'helpdesk', route: 'insights.helpdesk', label: 'Helpdesk & leads' },
    { tab: 'usage', route: 'insights.usage', label: 'Usage' },
];

const PERIODS: { value: InsightsPeriod; label: string }[] = [
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'last_30', label: 'Last 30 days' },
    { value: 'last_90', label: 'Last 90 days' },
    { value: 'custom', label: 'Custom' },
];

const ALL = 'all';

type Shared = Omit<InsightsPageProps<unknown>, 'insights'>;

export default function InsightsLayout({ tab, props, description, showRange = true, children }: {
    tab: InsightsTab;
    props: Shared;
    description?: ReactNode;
    /** Usage is a snapshot of now: it hides the period. */
    showRange?: boolean;
    children: ReactNode;
}) {
    const { workspace, filters, exportQuery, projectOptions } = props;
    const label = TABS.find((t) => t.tab === tab)?.label ?? 'Insights';
    const [range, setRange] = useState({ from: filters.from, to: filters.to });
    useEffect(() => setRange({ from: filters.from, to: filters.to }), [filters.from, filters.to]);

    const visit = (query: Record<string, string | number | null | undefined>) => {
        const next = { ...exportQuery, ...query };
        const clean = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        if (clean.period !== 'custom') {
            delete clean.from;
            delete clean.to;
        }
        router.get(route(`insights.${tab}`, workspace.slug), clean, { preserveScroll: true, preserveState: true });
    };

    const pickPeriod = (period: InsightsPeriod) => {
        if (period === 'custom') visit({ period, from: filters.from, to: filters.to });
        else visit({ period });
    };

    return (
        <AppLayout>
            <Head title={`${label} · Insights`} />
            <div className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <ChartColumn className="size-6 text-primary" />
                            Insights
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {description ?? <>How {workspace.name} is doing. Only workspace admins see this page.</>}
                        </p>
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <a href={route('insights.export', { workspace: workspace.slug, tab, ...exportQuery })} download>
                            <Download className="size-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <nav className="-mx-4 overflow-x-auto border-b border-border px-4 sm:mx-0 sm:px-0" aria-label="Insights">
                    <div className="flex gap-5">
                        {TABS.filter((t) => route().has(t.route)).map((t) => (
                            <Link
                                key={t.tab}
                                href={route(t.route, { workspace: workspace.slug, ...exportQuery })}
                                aria-current={t.tab === tab ? 'page' : undefined}
                                preserveScroll
                                className={cn(
                                    '-mb-px whitespace-nowrap border-b-2 pb-2 pt-1 text-sm font-medium transition-colors',
                                    t.tab === tab ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                )}
                            >
                                {t.label}
                            </Link>
                        ))}
                    </div>
                </nav>

                <div className="flex flex-wrap items-end gap-3">
                    {showRange && (
                        <div className="space-y-1.5">
                            <span className="block text-xs font-medium text-muted-foreground">Period</span>
                            <div role="group" aria-label="Period" className="inline-flex flex-wrap rounded-lg border bg-muted/40 p-0.5">
                                {PERIODS.map((p) => (
                                    <button
                                        key={p.value}
                                        type="button"
                                        aria-pressed={filters.period === p.value}
                                        onClick={() => pickPeriod(p.value)}
                                        className={cn(
                                            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                            filters.period === p.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {p.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                    {showRange && filters.period === 'custom' && (
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                visit({ period: 'custom', ...range });
                            }}
                        >
                            <label className="space-y-1.5">
                                <span className="block text-xs font-medium text-muted-foreground">From</span>
                                <Input type="date" aria-label="From" value={range.from} max={range.to} onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))} className="h-9 w-40" required />
                            </label>
                            <label className="space-y-1.5">
                                <span className="block text-xs font-medium text-muted-foreground">To</span>
                                <Input type="date" aria-label="To" value={range.to} min={range.from} onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))} className="h-9 w-40" required />
                            </label>
                            <Button type="submit" size="sm" variant="secondary" className="h-9">Apply</Button>
                        </form>
                    )}
                    <div className="space-y-1.5">
                        <span className="block text-xs font-medium text-muted-foreground">Project</span>
                        <Select value={filters.project ? String(filters.project) : ALL} onValueChange={(value) => visit({ project: value === ALL ? null : value })}>
                            <SelectTrigger aria-label="Project" className="h-9 w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All projects</SelectItem>
                                {projectOptions.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    {showRange && (
                        <p className="pb-2 text-xs text-muted-foreground" data-testid="insights-range">
                            {formatDay(filters.from)} – {formatDay(filters.to)} · {filters.timezone}
                        </p>
                    )}
                </div>

                {children}
            </div>
        </AppLayout>
    );
}

/** "Oct 1, 2026" from a Y-m-d date (no time zone shift). */
export function formatDay(day: string): string {
    const [y, m, d] = day.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
}
