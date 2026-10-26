import AppLayout from '@/Layouts/AppLayout';
import { ProjectIcon } from '@/components/ProjectIcon';
import { DECISION_CLASSES, DECISION_LABELS, ReviewStatusBadge } from '@/components/page-review/ReviewStatusBadge';
import ReviewSnapshot from '@/components/page-review/ReviewSnapshot';
import ReviewThreads from '@/components/page-review/ReviewThreads';
import type { QuoteSelection } from '@/components/page-review/anchors';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { PageProps, PageReviewDetail, PageReviewThread, Project, Workspace } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Check, ChevronRight, Loader2, ShieldCheck, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { useFlashToast } from '@/components/automations/useFlashToast';

interface Props extends PageProps {
    workspace: Workspace;
    project: Project;
    page: { uuid: string; status: 'draft' | 'published' | 'archived'; review_required: boolean; can_open: boolean };
    review: PageReviewDetail;
    threads: PageReviewThread[];
    can: { decide: boolean; comment: boolean; cancel: boolean; request: boolean };
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/**
 * A page review (BRAI-80): the immutable snapshot of the draft, inline comments by text quote,
 * and the reviewers' decisions. Reviewers reach it even when they can't open the unpublished page.
 */
export default function PageReview({ workspace, project, page, review, threads, can }: Props) {
    const { auth, errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    useFlashToast();
    const routeParams = useMemo(() => [workspace.slug, project.slug, page.uuid] as [string, string, string], [workspace.slug, project.slug, page.uuid]);

    const [activeThread, setActiveThread] = useState<string | null>(null);
    // a click on a highlight scrolls the comment into view, not the highlight (one scroller on phones)
    const [scrollToMark, setScrollToMark] = useState(true);
    const [draft, setDraft] = useState<QuoteSelection | null>(null);
    const [unplaced, setUnplaced] = useState<Set<string>>(new Set());
    const [decisionComment, setDecisionComment] = useState(review.my_decision?.comment ?? '');
    const [deciding, setDeciding] = useState<'approved' | 'changes_requested' | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);

    const isOpen = review.status === 'pending' || review.status === 'approved' || review.status === 'changes_requested';
    const openThreads = threads.filter((t) => !t.resolved);

    // validation errors of the review actions (decide, cancel, request again) as a toast
    useEffect(() => {
        const first = errors ? Object.values(errors)[0] : undefined;
        if (first) toast.error(first);
    }, [errors]);

    const decide = (decision: 'approved' | 'changes_requested') => {
        setDeciding(decision);
        router.post(route('pages.reviews.decide', [...routeParams, review.uuid]), { decision, comment: decisionComment || null }, {
            preserveScroll: true,
            onFinish: () => setDeciding(null),
        });
    };

    const requestAgain = () => {
        router.post(route('pages.reviews.store', routeParams), {
            reviewer_ids: review.reviewers.filter((r) => r.active && r.user_id !== null && r.user_id !== auth.user.id).map((r) => r.user_id),
            message: review.message,
        });
    };

    const pageUrl = route('pages.show', routeParams);

    return (
        <AppLayout>
            <Head title={`Review: ${review.snapshot_name}`} />

            <div className="h-full overflow-y-auto bg-background">
                <div className="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
                    {/* Breadcrumbs */}
                    <nav aria-label="Breadcrumb" className="mb-4 flex flex-wrap items-center gap-1 text-sm text-muted-foreground">
                        <Link href={route('projects.show', [workspace.slug, project.slug])} className="flex items-center gap-1.5 hover:text-foreground">
                            <ProjectIcon project={project} size="xs" />
                            {project.name}
                        </Link>
                        <ChevronRight className="size-3.5" />
                        {page.can_open ? (
                            <Link href={pageUrl} className="hover:text-foreground truncate max-w-[16rem]">{review.snapshot_name}</Link>
                        ) : (
                            <span className="truncate max-w-[16rem]">{review.snapshot_name}</span>
                        )}
                        <ChevronRight className="size-3.5" />
                        <span className="text-foreground">Review #{review.number}</span>
                    </nav>

                    {/* Header */}
                    <header className="mb-5 rounded-xl border bg-card p-4 sm:p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-xl font-bold tracking-tight sm:text-2xl break-words">{review.snapshot_name}</h1>
                                    <ReviewStatusBadge status={review.status} stale={review.stale} />
                                    {page.review_required && (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground" title="Publishing this page needs an approved review">
                                            <ShieldCheck className="size-3.5" /> Review required
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Review #{review.number} requested by <span className="font-medium text-foreground">{review.requested_by.name}</span> · {formatDate(review.created_at)}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {page.can_open && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={pageUrl}><ArrowLeft className="size-4 mr-1.5" />Back to page</Link>
                                    </Button>
                                )}
                                {can.cancel && (
                                    <Button variant="ghost" size="sm" onClick={() => setCancelOpen(true)}>Cancel review</Button>
                                )}
                            </div>
                        </div>

                        {review.message && (
                            <p className="mt-3 whitespace-pre-wrap break-words rounded-lg bg-muted/50 px-3 py-2 text-sm">{review.message}</p>
                        )}

                        {/* Reviewers */}
                        <ul aria-label="Reviewers" className="mt-4 flex flex-wrap gap-2">
                            {review.reviewers.map((reviewer) => (
                                <li
                                    key={reviewer.id}
                                    className={cn('rounded-lg border px-3 py-1.5 text-sm', !reviewer.active && 'opacity-60')}
                                    title={reviewer.active ? undefined : 'No longer in this project — not counted'}
                                >
                                    <span className={cn('font-medium', !reviewer.active && 'line-through')}>{reviewer.name}</span>
                                    <span className={cn('ml-2 text-xs', DECISION_CLASSES[reviewer.decision])}>{DECISION_LABELS[reviewer.decision]}</span>
                                    {reviewer.comment && <p className="mt-0.5 max-w-xs whitespace-pre-wrap break-words text-xs text-muted-foreground">{reviewer.comment}</p>}
                                </li>
                            ))}
                        </ul>

                        {review.stale && isOpen && (
                            <div className="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-sm">
                                <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                <span className="flex-1 min-w-[14rem]">
                                    The page's draft changed after this review was requested. This review shows the version that was sent; it can't be approved any more.
                                </span>
                                {can.request && (
                                    <Button size="sm" variant="outline" onClick={requestAgain}>Request a new review</Button>
                                )}
                            </div>
                        )}
                        {!isOpen && (
                            <p className="mt-4 rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                                {review.status === 'published' && 'This review was approved and the page was published.'}
                                {review.status === 'cancelled' && 'This review was cancelled.'}
                                {review.status === 'superseded' && 'A newer review replaced this one.'}
                                {review.closed_at ? ` (${formatDate(review.closed_at)})` : ''}
                            </p>
                        )}
                    </header>

                    <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                        {/* Snapshot */}
                        <div className="min-w-0 space-y-4">
                            <div className="rounded-xl border bg-card px-5 py-6 sm:px-10 sm:py-8">
                                <ReviewSnapshot
                                    html={review.snapshot_html}
                                    threads={openThreads}
                                    activeThread={activeThread}
                                    scrollToActive={scrollToMark}
                                    onSelectThread={(uuid) => {
                                        setScrollToMark(false);
                                        setActiveThread(uuid);
                                        document.querySelector(`[data-thread-card="${CSS.escape(uuid)}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                                    }}
                                    onComment={can.comment ? setDraft : null}
                                    onUnplaced={setUnplaced}
                                />
                            </div>

                            {/* Decision */}
                            {can.decide && (
                                <section aria-label="Your decision" className="rounded-xl border bg-card p-4 space-y-3">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <h2 className="text-sm font-semibold">Your decision</h2>
                                        {review.my_decision && review.my_decision.decision !== 'pending' && (
                                            <span className={cn('text-xs', DECISION_CLASSES[review.my_decision.decision])}>
                                                You chose: {DECISION_LABELS[review.my_decision.decision]}
                                            </span>
                                        )}
                                    </div>
                                    <Textarea
                                        value={decisionComment}
                                        onChange={(e) => setDecisionComment(e.target.value)}
                                        placeholder="Summary for the author (optional)"
                                        aria-label="Decision comment"
                                        rows={2}
                                        maxLength={5000}
                                    />
                                    {review.stale && (
                                        <p className="text-xs text-muted-foreground">The draft changed after this review was requested, so this version can't be approved. You can still request changes.</p>
                                    )}
                                    <div className="flex flex-wrap justify-end gap-2">
                                        <Button variant="outline" onClick={() => decide('changes_requested')} disabled={deciding !== null} className="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                            {deciding === 'changes_requested' ? <Loader2 className="size-4 mr-1.5 animate-spin" /> : <XCircle className="size-4 mr-1.5" />}
                                            Request changes
                                        </Button>
                                        <Button onClick={() => decide('approved')} disabled={deciding !== null || review.stale}>
                                            {deciding === 'approved' ? <Loader2 className="size-4 mr-1.5 animate-spin" /> : <Check className="size-4 mr-1.5" />}
                                            Approve
                                        </Button>
                                    </div>
                                </section>
                            )}
                        </div>

                        {/* Comments */}
                        <aside className="min-w-0 lg:sticky lg:top-4 lg:self-start lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
                            <ReviewThreads
                                threads={threads}
                                reviewUuid={review.uuid}
                                routeParams={routeParams}
                                canComment={can.comment}
                                draft={draft}
                                onDraftDone={() => setDraft(null)}
                                activeThread={activeThread}
                                onSelectThread={(uuid) => {
                                    setScrollToMark(true);
                                    setActiveThread(uuid);
                                }}
                                unplaced={unplaced}
                            />
                        </aside>
                    </div>
                </div>
            </div>

            <AlertDialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Cancel this review?</AlertDialogTitle>
                        <AlertDialogDescription>Reviewers can no longer approve it. Comments stay on the page's review history.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep it</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => router.post(route('pages.reviews.cancel', [...routeParams, review.uuid]), {}, { preserveScroll: true, onFinish: () => setCancelOpen(false) })}
                        >
                            Cancel review
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
