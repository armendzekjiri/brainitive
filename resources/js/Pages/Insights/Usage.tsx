import InsightsLayout from '@/Layouts/InsightsLayout';
import { Empty, formatBytes, InlineBar, Section, StatTile } from '@/components/insights/InsightsBits';
import type { InsightsPageProps, InsightsUsage } from '@/types';

const ROLE_ORDER = ['owner', 'admin', 'editor', 'member', 'viewer'];

/** Insights → Usage (BRAI-105): the workspace as it is now. Definitions: UsageInsights (PHP). */
export default function InsightsUsagePage(props: InsightsPageProps<InsightsUsage>) {
    const usage = props.insights;
    const roles = Object.entries(usage.roles).sort(([a], [b]) => (ROLE_ORDER.indexOf(a) + 1 || 99) - (ROLE_ORDER.indexOf(b) + 1 || 99));
    const maxBytes = Math.max(0, ...usage.storage.map((s) => s.bytes));

    return (
        <InsightsLayout tab="usage" props={props} showRange={false} description={<>The workspace right now. The period doesn't apply here; the project filter limits storage.</>}>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <StatTile testId="usage-members" label="Members" value={usage.members} hint={roles.map(([role, n]) => `${n} ${role}`).join(' · ')} />
                <StatTile testId="usage-invites" label="Pending invitations" value={usage.pending_invites} hint="not accepted, not expired" />
                <StatTile testId="usage-projects" label="Projects" value={usage.projects} />
                <StatTile testId="usage-storage" label="Storage" value={formatBytes(usage.storage_bytes)} hint="issue + ticket attachments" />
                <StatTile testId="usage-webhooks" label="Webhooks" value={usage.webhooks} hint="outgoing, all states" />
            </div>

            <Section title="Members by role">
                <table className="w-full max-w-md text-sm" aria-label="Members by role">
                    <tbody>
                        {roles.map(([role, n]) => (
                            <tr key={role} className="border-t first:border-t-0">
                                <td className="py-2 pr-3 capitalize">{role}</td>
                                <td className="w-1/2 py-2 pr-3"><InlineBar value={n} max={usage.members} /></td>
                                <td className="py-2 text-right tabular-nums">{n}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Section>

            <Section title="Storage by project" description="Files attached to issues and tickets, including those of deleted issues still on disk.">
                {usage.storage.length === 0 ? (
                    <Empty>No projects yet.</Empty>
                ) : (
                    <div className="-mx-4 overflow-x-auto px-4">
                        <table className="w-full min-w-[560px] text-sm" aria-label="Storage by project">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">Project</th>
                                    <th className="w-1/3 pb-2 font-medium"><span className="sr-only">Share</span></th>
                                    <th className="pb-2 text-right font-medium">Files</th>
                                    <th className="pb-2 text-right font-medium">Issues</th>
                                    <th className="pb-2 text-right font-medium">Tickets</th>
                                    <th className="pb-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {usage.storage.map((s) => (
                                    <tr key={s.id} className="border-t">
                                        <td className="py-2 pr-3 font-medium">{s.name}</td>
                                        <td className="py-2 pr-3"><InlineBar value={s.bytes} max={maxBytes} /></td>
                                        <td className="py-2 text-right tabular-nums">{s.files}</td>
                                        <td className="py-2 text-right tabular-nums">{formatBytes(s.issue_bytes)}</td>
                                        <td className="py-2 text-right tabular-nums">{formatBytes(s.ticket_bytes)}</td>
                                        <td className="py-2 text-right font-medium tabular-nums">{formatBytes(s.bytes)}</td>
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
