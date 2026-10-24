import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import InsightsLayout from '@/Layouts/InsightsLayout';
import { Empty, formatHours, InlineBar, Legend, Section, StatTile, WeeklyChart } from '@/components/insights/InsightsBits';
import type { InsightsOverview, InsightsPageProps } from '@/types';

/** Insights → Overview (BRAI-105). Definitions: OverviewInsights (PHP). */
export default function InsightsOverviewPage(props: InsightsPageProps<InsightsOverview>) {
    const { kpis, weeks, projects, types } = props.insights;
    const maxOpen = Math.max(0, ...projects.map((p) => p.open));
    const maxType = Math.max(0, ...types.map((t) => t.created));

    return (
        <InsightsLayout tab="overview" props={props}>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <StatTile testId="kpi-created" label="Created" value={kpis.created} hint="issues in the period" />
                <StatTile testId="kpi-completed" label="Completed" value={kpis.completed} hint="issues in the period" />
                <StatTile testId="kpi-completion-rate" label="Completion rate" value={kpis.completion_rate === null ? '—' : `${kpis.completion_rate}%`} hint="of the created ones are done" />
                <StatTile testId="kpi-cycle-time" label="Median cycle time" value={kpis.median_cycle_days === null ? '—' : `${kpis.median_cycle_days} d`} hint="created → completed" />
                <StatTile testId="kpi-overdue" label="Overdue now" value={kpis.overdue} hint={`of ${kpis.open} open`} />
                <StatTile testId="kpi-hours" label="Hours logged" value={formatHours(kpis.hours)} hint="time entries" />
            </div>

            <Section
                title="Created vs completed per week"
                description="Weeks start on Monday. Completed counts issues whose completion falls in the week, whenever they were created."
                actions={<Legend items={[{ label: 'Created', color: 'var(--viz-reference)' }, { label: 'Completed', color: 'var(--viz-series-1)' }]} />}
                className="viz-root"
            >
                {weeks.some((w) => w.created || w.completed) ? <WeeklyChart weeks={weeks} /> : <Empty>No issues were created or completed in this period.</Empty>}
            </Section>

            <div className="grid gap-6 lg:grid-cols-2">
                <Section title="Open and overdue by project" description="Right now, whatever the period.">
                    {projects.length === 0 ? (
                        <Empty>No open issues.</Empty>
                    ) : (
                        <table className="w-full text-sm" aria-label="Open and overdue by project">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">Project</th>
                                    <th className="w-1/3 pb-2 font-medium"><span className="sr-only">Share</span></th>
                                    <th className="pb-2 text-right font-medium">Open</th>
                                    <th className="pb-2 text-right font-medium">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                {projects.map((p) => (
                                    <tr key={p.id} className="border-t">
                                        <td className="max-w-0 truncate py-2 pr-3">
                                            <Link href={route('projects.show', [props.workspace.slug, p.slug])} className="hover:underline">{p.name}</Link>
                                        </td>
                                        <td className="py-2 pr-3"><InlineBar value={p.open} max={maxOpen} /></td>
                                        <td className="py-2 text-right tabular-nums">{p.open}</td>
                                        <td className="py-2 text-right tabular-nums">
                                            {p.overdue > 0 ? (
                                                <span className="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                                    <AlertTriangle className="size-3.5" aria-hidden />
                                                    {p.overdue}
                                                </span>
                                            ) : 0}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Section>

                <Section title="Top issue types" description="Issues created in the period, by type; done = done now.">
                    {types.length === 0 ? (
                        <Empty>No issues were created in this period.</Empty>
                    ) : (
                        <table className="w-full text-sm" aria-label="Top issue types">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">Type</th>
                                    <th className="w-1/3 pb-2 font-medium"><span className="sr-only">Share</span></th>
                                    <th className="pb-2 text-right font-medium">Created</th>
                                    <th className="pb-2 text-right font-medium">Done</th>
                                </tr>
                            </thead>
                            <tbody>
                                {types.map((t) => (
                                    <tr key={t.name ?? '—'} className="border-t">
                                        <td className="py-2 pr-3">{t.name ?? <span className="text-muted-foreground">No type</span>}</td>
                                        <td className="py-2 pr-3"><InlineBar value={t.created} max={maxType} reference /></td>
                                        <td className="py-2 text-right tabular-nums">{t.created}</td>
                                        <td className="py-2 text-right tabular-nums">{t.completed}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Section>
            </div>
        </InsightsLayout>
    );
}
