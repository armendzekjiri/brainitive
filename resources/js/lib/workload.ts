/**
 * Workload planning helpers (BRAI-75). Amounts are integer minutes; weeks are Mondays (Y-m-d).
 * formatHours mirrors App\Support\Workload\WorkloadFormat::hours — keep both in sync.
 */
import type { WorkloadCell, WorkloadWeek } from '@/types';

/** "25h", "7h 30m", "20m", "0h" — hours and minutes only (no 1d = 8h for capacity). */
export function formatHours(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) return '—';
    const sign = minutes < 0 ? '-' : '';
    const abs = Math.abs(Math.round(minutes));
    const h = Math.floor(abs / 60);
    const m = abs % 60;
    if (m === 0) return `${sign}${h}h`;
    return h === 0 ? `${sign}${m}m` : `${sign}${h}h ${m}m`;
}

/**
 * Parse what a planner typed into whole minutes: "7.5", "7,5" and "7.5h" are hours, "7h 30m",
 * "45m". Returns null for anything else (and for negative amounts).
 */
export function parseHours(input: string): number | null {
    const text = input.trim().toLowerCase().replace(',', '.');
    if (text === '') return null;
    if (/^\d+(\.\d+)?h?$/.test(text)) return Math.round(parseFloat(text) * 60);
    const match = /^(?:(\d+(?:\.\d+)?)\s*h)?\s*(?:(\d+)\s*m)?$/.exec(text);
    if (!match || (match[1] === undefined && match[2] === undefined)) return null;
    return Math.round(parseFloat(match[1] ?? '0') * 60) + parseInt(match[2] ?? '0', 10);
}

/** Minutes as an editable hours value ("7.5", "8", "0.33"). */
export function hoursInput(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) return '';
    return String(Math.round((minutes / 60) * 100) / 100);
}

const day = (iso: string) => new Date(`${iso}T00:00:00Z`);
const fmt = (iso: string, opts: Intl.DateTimeFormatOptions) => day(iso).toLocaleDateString(undefined, { timeZone: 'UTC', ...opts });

/** "26 Oct – 1 Nov" (dates are calendar days: formatted in UTC so no zone shifts them). */
export function weekRange(week: Pick<WorkloadWeek, 'start' | 'end'>): string {
    return `${fmt(week.start, { day: 'numeric', month: 'short' })} – ${fmt(week.end, { day: 'numeric', month: 'short' })}`;
}

export function weekTitle(week: WorkloadWeek): string {
    return `Week ${week.week}, ${week.year} · ${weekRange(week)}`;
}

/** Short status text of a cell, as a person reads it. */
export function cellSummary(cell: WorkloadCell): string {
    switch (cell.state) {
        case 'unset':
            return cell.allocated_minutes > 0 ? `${formatHours(cell.allocated_minutes)} planned · capacity not set` : 'Capacity not set';
        case 'unavailable':
            return 'Unavailable (0h)';
        case 'over':
            return cell.capacity_minutes === 0
                ? `${formatHours(cell.over_minutes)} over · unavailable`
                : `${formatHours(cell.over_minutes)} over capacity`;
        default:
            return `${formatHours(cell.free_minutes)} free`;
    }
}

/** Bar width (0–100) for the load bar. */
export function loadWidth(cell: WorkloadCell): number {
    if (cell.capacity_minutes === null) return 0;
    if (cell.capacity_minutes === 0) return cell.allocated_minutes > 0 ? 100 : 0;
    return Math.min(100, Math.round((cell.allocated_minutes / cell.capacity_minutes) * 100));
}

export const FLAG_LABELS: Record<string, string> = {
    over_remaining: 'More planned than remaining',
    estimate_changed: 'Estimate changed',
    assignee_changed: 'Assignee changed',
    not_member: 'Left the project',
    done: 'Done',
};
