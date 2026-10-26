import { DeliverableStatusBadge, FlashNotice, formatDay } from '@/components/client-portal/ClientPortalBits';
import PortalLayout from '@/Layouts/PortalLayout';
import type { ClientDeliverableSummary, ClientPortalPublic, PageProps, PortalIdentityData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, FileText, GanttChart, Inbox, MessageSquare } from 'lucide-react';

interface Props {
    portal: ClientPortalPublic;
    identity: PortalIdentityData | null;
    deliverables: (ClientDeliverableSummary & { comments_count: number })[];
}

/** A client's home in the portal (ClientPortalController::show, v1.42.0 BRAI-73): what waits for review. */
export default function ClientPortalIndex({ portal, identity, deliverables }: Props) {
    const flash = (usePage().props as unknown as PageProps).flash?.success;
    const waiting = deliverables.filter((d) => d.status === 'awaiting').length;

    return (
        <PortalLayout title={portal.title} brand={portal.title} subtitle="Client portal" identity={identity} returnTo={route('client-portal.show', portal.slug, false)}>
            <div className="space-y-6">
                <FlashNotice message={flash} />
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">{portal.title}</h1>
                    {portal.welcome && <p className="mt-2 max-w-2xl whitespace-pre-line text-muted-foreground">{portal.welcome}</p>}
                </div>

                <section aria-labelledby="deliverables-heading" className="space-y-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="deliverables-heading" className="text-lg font-semibold">Deliverables</h2>
                        {deliverables.length > 0 && (
                            <p className="text-sm text-muted-foreground">
                                {waiting === 0 ? 'Nothing waits for your review.' : `${waiting} waiting for your review`}
                            </p>
                        )}
                    </div>

                    {deliverables.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center">
                            <Inbox className="size-8 text-muted-foreground" aria-hidden="true" />
                            <p className="font-medium">Nothing shared yet</p>
                            <p className="max-w-sm text-sm text-muted-foreground">When the team shares a document or a plan with you, it shows up here and you get an email.</p>
                        </div>
                    ) : (
                        <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                            {deliverables.map((d) => (
                                <li key={d.uuid}>
                                    <Link
                                        href={route('client-portal.deliverable', [portal.slug, d.uuid])}
                                        className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                                    >
                                        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                            {d.kind === 'plan' ? <GanttChart className="size-5" aria-hidden="true" /> : <FileText className="size-5" aria-hidden="true" />}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">{d.title}</span>
                                            <span className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                                <span>Version {d.version}</span>
                                                <span>{formatDay(d.published_at)}</span>
                                                {d.comments_count > 0 && (
                                                    <span className="inline-flex items-center gap-1"><MessageSquare className="size-3" aria-hidden="true" />{d.comments_count}</span>
                                                )}
                                            </span>
                                        </span>
                                        <DeliverableStatusBadge status={d.status} />
                                        <ChevronRight className="hidden size-4 shrink-0 text-muted-foreground sm:block" aria-hidden="true" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </PortalLayout>
    );
}
