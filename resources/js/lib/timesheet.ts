/** Helpers for logging time (v1.38.0, BRAI-99). Durations are parsed on the server (Item::parseDuration). */

/** Today in the browser's zone as Y-m-d (the default work day of a log form). */
export function localToday(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/** Hours and minutes for timesheet totals ("41h 30m"), never days or weeks. */
export function formatHoursMinutes(minutes: number): string {
    if (minutes <= 0) return '0h';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h === 0) return `${m}m`;
    return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

/** A Y-m-d calendar day as a Date at UTC midnight (no time-zone shifts). */
export function utcDay(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

/** The 7 days (Y-m-d) of the week starting on `monday`. */
export function weekDays(monday: string): string[] {
    const start = utcDay(monday).getTime();
    return Array.from({ length: 7 }, (_, i) => new Date(start + i * 86_400_000).toISOString().slice(0, 10));
}
