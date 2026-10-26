import { FeedbackStatusBadge, FlashNotice, PortalNav, VoteButton, formatDate } from '@/components/feedback/FeedbackBits';
import { Button } from '@/components/ui/button';
import PortalLayout from '@/Layouts/PortalLayout';
import type { FeedbackPortalData, FeedbackPostData, FeedbackStatus, PortalIdentityData } from '@/types';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Bell, BellOff, Clock } from 'lucide-react';
import { useState } from 'react';

interface Props {
    portal: FeedbackPortalData;
    post: FeedbackPostData;
    statuses: Record<FeedbackStatus, string>;
    identity: PortalIdentityData | null;
}

/** One idea on the public feedback portal (FeedbackPortalController::show). */
export default function FeedbackShow({ portal, post, identity }: Props) {
    const [busy, setBusy] = useState(false);

    const toggleFollow = () => {
        const url = route(post.is_following ? 'feedback.unfollow' : 'feedback.follow', [portal.slug, post.number]);
        const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
        if (post.is_following) router.delete(url, options);
        else router.post(url, {}, options);
    };

    return (
        <PortalLayout title={`${post.title} · ${portal.title}`} brand={portal.title} subtitle="Feedback" identity={identity}>
            <FlashNotice />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <Link href={portal.url} className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    All ideas
                </Link>
                <PortalNav portal={portal} active="ideas" />
            </div>

            {post.is_pending && (
                <div className="mb-4 flex items-start gap-2 rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground" data-testid="feedback-pending">
                    <Clock className="mt-0.5 size-4 shrink-0" />
                    Only you can see this idea until the team has reviewed it.
                </div>
            )}

            <article className="rounded-2xl border bg-card p-4 sm:p-6">
                <div className="flex gap-4">
                    {!post.is_pending && <VoteButton portal={portal} post={post} identity={identity} size="lg" />}
                    <div className="min-w-0 flex-1">
                        <h1 className="text-xl font-bold leading-snug sm:text-2xl [overflow-wrap:anywhere]" data-testid="feedback-post-title">
                            {post.title}
                        </h1>
                        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {!post.is_pending && <FeedbackStatusBadge status={post.status} label={post.status_label} />}
                            <span>
                                {post.author_name ? `${post.author_name} · ` : ''}
                                {formatDate(post.created_at)}
                            </span>
                        </div>
                    </div>
                </div>

                {post.status_note && !post.is_pending && (
                    <div className="mt-5 rounded-xl border-l-4 border-primary bg-muted/50 px-4 py-3 text-sm">
                        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Update from the team</p>
                        <p className="whitespace-pre-line [overflow-wrap:anywhere]" data-testid="feedback-status-note">{post.status_note}</p>
                    </div>
                )}

                {post.body && <p className="mt-5 whitespace-pre-line leading-relaxed [overflow-wrap:anywhere]">{post.body}</p>}

                <div className="mt-6 flex flex-wrap items-center gap-3 border-t pt-4">
                    {identity ? (
                        <Button variant="outline" size="sm" onClick={toggleFollow} disabled={busy} aria-pressed={post.is_following}>
                            {post.is_following ? <BellOff className="size-4" /> : <Bell className="size-4" />}
                            {post.is_following ? 'Unfollow' : 'Follow'}
                        </Button>
                    ) : (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('guest.login', { return_to: window.location.pathname })}>
                                <Bell className="size-4" />
                                Sign in to vote and follow
                            </Link>
                        </Button>
                    )}
                    <p className="text-xs text-muted-foreground">
                        {post.is_following ? 'You get an email when the status changes.' : 'Follow to get an email when the status changes.'}
                    </p>
                </div>
            </article>
        </PortalLayout>
    );
}
