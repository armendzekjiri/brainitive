import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PageReviewShowState } from '@/types';
import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, MessageSquareText, ShieldCheck, XCircle } from 'lucide-react';

interface Props {
    review: PageReviewShowState;
    /** hash of the draft as the editor last saved it (autosave responses keep it current) */
    draftHash: string | null;
    /** an unpublished page, or a published one with unpublished changes */
    hasSomethingToPublish: boolean;
    onRequestReview: () => void;
}

/**
 * The review state on top of a page (BRAI-80): the latest open review for editors (in review,
 * changes requested, approved, or out of date because the draft moved on), and a pointer to the
 * review for people who were asked to review this page.
 */
export default function PageReviewBanner({ review, draftHash, hasSomethingToPublish, onRequestReview }: Props) {
    const latest = review.latest;
    const stale = !!latest && !!draftHash && latest.content_hash !== draftHash;

    return (
        <>
            {review.assigned.map((assigned) => (
                <div key={assigned.uuid} className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border border-sky-500/30 bg-sky-500/5 px-4 py-3 text-sm">
                    <MessageSquareText className="size-4 text-sky-600 dark:text-sky-400 shrink-0" />
                    <span className="flex-1 min-w-[14rem]">
                        <strong>{assigned.requested_by_name}</strong> asked you to review a draft of this page.
                    </span>
                    <Button size="sm" asChild>
                        <Link href={assigned.url}>Open review</Link>
                    </Button>
                </div>
            ))}

            {latest && (() => {
                const approvedBy = latest.reviewers.filter((r) => r.active && r.decision === 'approved').map((r) => r.name);
                const changesBy = latest.reviewers.filter((r) => r.active && r.decision === 'changes_requested').map((r) => r.name);
                const waiting = latest.reviewers.filter((r) => r.active && r.decision === 'pending').map((r) => r.name);

                const tone = stale
                    ? { box: 'border-amber-500/30 bg-amber-500/5', icon: <AlertTriangle className="size-4 text-amber-600 dark:text-amber-400 shrink-0" /> }
                    : latest.status === 'approved'
                        ? { box: 'border-brand-green/30 bg-brand-green/5', icon: <CheckCircle2 className="size-4 text-brand-green shrink-0" /> }
                        : latest.status === 'changes_requested'
                            ? { box: 'border-red-500/30 bg-red-500/5', icon: <XCircle className="size-4 text-red-600 dark:text-red-400 shrink-0" /> }
                            : { box: 'border-sky-500/30 bg-sky-500/5', icon: <Clock className="size-4 text-sky-600 dark:text-sky-400 shrink-0" /> };

                return (
                    <div data-testid="page-review-banner" className={cn('mb-6 flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3 text-sm', tone.box)}>
                        {tone.icon}
                        <span className="flex-1 min-w-[14rem]">
                            {stale ? (
                                <>The draft changed after review #{latest.number} was requested, so the review no longer covers it.</>
                            ) : latest.status === 'approved' ? (
                                <>Review #{latest.number} approved by <strong>{approvedBy.join(', ')}</strong>{review.required ? ' — ready to publish.' : '.'}</>
                            ) : latest.status === 'changes_requested' ? (
                                <><strong>{changesBy.join(', ')}</strong> requested changes in review #{latest.number}.</>
                            ) : (
                                <>Review #{latest.number} in progress{waiting.length > 0 ? <> — waiting for <strong>{waiting.join(', ')}</strong></> : null}.</>
                            )}
                        </span>
                        {(stale || latest.status === 'changes_requested') && review.can_request && (
                            <Button size="sm" variant="outline" onClick={onRequestReview}>Request new review</Button>
                        )}
                        <Button size="sm" variant={stale ? 'outline' : 'default'} asChild>
                            <Link href={latest.url}>Open review</Link>
                        </Button>
                    </div>
                );
            })()}

            {!latest && review.required && review.can_request && hasSomethingToPublish && (
                <div className="mb-6 flex flex-wrap items-center gap-3 rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                    <ShieldCheck className="size-4 text-muted-foreground shrink-0" />
                    <span className="flex-1 min-w-[14rem]">This page needs an approved review before it can be published.</span>
                    <Button size="sm" variant="outline" onClick={onRequestReview}>Request review</Button>
                </div>
            )}
        </>
    );
}
