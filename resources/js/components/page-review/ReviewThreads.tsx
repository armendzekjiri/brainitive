import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { PageReviewThread } from '@/types';
import { router } from '@inertiajs/react';
import { CheckCircle2, ChevronDown, ChevronRight, Loader2, MessageSquare, RotateCcw, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import type { QuoteSelection } from './anchors';

interface Props {
    threads: PageReviewThread[];
    reviewUuid: string;
    routeParams: [string, string, string];
    canComment: boolean;
    /** a selection waiting for its first comment */
    draft: QuoteSelection | null;
    onDraftDone: () => void;
    activeThread: string | null;
    onSelectThread: (uuid: string | null) => void;
    /** threads the snapshot couldn't place on this rendering */
    unplaced: Set<string>;
}

function formatTime(value: string | null): string {
    if (!value) return '';
    return new Date(value).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * Review comments (BRAI-80): inline threads (anchored by text quote), page-level comments, replies,
 * resolve / reopen. Outdated threads (their text is no longer in this snapshot) stay readable but
 * never attach to other text.
 */
export default function ReviewThreads({ threads, reviewUuid, routeParams, canComment, draft, onDraftDone, activeThread, onSelectThread, unplaced }: Props) {
    const [showResolved, setShowResolved] = useState(false);
    const [pageComment, setPageComment] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const open = threads.filter((t) => !t.resolved);
    const resolved = threads.filter((t) => t.resolved);

    const post = (data: Record<string, string | null>, done: () => void) => {
        setBusy(true);
        setError(null);
        router.post(route('pages.reviews.comments.store', [...routeParams, reviewUuid]), data, {
            preserveScroll: true,
            onSuccess: done,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Could not save the comment.'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <section aria-label="Review comments" className="space-y-3">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <MessageSquare className="size-4" />
                Comments
                <span className="text-muted-foreground font-normal">{open.length}</span>
            </h2>

            {draft && (
                <NewInlineComment
                    selection={draft}
                    busy={busy}
                    onCancel={onDraftDone}
                    onSubmit={(body) => post({ body, quote: draft.quote, prefix: draft.prefix, suffix: draft.suffix }, onDraftDone)}
                />
            )}

            {error && <p className="text-sm text-destructive">{error}</p>}

            {open.length === 0 && !draft && (
                <p className="text-sm text-muted-foreground">
                    {canComment ? 'Select text in the page to comment on it, or leave a comment on the whole page.' : 'No open comments.'}
                </p>
            )}

            {open.map((thread) => (
                <ThreadCard
                    key={thread.uuid}
                    thread={thread}
                    reviewUuid={reviewUuid}
                    routeParams={routeParams}
                    active={thread.uuid === activeThread}
                    outdated={thread.outdated || unplaced.has(thread.uuid)}
                    onSelect={() => onSelectThread(thread.uuid === activeThread ? null : thread.uuid)}
                    onReply={(body, done) => post({ body, parent: thread.uuid }, done)}
                    busy={busy}
                />
            ))}

            {canComment && (
                <div className="rounded-lg border bg-card p-3 space-y-2">
                    <Textarea
                        value={pageComment}
                        onChange={(e) => setPageComment(e.target.value)}
                        placeholder="Comment on the whole page…"
                        aria-label="Comment on the whole page"
                        rows={2}
                        maxLength={5000}
                    />
                    <div className="flex justify-end">
                        <Button size="sm" variant="outline" disabled={busy || pageComment.trim() === ''} onClick={() => post({ body: pageComment }, () => setPageComment(''))}>
                            Add comment
                        </Button>
                    </div>
                </div>
            )}

            {resolved.length > 0 && (
                <div className="pt-1">
                    <button type="button" aria-expanded={showResolved} onClick={() => setShowResolved((v) => !v)} className="flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground">
                        {showResolved ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                        Resolved ({resolved.length})
                    </button>
                    {showResolved && (
                        <div className="mt-2 space-y-3">
                            {resolved.map((thread) => (
                                <ThreadCard
                                    key={thread.uuid}
                                    thread={thread}
                                    reviewUuid={reviewUuid}
                                    routeParams={routeParams}
                                    active={thread.uuid === activeThread}
                                    outdated={thread.outdated || unplaced.has(thread.uuid)}
                                    onSelect={() => onSelectThread(thread.uuid === activeThread ? null : thread.uuid)}
                                    onReply={(body, done) => post({ body, parent: thread.uuid }, done)}
                                    busy={busy}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

function NewInlineComment({ selection, busy, onCancel, onSubmit }: { selection: QuoteSelection; busy: boolean; onCancel: () => void; onSubmit: (body: string) => void }) {
    const [body, setBody] = useState('');
    const ref = useRef<HTMLTextAreaElement>(null);
    useEffect(() => ref.current?.focus(), [selection.quote]);

    return (
        <div className="rounded-lg border border-primary/40 bg-card p-3 space-y-2 shadow-sm">
            <div className="flex items-start gap-2">
                <blockquote className="flex-1 border-l-2 border-amber-500/70 pl-2 text-xs text-muted-foreground line-clamp-3">{selection.quote}</blockquote>
                <button type="button" onClick={onCancel} className="text-muted-foreground hover:text-foreground" aria-label="Cancel comment">
                    <X className="size-4" />
                </button>
            </div>
            <Textarea ref={ref} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Add a comment…" aria-label="Comment on the selection" rows={3} maxLength={5000} />
            <div className="flex justify-end gap-2">
                <Button size="sm" variant="ghost" onClick={onCancel}>Cancel</Button>
                <Button size="sm" disabled={busy || body.trim() === ''} onClick={() => onSubmit(body)}>
                    {busy && <Loader2 className="size-3.5 mr-1.5 animate-spin" />}
                    Comment
                </Button>
            </div>
        </div>
    );
}

function ThreadCard({
    thread, reviewUuid, routeParams, active, outdated, onSelect, onReply, busy,
}: {
    thread: PageReviewThread;
    reviewUuid: string;
    routeParams: [string, string, string];
    active: boolean;
    outdated: boolean;
    onSelect: () => void;
    onReply: (body: string, done: () => void) => void;
    busy: boolean;
}) {
    const [replying, setReplying] = useState(false);
    const [reply, setReply] = useState('');
    const [toggling, setToggling] = useState(false);
    const fromEarlier = thread.review_uuid !== reviewUuid && thread.review_number !== null;

    const toggleResolved = () => {
        setToggling(true);
        router.patch(route('pages.reviews.threads.update', [...routeParams, thread.uuid]), { resolved: !thread.resolved }, {
            preserveScroll: true,
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Could not update the thread.'),
            onFinish: () => setToggling(false),
        });
    };

    return (
        <article
            data-thread-card={thread.uuid}
            aria-label={thread.quote ? `Comment on “${thread.quote}”` : 'Page comment'}
            className={cn('rounded-lg border bg-card p-3 text-sm transition-shadow', active && 'ring-2 ring-amber-500/60', thread.resolved && 'opacity-80')}
        >
            {(thread.quote || fromEarlier) && (
                <div className="mb-2 flex flex-wrap items-center gap-1.5">
                    {thread.quote && (
                        <button
                            type="button"
                            onClick={onSelect}
                            disabled={outdated}
                            className={cn('min-w-0 flex-1 basis-full text-left border-l-2 pl-2 text-xs line-clamp-2', outdated ? 'border-muted-foreground/40 text-muted-foreground line-through decoration-muted-foreground/50' : 'border-amber-500/70 text-muted-foreground hover:text-foreground')}
                        >
                            {thread.quote}
                        </button>
                    )}
                    {outdated && thread.quote && (
                        <Badge variant="outline" className="border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300 text-[10px]">Outdated</Badge>
                    )}
                    {fromEarlier && (
                        <Badge variant="outline" className="text-[10px] text-muted-foreground">From review #{thread.review_number}</Badge>
                    )}
                </div>
            )}

            <ol className="space-y-2">
                {thread.comments.map((comment) => (
                    <li key={comment.uuid}>
                        <div className="flex items-baseline gap-2">
                            <span className="font-medium">{comment.author.name}</span>
                            <span className="text-xs text-muted-foreground">{formatTime(comment.created_at)}</span>
                        </div>
                        <p className="whitespace-pre-wrap break-words text-foreground/90">{comment.body}</p>
                    </li>
                ))}
            </ol>

            {thread.resolved && (
                <p className="mt-2 flex items-center gap-1 text-xs text-brand-green">
                    <CheckCircle2 className="size-3.5" /> Resolved{thread.resolved_by_name ? ` by ${thread.resolved_by_name}` : ''}
                </p>
            )}

            {replying ? (
                <div className="mt-2 space-y-2">
                    <Textarea value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Reply…" aria-label="Reply" rows={2} maxLength={5000} autoFocus />
                    <div className="flex justify-end gap-2">
                        <Button size="sm" variant="ghost" onClick={() => setReplying(false)}>Cancel</Button>
                        <Button size="sm" disabled={busy || reply.trim() === ''} onClick={() => onReply(reply, () => { setReply(''); setReplying(false); })}>Reply</Button>
                    </div>
                </div>
            ) : (
                <div className="mt-2 flex items-center gap-1">
                    <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => setReplying(true)}>Reply</Button>
                    <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={toggleResolved} disabled={toggling}>
                        {thread.resolved ? <><RotateCcw className="size-3.5 mr-1" />Reopen</> : <><CheckCircle2 className="size-3.5 mr-1" />Resolve</>}
                    </Button>
                </div>
            )}
        </article>
    );
}
