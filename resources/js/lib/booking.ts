/**
 * Time helpers for booking pages (BRAI-158). The server sends slot starts in UTC (ISO, "Z"); the page
 * shows them in the visitor's zone, which they can switch. Only Intl — no date library.
 */

/** The browser's IANA zone, or UTC. */
export function browserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

/** Every IANA zone the browser knows (for the picker); the current one at least. */
export function allTimezones(current: string): string[] {
    try {
        const zones = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone') ?? [];
        return zones.includes(current) ? zones : [current, ...zones];
    } catch {
        return [current];
    }
}

const parts = (iso: string, zone: string) => {
    const out: Record<string, string> = {};
    for (const p of new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(iso))) {
        out[p.type] = p.value;
    }
    return out;
};

/** 'YYYY-MM-DD' of an instant in a zone. */
export function dayKey(iso: string, zone: string): string {
    const p = parts(iso, zone);
    return `${p.year}-${p.month}-${p.day}`;
}

/** "14:30" (24 h) or "2:30 PM", following the visitor's locale. */
export function timeLabel(iso: string, zone: string): string {
    return new Intl.DateTimeFormat(undefined, { timeZone: zone, hour: 'numeric', minute: '2-digit' }).format(new Date(iso));
}

/** "Monday, 4 January 2027" */
export function longDate(iso: string, zone: string): string {
    return new Intl.DateTimeFormat(undefined, { timeZone: zone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso));
}

/** "Mon 4 Jan, 14:00–14:30" in a zone. */
export function whenLabel(startIso: string, endIso: string, zone: string): string {
    const day = new Intl.DateTimeFormat(undefined, { timeZone: zone, weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(startIso));
    return `${day}, ${timeLabel(startIso, zone)}–${timeLabel(endIso, zone)}`;
}

/** "January 2027" for a 'YYYY-MM' key. */
export function monthLabel(month: string): string {
    const [y, m] = month.split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(Date.UTC(y, m - 1, 1));
}

export function addMonths(month: string, delta: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + delta, 1));
    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** The month's days as a Monday-first grid; null = padding. */
export function monthGrid(month: string): (string | null)[] {
    const [y, m] = month.split('-').map(Number);
    const first = new Date(Date.UTC(y, m - 1, 1));
    const days = new Date(Date.UTC(y, m, 0)).getUTCDate();
    const pad = (first.getUTCDay() + 6) % 7;
    const cells: (string | null)[] = Array.from({ length: pad }, () => null);
    for (let d = 1; d <= days; d++) cells.push(`${month}-${String(d).padStart(2, '0')}`);
    while (cells.length % 7 !== 0) cells.push(null);
    return cells;
}

export function lastDayOfMonth(month: string): string {
    const [y, m] = month.split('-').map(Number);
    return `${month}-${String(new Date(Date.UTC(y, m, 0)).getUTCDate()).padStart(2, '0')}`;
}

/** Weekday initials, Monday first, in the visitor's language. */
export function weekdayInitials(): string[] {
    const fmt = new Intl.DateTimeFormat(undefined, { weekday: 'short', timeZone: 'UTC' });
    return Array.from({ length: 7 }, (_, i) => fmt.format(Date.UTC(2024, 0, 1 + i))); // 2024-01-01 was a Monday
}

export function durationLabel(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h} h ${m} min` : `${h} h`;
}

export const LOCATION_LABELS: Record<string, string> = {
    none: 'No location',
    google_meet: 'Google Meet',
    teams: 'Microsoft Teams',
    phone: 'Phone call',
    in_person: 'In person',
    link: 'Online (your link)',
};
