import { Link } from '@inertiajs/react';
import { HeartPulse } from 'lucide-react';
import InsightsLayout from '@/Layouts/InsightsLayout';
import { StatusBadge } from '@/components/health/HealthBits';
import { Empty, formatHours, fullTime, Section, timeAgo } from '@/components/insights/InsightsBits';
import { cn } from '@/lib/utils';
import type { InsightsPageProps, InsightsProjectRow } from '@/types';

/** Insights → Projects (BRAI-105). Definitions: ProjectInsights (PHP), same counting as project health. */
export default function InsightsProjectsPage(props: InsightsPageProps<{ projects: InsightsProjectRow[] }>) {
    const { projects } = props.insights;

    return (
        <InsightsLayout tab="projects" props={props}>
            <Section
                title="Projects"
                description="Health is the status of the latest saved health report. Open and overdue are now; done and hours count in the period. Last activity = the latest change of an issue."
            >
                {projects.length === 0 ? (
                    <Empty>No projects yet.</Empty>
                ) : (
                    <div className="-mx-4 overflow-x-auto px-4">
                        <table className="w-full min-w-[720px] text-sm" aria-label="Projects">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">Project</th>
                                    <th className="pb-2 font-medium">Health</th>
                                    <th className="pb-2 text-right font-medium">Open</th>
                                    <th className="pb-2 text-right font-medium">Overdue</th>
                                    <th className="pb-2 text-right font-medium">Done in period</th>
                                    <th className="pb-2 text-right font-medium">Hours</th>
                                    <th className="pb-2 pl-3 font-medium">Last activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                {projects.map((p) => (
                                    <tr key={p.id} className="border-t">
                                        <td className="py-2 pr-3">
                                            <Link href={p.url} className="font-medium hover:underline">{p.name}</Link>
                                            {p.key && <span className="ml-2 text-xs text-muted-foreground">{p.key}</span>}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Link href={p.health_url} className="inline-flex items-center gap-1.5" title={p.health_at ? `Reported ${fullTime(p.health_at)}` : 'Open project health'}>
                                                {p.health ? <StatusBadge status={p.health} /> : (
                                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                                                        <HeartPulse className="size-3.5" /> No report
                                                    </span>
                                                )}
                                            </Link>
                                        </td>
                                        <td className="py-2 text-right tabular-nums">{p.open}</td>
                                        <td className={cn('py-2 text-right tabular-nums', p.overdue > 0 && 'font-medium text-amber-700 dark:text-amber-400')}>{p.overdue}</td>
                                        <td className="py-2 text-right tabular-nums">{p.done_in_range}</td>
                                        <td className="py-2 text-right tabular-nums">{formatHours(p.hours)}</td>
                                        <td className="py-2 pl-3 text-muted-foreground" title={fullTime(p.last_activity_at)}>{timeAgo(p.last_activity_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Section>
        </InsightsLayout>
    );
}
