import type { BillingReportStatus } from '@/types';

/** Display helpers for billing reports (v1.38.0, BRAI-101). Amounts are integer cents, durations minutes. */

export function formatMoney(cents: number, currency = 'EUR'): string {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(cents / 100);
    } catch {
        return `${(cents / 100).toFixed(2)} ${currency}`;
    }
}

/** 390 → "6.50 h" (decimal hours, like on an invoice). */
export function formatHours(minutes: number): string {
    return `${(minutes / 60).toFixed(2)} h`;
}

/** 19 % from basis points (1900). */
export function formatTaxRate(bp: number): string {
    return `${Number.isInteger(bp / 100) ? bp / 100 : (bp / 100).toFixed(2)} %`;
}

/** A Y-m-d day without time zone shifts: "3 Oct 2026". */
export function formatDay(day: string): string {
    const [y, m, d] = day.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}

export function formatPeriod(from: string, to: string): string {
    return `${formatDay(from)} – ${formatDay(to)}`;
}

export const STATUS_LABELS: Record<BillingReportStatus, string> = {
    draft: 'Draft',
    finalized: 'Finalized',
    sent: 'Sent',
    paid: 'Paid',
    void: 'Void',
};

export const STATUS_CLASSES: Record<BillingReportStatus, string> = {
    draft: 'border-border bg-muted text-muted-foreground',
    finalized: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    sent: 'border-transparent bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
    paid: 'border-transparent bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
    void: 'border-transparent bg-red-100 text-red-800 line-through dark:bg-red-500/15 dark:text-red-300',
};

