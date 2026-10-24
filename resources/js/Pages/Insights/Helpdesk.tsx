import { Link } from '@inertiajs/react';
import InsightsLayout from '@/Layouts/InsightsLayout';
import { Empty, InlineBar, Section, StatTile } from '@/components/insights/InsightsBits';
import type { InsightsHelpdesk, InsightsPageProps } from '@/types';

function formatDuration(hours: number | null): string {
    if (hours === null) return '—';
    if (hours < 48) return `${hours} h`;
    return `${Math.round((hours / 24) * 10) / 10} d`;
}

/** Insights → Helpdesk & leads (BRAI-105). Definitions: HelpdeskInsights (PHP). */
export default function InsightsHelpdeskPage(props: InsightsPageProps<InsightsHelpdesk>) {
    const { tickets, helpdesks, leads, forms } = props.insights;
    const funnel = [
        { label: 'Viewed', value: leads.views },
        { label: 'Started', value: leads.starts },
        { label: 'Submitted', value: leads.submits },
    ];

    return (
        <InsightsLayout tab="helpdesk" props={props}>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile testId="kpi-tickets-created" label="Tickets created" value={tickets.created} hint="in the period" />
                <StatTile testId="kpi-tickets-resolved" label="Tickets resolved" value={tickets.resolved} hint="in the period" />
                <StatTile testId="kpi-resolution" label="Median resolution time" value={formatDuration(tickets.median_resolution_hours)} hint="of the resolved ones" />
                <StatTile testId="kpi-tickets-open" label="Open tickets" value={tickets.open} hint="now" />
            </div>

            <Section title="Helpdesks" description="Created and resolved in the period; open now.">
                {helpdesks.length === 0 ? (
                    <Empty>No helpdesks yet.</Empty>
                ) : (
                    <div className="-mx-4 overflow-x-auto px-4">
                        <table className="w-full min-w-[560px] text-sm" aria-label="Helpdesks">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">Helpdesk</th>
                                    <th className="pb-2 font-medium">Project</th>
                                    <th className="pb-2 text-right font-medium">Created</th>
                                    <th className="pb-2 text-right font-medium">Resolved</th>
                                    <th className="pb-2 text-right font-medium">Open now</th>
                                </tr>
                            </thead>
                            <tbody>
                                {helpdesks.map((h) => (
                                    <tr key={h.id} className="border-t">
                                        <td className="py-2 pr-3"><Link href={h.url} className="font-medium hover:underline">{h.name}</Link></td>
                                        <td className="py-2 pr-3 text-muted-foreground">{h.project}</td>
                                        <td className="py-2 text-right tabular-nums">{h.created}</td>
                                        <td className="py-2 text-right tabular-nums">{h.resolved}</td>
                                        <td className="py-2 text-right tabular-nums">{h.open}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Section>

            <div className="grid gap-6 lg:grid-cols-3">
                <Section title="Lead funnel" description="Unique visits of all forms in the period (visit data is kept for 90 days)." className="lg:col-span-1">
                    <div className="space-y-3">
                        {funnel.map((step) => (
                            <div key={step.label}>
                                <div className="mb-1 flex items-baseline justify-between text-xs">
                                    <span className="font-medium">{step.label}</span>
                                    <span className="tabular-nums text-muted-foreground">
                                        <strong className="text-foreground">{step.value}</strong>
                                        {leads.views > 0 && step.label !== 'Viewed' && ` · ${Math.round((step.value / leads.views) * 1000) / 10}%`}
                                    </span>
                                </div>
                                <InlineBar value={step.value} max={leads.views} />
                            </div>
                        ))}
                        <p className="pt-1 text-sm"><strong className="tabular-nums" data-testid="kpi-leads">{leads.total}</strong> leads received</p>
                    </div>
                </Section>

                <Section title="Leads per form" description="Leads received in the period; closed = in a done column now." className="lg:col-span-2">
                    {forms.length === 0 ? (
                        <Empty>No lead forms yet.</Empty>
                    ) : (
                        <div className="-mx-4 overflow-x-auto px-4">
                            <table className="w-full min-w-[560px] text-sm" aria-label="Leads per form">
                                <thead>
                                    <tr className="text-left text-xs text-muted-foreground">
                                        <th className="pb-2 font-medium">Form</th>
                                        <th className="pb-2 text-right font-medium">Leads</th>
                                        <th className="pb-2 text-right font-medium">Closed</th>
                                        <th className="pb-2 text-right font-medium">Views</th>
                                        <th className="pb-2 text-right font-medium">Starts</th>
                                        <th className="pb-2 text-right font-medium">Submits</th>
                                        <th className="pb-2 text-right font-medium">Conversion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {forms.map((f) => (
                                        <tr key={f.id} className="border-t">
                                            <td className="py-2 pr-3">
                                                <Link href={f.url} className="font-medium hover:underline">{f.name}</Link>
                                                <div className="text-xs text-muted-foreground">{f.project}{f.status !== 'published' && ` · ${f.status}`}</div>
                                            </td>
                                            <td className="py-2 text-right tabular-nums">{f.leads}</td>
                                            <td className="py-2 text-right tabular-nums">{f.closed}</td>
                                            <td className="py-2 text-right tabular-nums">{f.views}</td>
                                            <td className="py-2 text-right tabular-nums">{f.starts}</td>
                                            <td className="py-2 text-right tabular-nums">{f.submits}</td>
                                            <td className="py-2 text-right tabular-nums">{f.conversion === null ? '—' : `${f.conversion}%`}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </InsightsLayout>
    );
}
