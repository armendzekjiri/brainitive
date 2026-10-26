import { FeedbackStatusBadge, PortalHeading, VoteButton } from '@/components/feedback/FeedbackBits';
import PortalLayout from '@/Layouts/PortalLayout';
import type { FeedbackPortalData, FeedbackPostData, FeedbackStatus, PortalIdentityData } from '@/types';
import { Link } from '@inertiajs/react';

interface Props {
    portal: FeedbackPortalData;
    columns: { status: FeedbackStatus; label: string; posts: FeedbackPostData[] }[];
    identity: PortalIdentityData | null;
}

/** Public roadmap: Planned / Building / Released (FeedbackPortalController::roadmap). */
export default function FeedbackRoadmap({ portal, columns, identity }: Props) {
    return (
        <PortalLayout title={`Roadmap · ${portal.title}`} brand={portal.title} subtitle="Roadmap" identity={identity} width="max-w-6xl">
            <PortalHeading portal={portal} active="roadmap" />
            <div className="grid gap-4 md:grid-cols-3">
                {columns.map((column) => (
                    <section key={column.status} className="rounded-2xl border bg-muted/30 p-3" aria-label={column.label} data-testid={`roadmap-${column.status}`}>
                        <h2 className="mb-3 flex items-center justify-between px-1">
                            <FeedbackStatusBadge status={column.status} label={column.label} />
                            <span className="text-xs text-muted-foreground tabular-nums">{column.posts.length}</span>
                        </h2>
                        {column.posts.length === 0 ? (
                            <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">Nothing here yet.</p>
                        ) : (
                            <ul className="space-y-2">
                                {column.posts.map((post) => (
                                    <li key={post.number} className="flex items-start gap-3 rounded-xl border bg-card p-3">
                                        <VoteButton portal={portal} post={post} identity={identity} />
                                        <Link href={post.url} className="min-w-0 flex-1 text-sm font-medium leading-snug underline-offset-2 hover:underline [overflow-wrap:anywhere]">
                                            {post.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>
        </PortalLayout>
    );
}
