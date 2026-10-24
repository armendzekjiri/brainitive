import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { AppNotification } from '@/types';
import { AlertCircle, Loader2, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';

export const DAY_GROUPS = ['Today', 'Yesterday', 'This week', 'Older'] as const;
export type DayGroup = (typeof DAY_GROUPS)[number];

function startOfDay(d: Date): number {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

export function dayGroup(iso: string, now = new Date()): DayGroup {
    const days = Math.round((startOfDay(now) - startOfDay(new Date(iso))) / 86_400_000);
    if (days <= 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return 'This week';
    return 'Older';
}

/** Rows grouped Today / Yesterday / This week / Older, keeping their order (newest first). */
export function groupByDay(items: AppNotification[], now = new Date()): { label: DayGroup; items: AppNotification[] }[] {
    const groups = new Map<DayGroup, AppNotification[]>();
    for (const n of items) {
        const label = dayGroup(n.created_at, now);
        groups.set(label, [...(groups.get(label) ?? []), n]);
    }
    return DAY_GROUPS.filter((g) => groups.has(g)).map((label) => ({ label, items: groups.get(label)! }));
}

export interface EmptyState {
    icon: LucideIcon;
    title: string;
    description: string;
}

interface ListProps {
    items: AppNotification[];
    loading: boolean;
    error: boolean;
    hasMore: boolean;
    loadingMore: boolean;
    onLoadMore: () => void;
    onRetry: () => void;
    empty: EmptyState;
    /** Renders one row; `index` is the position in `items` (for keyboard navigation). */
    renderRow: (notification: AppNotification, index: number) => ReactNode;
    /** Background of the sticky day headers (match the container). */
    headerClassName?: string;
    className?: string;
}

export function NotificationList({ items, loading, error, hasMore, loadingMore, onLoadMore, onRetry, empty, renderRow, headerClassName, className }: ListProps) {
    const sentinel = useRef<HTMLDivElement>(null);

    // infinite scroll: load the next page when the end of the list comes into view
    useEffect(() => {
        const el = sentinel.current;
        if (!el || !hasMore || typeof IntersectionObserver === 'undefined') return;
        const observer = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && onLoadMore(), { rootMargin: '120px' });
        observer.observe(el);
        return () => observer.disconnect();
    }, [hasMore, onLoadMore, items.length]);

    if (loading && items.length === 0) {
        return (
            <div className={cn('space-y-1 p-2', className)} aria-busy="true" aria-label="Loading notifications">
                {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="flex animate-pulse gap-3 px-2 py-3">
                        <div className="size-9 shrink-0 rounded-full bg-muted" />
                        <div className="flex-1 space-y-2 pt-1">
                            <div className="h-3 w-4/5 rounded bg-muted" />
                            <div className="h-2.5 w-2/5 rounded bg-muted" />
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    if (error && items.length === 0) {
        return (
            <div className={cn('flex flex-col items-center justify-center gap-3 px-6 py-12 text-center', className)} role="alert">
                <AlertCircle className="size-6 text-destructive" aria-hidden />
                <p className="text-sm text-muted-foreground">Notifications could not be loaded.</p>
                <Button variant="outline" size="sm" onClick={onRetry}>
                    Try again
                </Button>
            </div>
        );
    }

    if (items.length === 0) {
        const Icon = empty.icon;
        return (
            <div className={cn('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
                <span className="mb-4 flex size-12 items-center justify-center rounded-full bg-muted">
                    <Icon className="size-5 text-muted-foreground" aria-hidden />
                </span>
                <p className="text-sm font-medium">{empty.title}</p>
                <p className="mt-1 max-w-xs text-sm text-muted-foreground">{empty.description}</p>
            </div>
        );
    }

    let index = 0;
    return (
        <div className={className}>
            {groupByDay(items).map((group) => (
                <section key={group.label} aria-label={group.label}>
                    <h3
                        className={cn(
                            'sticky top-0 z-20 border-b border-border/60 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground backdrop-blur',
                            headerClassName ?? 'bg-popover/95',
                        )}
                    >
                        {group.label}
                    </h3>
                    <ul role="list" className="divide-y divide-border/60">
                        {group.items.map((n) => renderRow(n, index++))}
                    </ul>
                </section>
            ))}
            {hasMore && (
                <div ref={sentinel} className="flex justify-center p-3">
                    <Button variant="ghost" size="sm" onClick={onLoadMore} disabled={loadingMore} className="text-muted-foreground">
                        {loadingMore ? <Loader2 className="size-4 animate-spin" aria-hidden /> : null}
                        {loadingMore ? 'Loading…' : 'Load more'}
                    </Button>
                </div>
            )}
        </div>
    );
}
