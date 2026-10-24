import { cn } from '@/lib/utils';
import type { HealthItemRef, HealthMetricKey, HealthSource, HealthStatus } from '@/types';
import { Link } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import type { ReactNode } from 'react';

/** Same labels as HealthMetrics::LABELS (PHP). */
export const METRIC_LABELS: Record<HealthMetricKey, string> = {
    open: 'Open issues',
    completed: 'Completed issues',
    completed_in_period: 'Completed in period',
    created_in_period: 'Created in period',
    overdue: 'Overdue',
    due_soon: 'Due in the next 7 days',
    no_due_date: 'Open without due date',
    unassigned: 'Unassigned open issues',
    blocked: 'Blocked issues',
    milestones_upcoming: 'Upcoming milestones (30 days)',
    milestones_missed: 'Missed milestones',
    plan_rows_open: 'Open plan rows not on a board',
    plan_rows_late: 'Late plan rows not on a board',
};

export const STATUS_META: Record<HealthStatus, { label: string; dot: string; badge: string }> = {
    on_track: {
        label: 'On track',
        dot: 'bg-emerald-500',
        badge: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
    },
    at_risk: {
        label: 'At risk',
        dot: 'bg-amber-500',
        badge: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    },
    off_track: {
        label: 'Off track',
        dot: 'bg-red-500',
        badge: 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
    },
};

export const STATUSES = Object.keys(STATUS_META) as HealthStatus[];

export function StatusBadge({ status, className }: { status: HealthStatus; className?: string }) {
    const meta = STATUS_META[status];
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium', meta.badge, className)}>
            <span className={cn('size-1.5 rounded-full', meta.dot)} aria-hidden />
            {meta.label}
        </span>
    );
}

/** "Oct 24, 2026, 10:00" in the report's time zone. */
export function formatDateTime(iso: string | null, timeZone?: string): string {
    if (!iso) return '';
    try {
        return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(iso));
    } catch {
        return new Date(iso).toLocaleString();
    }
}

/** A Y-m-d date as "Oct 24" (no time zone shift: dates are wall-clock days). */
export function formatDay(day: string | null): string {
    if (!day) return '';
    const [y, m, d] = day.split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: y === new Date().getFullYear() ? undefined : 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(y, m - 1, d)));
}

/** A source ref as a chip: a link when the reader can open it, a muted "Unavailable source" otherwise. */
export function SourceChip({ source, refKey, onRemove }: { source: HealthSource | undefined; refKey: string; onRemove?: () => void }) {
    const available = source?.available ?? false;
    const label = source?.label ?? (refKey.startsWith('metric:') ? METRIC_LABELS[refKey.slice(7) as HealthMetricKey] ?? refKey : refKey);
    const base = 'inline-flex max-w-full items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] leading-4';
    const body = source && !available
        ? <span className={cn(base, 'border-dashed text-muted-foreground')}><Ban className="size-3 shrink-0" />Unavailable source</span>
        : source?.url
            ? onRemove
                // while editing, a source opens in a new tab so the unsaved draft stays
                ? <a href={source.url} target="_blank" rel="noopener noreferrer" className={cn(base, 'bg-muted/60 text-foreground hover:bg-muted')}><span className="truncate">{label}</span></a>
                : <Link href={source.url} className={cn(base, 'bg-muted/60 text-foreground hover:bg-muted')}><span className="truncate">{label}</span></Link>
            : <span className={cn(base, 'bg-muted/60 text-muted-foreground')}><span className="truncate">{label}</span></span>;

    if (!onRemove) return body;
    return (
        <span className="inline-flex max-w-full items-center gap-0.5">
            {body}
            <button type="button" onClick={onRemove} className="rounded px-1 text-xs text-muted-foreground hover:text-foreground" aria-label={`Remove source ${label}`}>×</button>
        </span>
    );
}

/** One issue row in a health list. */
export function IssueRow({ item, today, extra }: { item: HealthItemRef; today?: string; extra?: ReactNode }) {
    const late = today && item.due_date !== null && item.due_date < today;
    return (
        <li className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 text-sm">
            <Link href={item.url} className="min-w-0 flex-1 hover:underline">
                <span className="mr-2 font-mono text-xs text-muted-foreground">{item.key}</span>
                <span className="break-words">{item.title}</span>
            </Link>
            <span className="flex shrink-0 items-center gap-3 text-xs text-muted-foreground">
                {item.due_date && <span className={cn(late && 'font-medium text-red-600 dark:text-red-400')}>Due {formatDay(item.due_date)}</span>}
                <span>{item.assignee ?? 'Unassigned'}</span>
            </span>
            {extra}
        </li>
    );
}

export const METRIC_DEFINITIONS: { term: string; text: string }[] = [
    { term: 'Issue', text: 'Every issue and subtask on the project’s boards. Deleted issues and issues on deleted boards don’t count.' },
    { term: 'Completed / open', text: 'Completed = marked complete or in a done column. Everything else is open.' },
    { term: 'Today', text: 'The date in the time zone you picked. Change it above to see the numbers for another zone.' },
    { term: 'Overdue', text: 'Open issues whose due date is before today. Due today is not overdue.' },
    { term: 'Due in the next 7 days', text: 'Open issues due today or in the 6 days after.' },
    { term: 'Unassigned', text: 'Open issues without an assignee.' },
    { term: 'Blocked', text: 'Open issues with a “blocks” / “is blocked by” link whose blocker is still open and that you can open. Each pair counts once; “relates to”, labels or text never count.' },
    { term: 'Completed / created in period', text: 'Completion or creation time inside the period, in your time zone. Issues in a done column without a completion time have no date.' },
    { term: 'Plans', text: 'Milestones are listed apart. Plan rows linked to an issue are that issue and aren’t counted again; other rows with bars are “plan rows not on a board”.' },
    { term: 'Unknown', text: 'Shown when the data isn’t there (no boards, no due dates, no plans) — never as a healthy zero. Brainitive doesn’t compute a health score: the status is picked by a person.' },
];
