import { PlanData, PlanZoom } from './types';

/**
 * Dates are handled as UTC day numbers (days since 1970-01-01) so timezones and DST never
 * shift a bar; documents store them as Y-m-d strings.
 */
const MS_PER_DAY = 86_400_000;

export const PX_PER_DAY: Record<PlanZoom, number> = { day: 34, week: 18, month: 4.5, quarter: 1.6 };

export function toDay(date: string): number {
    const [y, m, d] = date.split('-').map(Number);
    return Math.round(Date.UTC(y, m - 1, d) / MS_PER_DAY);
}

export function fromDay(day: number): string {
    return new Date(day * MS_PER_DAY).toISOString().slice(0, 10);
}

export function todayDay(): number {
    const now = new Date();
    return Math.round(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()) / MS_PER_DAY);
}

/** 0 = Sunday … 6 = Saturday */
export function weekday(day: number): number {
    return (((day + 4) % 7) + 7) % 7;
}

export function isWeekend(day: number): boolean {
    const wd = weekday(day);
    return wd === 0 || wd === 6;
}

export function startOfWeek(day: number): number {
    return day - ((weekday(day) + 6) % 7);
}

function utc(day: number): Date {
    return new Date(day * MS_PER_DAY);
}

function dayOf(year: number, month: number, date = 1): number {
    return Math.round(Date.UTC(year, month, date) / MS_PER_DAY);
}

export function startOfMonth(day: number): number {
    const d = utc(day);
    return dayOf(d.getUTCFullYear(), d.getUTCMonth());
}

export function startOfQuarter(day: number): number {
    const d = utc(day);
    return dayOf(d.getUTCFullYear(), Math.floor(d.getUTCMonth() / 3) * 3);
}

export function isoWeek(day: number): number {
    const thursday = startOfWeek(day) + 3;
    const yearStart = dayOf(utc(thursday).getUTCFullYear(), 0);
    return Math.floor((thursday - yearStart) / 7) + 1;
}

/** Mon–Fri days in [start, end], inclusive. */
export function workingDays(start: number, end: number): number {
    if (end < start) return 0;
    let count = 0;
    for (let day = start; day <= end; day++) if (!isWeekend(day)) count++;
    return count;
}

/** Last day of a span that starts at `start` and covers `count` working days. */
export function endAfterWorkingDays(start: number, count: number): number {
    let day = start;
    while (isWeekend(day)) day++;
    let left = Math.max(1, count) - 1;
    while (left > 0) {
        day++;
        if (!isWeekend(day)) left--;
    }
    return day;
}

const dateFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC' });
const monthFormat = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
const shortMonthFormat = new Intl.DateTimeFormat('en-GB', { month: 'short', timeZone: 'UTC' });
const weekdayFormat = new Intl.DateTimeFormat('en-GB', { weekday: 'narrow', timeZone: 'UTC' });
const dayMonthFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });

export function formatDate(date: string | number): string {
    return dateFormat.format(utc(typeof date === 'number' ? date : toDay(date)));
}

export interface HeaderCell {
    key: string;
    x: number;
    width: number;
    label: string;
    sub?: string;
    weekend?: boolean;
    today?: boolean;
}

export interface TimeScale {
    zoom: PlanZoom;
    start: number;
    end: number;
    pxPerDay: number;
    width: number;
    x: (day: number) => number;
    dayAt: (x: number) => number;
    top: HeaderCell[];
    bottom: HeaderCell[];
}

/**
 * Visible range: the plan's bars and today, padded, snapped to the zoom's unit.
 */
export function buildScale(data: PlanData, zoom: PlanZoom, minWidth = 0, tight = false): TimeScale {
    const today = todayDay();
    let min = tight ? Infinity : today;
    let max = tight ? -Infinity : today;
    for (const row of data.rows) {
        for (const bar of row.bars) {
            min = Math.min(min, toDay(bar.start));
            max = Math.max(max, toDay(bar.end));
        }
    }
    // Room to scroll and draw beyond the plan; `tight` (print) keeps just a small margin
    if (!Number.isFinite(min)) min = max = today;
    const pad = tight
        ? { day: [1, 3], week: [3, 7], month: [7, 14], quarter: [14, 31] }[zoom]
        : { day: [7, 45], week: [14, 120], month: [31, 365], quarter: [92, 730] }[zoom];
    let start = min - pad[0];
    start = zoom === 'month' ? startOfMonth(start) : zoom === 'quarter' ? startOfQuarter(start) : startOfWeek(start);
    const pxPerDay = PX_PER_DAY[zoom];
    let end = max + pad[1];
    end = Math.max(end, start + Math.ceil(minWidth / pxPerDay));

    const top: HeaderCell[] = [];
    const bottom: HeaderCell[] = [];
    const x = (day: number) => (day - start) * pxPerDay;
    const cell = (key: string, from: number, to: number, label: string, extra: Partial<HeaderCell> = {}) => ({
        key, x: x(from), width: (to - from) * pxPerDay, label, ...extra,
    });

    // Top tier: months (day/week zoom) or years
    for (let day = start; day <= end;) {
        const d = utc(day);
        if (zoom === 'day' || zoom === 'week') {
            const next = dayOf(d.getUTCFullYear(), d.getUTCMonth() + 1);
            top.push(cell(`m${day}`, day, Math.min(next, end + 1), monthFormat.format(d)));
            day = next;
        } else {
            const next = dayOf(d.getUTCFullYear() + 1, 0);
            top.push(cell(`y${day}`, day, Math.min(next, end + 1), String(d.getUTCFullYear())));
            day = next;
        }
    }

    // Bottom tier: the unit itself
    for (let day = start; day <= end;) {
        const d = utc(day);
        if (zoom === 'day') {
            bottom.push(cell(`d${day}`, day, day + 1, String(d.getUTCDate()), { sub: weekdayFormat.format(d), weekend: isWeekend(day), today: day === today }));
            day += 1;
        } else if (zoom === 'week') {
            bottom.push(cell(`w${day}`, day, day + 7, `W${isoWeek(day)}`, { sub: dayMonthFormat.format(d), today: today >= day && today < day + 7 }));
            day += 7;
        } else if (zoom === 'month') {
            const next = dayOf(d.getUTCFullYear(), d.getUTCMonth() + 1);
            bottom.push(cell(`m${day}`, day, next, shortMonthFormat.format(d), { today: today >= day && today < next }));
            day = next;
        } else {
            const next = dayOf(d.getUTCFullYear(), d.getUTCMonth() + 3);
            bottom.push(cell(`q${day}`, day, next, `Q${Math.floor(d.getUTCMonth() / 3) + 1}`, { today: today >= day && today < next }));
            day = next;
        }
    }

    const last = bottom[bottom.length - 1];
    const width = last ? last.x + last.width : x(end + 1);

    return {
        zoom,
        start,
        end: start + Math.round(width / pxPerDay) - 1,
        pxPerDay,
        width,
        x,
        dayAt: (px: number) => start + Math.floor(px / pxPerDay),
        top,
        bottom,
    };
}
