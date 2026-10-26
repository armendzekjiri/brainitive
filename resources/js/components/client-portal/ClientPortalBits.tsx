import { PlanChart } from '@/components/plan/PlanChart';
import { safeHtmlProp } from '@/lib/sanitize';
import { cn } from '@/lib/utils';
import type { ClientCommentEntry, ClientDecisionEntry, ClientDeliverableStatus, ClientPlanSnapshot } from '@/types';
import { CheckCircle2, Clock, History, Lock, MessageSquare, RotateCcw, XCircle } from 'lucide-react';

/**
 * Shared pieces of the client portal (v1.42.0, BRAI-73): client pages (Pages/ClientPortal/*) and the
 * team pages (Pages/Projects/ClientPortal*). Content is frozen server-side; HTML still goes through
 * safeHtmlProp.
 */

const STATUS: Record<ClientDeliverableStatus, { label: string; className: string; icon: typeof Clock }> = {
    awaiting: { label: 'Awaiting review', className: 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300', icon: Clock },
    approved: { label: 'Approved', className: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300', icon: CheckCircle2 },
    changes_requested: { label: 'Changes requested', className: 'bg-rose-100 text-rose-900 dark:bg-rose-500/15 dark:text-rose-300', icon: RotateCcw },
    superseded: { label: 'Superseded', className: 'bg-muted text-muted-foreground', icon: History },
    withdrawn: { label: 'Removed', className: 'bg-muted text-muted-foreground', icon: XCircle },
};

export function statusLabel(status: ClientDeliverableStatus): string {
    return STATUS[status]?.label ?? status;
}

export function DeliverableStatusBadge({ status, className }: { status: ClientDeliverableStatus; className?: string }) {
    const s = STATUS[status] ?? STATUS.awaiting;
    return (
        <span data-testid="deliverable-status" className={cn('inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', s.className, className)}>
            <s.icon className="size-3.5" aria-hidden="true" />
            {s.label}
        </span>
    );
}

export function formatWhen(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function formatDay(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
}

/** The frozen content: a doc's HTML or a read-only plan chart. */
export function DeliverableContent({ html, plan }: { html: string | null; plan: ClientPlanSnapshot | null }) {
    if (plan) {
        return (
            <div className="space-y-2">
                {plan.description && <p className="text-sm text-muted-foreground">{plan.description}</p>}
                <div className="h-[28rem] overflow-hidden rounded-xl border bg-card sm:h-[32rem]">
                    <PlanChart doc={{ data: plan.data, settings: plan.settings }} className="h-full" />
                </div>
            </div>
        );
    }
    if (!html) {
        return <p className="text-sm text-muted-foreground">This document is empty.</p>;
    }
    return (
        <article
            data-testid="deliverable-content"
            className="rich-html prose prose-sm max-w-none break-words sm:prose-base dark:prose-invert"
            dangerouslySetInnerHTML={safeHtmlProp(html)}
        />
    );
}

export function DecisionList({ decisions, showEmail = false }: { decisions: ClientDecisionEntry[]; showEmail?: boolean }) {
    if (decisions.length === 0) {
        return <p className="text-sm text-muted-foreground">No decision yet.</p>;
    }
    return (
        <ol className="space-y-3" aria-label="Decisions">
            {[...decisions].reverse().map((d) => (
                <li key={d.id} className="flex gap-3" data-testid="decision-entry">
                    {d.decision === 'approved'
                        ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        : <RotateCcw className="mt-0.5 size-4 shrink-0 text-rose-600 dark:text-rose-400" aria-hidden="true" />}
                    <div className="min-w-0 text-sm">
                        <p>
                            <span className="font-medium">{d.name}{d.is_me ? ' (you)' : ''}</span>{' '}
                            {d.decision === 'approved' ? 'approved' : 'requested changes on'} version {d.version}
                            {showEmail && d.email && <span className="text-muted-foreground"> · {d.email}</span>}
                        </p>
                        <p className="text-xs text-muted-foreground">{formatWhen(d.created_at)}</p>
                        {d.note && <p className="mt-1 whitespace-pre-line break-words rounded-lg bg-muted/60 px-3 py-2">{d.note}</p>}
                    </div>
                </li>
            ))}
        </ol>
    );
}

export function CommentList({ comments, empty }: { comments: ClientCommentEntry[]; empty: string }) {
    if (comments.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }
    return (
        <ul className="space-y-3" aria-label="Comments">
            {comments.map((c) => (
                <li
                    key={c.id}
                    data-testid="comment-entry"
                    className={cn(
                        'rounded-xl border px-3 py-2 text-sm',
                        c.visibility === 'internal' ? 'border-dashed border-amber-400/60 bg-amber-50/60 dark:bg-amber-500/5' : 'bg-card',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        <span className="font-medium">{c.author}{c.is_me ? ' (you)' : ''}</span>
                        {c.from_team && c.visibility !== 'internal' && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-1.5 text-[11px] font-medium text-primary">
                                <MessageSquare className="size-3" aria-hidden="true" />Team
                            </span>
                        )}
                        {c.visibility === 'internal' && (
                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-1.5 text-[11px] font-medium text-amber-900 dark:bg-amber-500/15 dark:text-amber-300">
                                <Lock className="size-3" aria-hidden="true" />Internal
                            </span>
                        )}
                        <span className="text-xs text-muted-foreground">{formatWhen(c.created_at)}</span>
                    </div>
                    <p className="mt-1 whitespace-pre-line break-words">{c.body}</p>
                </li>
            ))}
        </ul>
    );
}

/** A one-line notice for flash messages (portal pages have no toaster). */
export function FlashNotice({ message }: { message: string | null | undefined }) {
    if (!message) return null;
    return (
        <p role="status" className="rounded-xl border border-emerald-300/60 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
            {message}
        </p>
    );
}
