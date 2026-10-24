import type { Board, Project, RecurrenceRule, RecurringIssueFields, Workspace } from '@/types';

/** Recurring issues (BRAI-123): shared helpers of the dialog and the form. No Tailwind classes here (.ts isn't scanned). */

export const WEEKDAYS: { iso: number; short: string; long: string }[] = [
    { iso: 1, short: 'Mon', long: 'Monday' },
    { iso: 2, short: 'Tue', long: 'Tuesday' },
    { iso: 3, short: 'Wed', long: 'Wednesday' },
    { iso: 4, short: 'Thu', long: 'Thursday' },
    { iso: 5, short: 'Fri', long: 'Friday' },
    { iso: 6, short: 'Sat', long: 'Saturday' },
    { iso: 7, short: 'Sun', long: 'Sunday' },
];

export const MONTH_WEEKS: { value: number; label: string }[] = [
    { value: 1, label: 'first' },
    { value: 2, label: 'second' },
    { value: 3, label: 'third' },
    { value: 4, label: 'fourth' },
    { value: -1, label: 'last' },
];

export const FREQUENCY_UNITS: Record<RecurrenceRule['frequency'], [string, string]> = {
    daily: ['day', 'days'],
    weekly: ['week', 'weeks'],
    monthly: ['month', 'months'],
    yearly: ['year', 'years'],
};

/** What the form edits: the issue fields plus the schedule. */
export type RecurringIssueDraft = RecurringIssueFields & RecurrenceRule;

/** Today in the browser's zone as Y-m-d. */
export function localToday(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/** ISO weekday (1 = Mon … 7 = Sun) of a Y-m-d date. */
export function isoWeekday(date: string): number {
    const day = new Date(`${date}T00:00:00`).getDay();
    return day === 0 ? 7 : day;
}

export function emptyDraft(prefill: Partial<RecurringIssueFields> = {}): RecurringIssueDraft {
    const start = localToday();
    return {
        title: '',
        description: null,
        item_type_id: null,
        priority_id: null,
        assigned_to: null,
        label_ids: [],
        column_id: null,
        due_in_days: null,
        frequency: 'weekly',
        interval: 1,
        weekdays: [isoWeekday(start)],
        monthly_mode: 'day',
        month_day: Number(start.slice(8, 10)),
        month_week: 1,
        month_weekday: isoWeekday(start),
        start_date: start,
        end_date: null,
        max_occurrences: null,
        ...prefill,
    };
}

/** The schedule part only (what the preview endpoint takes). */
export function ruleOf(draft: RecurringIssueDraft): RecurrenceRule {
    return {
        frequency: draft.frequency,
        interval: draft.interval,
        weekdays: draft.weekdays,
        monthly_mode: draft.monthly_mode,
        month_day: draft.month_day,
        month_week: draft.month_week,
        month_weekday: draft.month_weekday,
        start_date: draft.start_date,
        end_date: draft.end_date,
        max_occurrences: draft.max_occurrences,
    };
}

/** "Mon, Oct 26, 2026" for a Y-m-d date. */
export function formatDate(date: string): string {
    return new Date(`${date}T00:00:00`).toLocaleDateString('en', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

export type BoardRouteParams = [string, string, string];

export function boardParams(workspace: Workspace, project: Project, board: Board): BoardRouteParams {
    return [workspace.slug, project.slug, board.slug];
}

/** First validation message of an axios 422, else a fallback. */
export function errorMessage(error: unknown, fallback: string): string {
    const response = (error as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } })?.response;
    const first = response?.data?.errors ? Object.values(response.data.errors)[0]?.[0] : undefined;
    if (first) return first;
    if (response?.status === 403) return 'You can’t manage recurring issues on this board.';
    return response?.data?.message || fallback;
}
