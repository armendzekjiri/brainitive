/** Formatting for the billing time overview and clients pages (BRAI-100). */

const hoursFormat = new Intl.NumberFormat(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });

/** Minutes as decimal hours, the way invoices count them: 90 → "1.5 h". */
export function decimalHours(minutes: number): string {
    return `${hoursFormat.format(Math.round((minutes / 60) * 100) / 100)} h`;
}

/** Billable share in whole percent; "—" when there is no time at all. */
export function billableShare(billable: number, total: number): string {
    return total > 0 ? `${Math.round((billable / total) * 100)} %` : '—';
}

export function money(cents: number, currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(cents / 100);
    } catch {
        return `${(cents / 100).toFixed(2)} ${currency}`;
    }
}

/** "2026-10-03" → "Sat, Oct 3, 2026" without time-zone shifts (the date is a calendar day). */
export function calendarDay(date: string, options: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }): string {
    const [y, m, d] = date.split('-').map(Number);
    return new Intl.DateTimeFormat(undefined, { ...options, timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
}
