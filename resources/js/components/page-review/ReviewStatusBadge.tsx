import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { PageReviewDecision, PageReviewStatus } from '@/types';
import { AlertTriangle, CheckCircle2, CircleDashed, Clock, GitPullRequestClosed, XCircle } from 'lucide-react';

/** Status chip of a page review (BRAI-80); `stale` wins over an open status. */
export function ReviewStatusBadge({ status, stale = false, className }: { status: PageReviewStatus; stale?: boolean; className?: string }) {
    const open = status === 'pending' || status === 'approved' || status === 'changes_requested';
    if (stale && open) {
        return (
            <Badge variant="outline" className={cn('border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300', className)}>
                <AlertTriangle className="size-3 mr-1" /> Outdated draft
            </Badge>
        );
    }

    const styles: Record<PageReviewStatus, { label: string; className: string; icon: JSX.Element }> = {
        pending: { label: 'In review', className: 'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-300', icon: <Clock className="size-3 mr-1" /> },
        approved: { label: 'Approved', className: 'border-brand-green/40 bg-brand-green/10 text-brand-green', icon: <CheckCircle2 className="size-3 mr-1" /> },
        changes_requested: { label: 'Changes requested', className: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300', icon: <XCircle className="size-3 mr-1" /> },
        published: { label: 'Published', className: 'border-brand-green/40 bg-brand-green/5 text-brand-green', icon: <CheckCircle2 className="size-3 mr-1" /> },
        cancelled: { label: 'Cancelled', className: 'border-border bg-muted text-muted-foreground', icon: <GitPullRequestClosed className="size-3 mr-1" /> },
        superseded: { label: 'Replaced by a newer review', className: 'border-border bg-muted text-muted-foreground', icon: <CircleDashed className="size-3 mr-1" /> },
    };
    const style = styles[status];

    return (
        <Badge variant="outline" className={cn(style.className, className)}>
            {style.icon} {style.label}
        </Badge>
    );
}

export const DECISION_LABELS: Record<PageReviewDecision, string> = {
    pending: 'Waiting',
    approved: 'Approved',
    changes_requested: 'Changes requested',
};

export const DECISION_CLASSES: Record<PageReviewDecision, string> = {
    pending: 'text-muted-foreground',
    approved: 'text-brand-green',
    changes_requested: 'text-red-600 dark:text-red-400',
};
