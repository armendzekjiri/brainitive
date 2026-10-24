import { Fragment, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import { Clock, FileSpreadsheet, Info, Lock } from 'lucide-react';
import BillingLayout from '@/Layouts/BillingLayout';
import { TimeOverviewFilterBar } from '@/components/billing/TimeOverviewFilterBar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { billableShare, calendarDay, decimalHours, money } from '@/lib/timeOverview';
import { cn } from '@/lib/utils';
import type { TimeOverviewEntry, TimeOverviewFilters, TimeOverviewGroup, TimeOverviewOptions, TimeOverviewSummary, TimeOverviewTicketRow } from '@/types';

interface Props {
    workspace: { id: number; name: string; slug: string };
    filters: TimeOverviewFilters;
    presets: Record<'last_month' | 'this_month', { from: string; to: string }>;
    summary: TimeOverviewSummary;
    options: TimeOverviewOptions;
    currency: string;
    detailLimit: number;
}

/** The filters as query-string parameters (defaults left out, so URLs stay short). */
type Query = Record<string, string | number | number[]>;

function toQuery(f: TimeOverviewFilters, withView = true): Query {
    const q: Query = { period: f.period };
    if (f.period === 'custom') Object.assign(q, { from: f.from, to: f.to });
    if (f.projects.length) q.projects = f.projects;
    if (f.client) q.client = f.client;
    if (f.person) q.person = f.person;
    if (f.activity !== null) q.activity = f.activity;
    if (f.billable !== 'all') q.billable = f.billable;
    if (withView && f.group !== 'ticket') q.group = f.group;
    if (withView && f.detail) q.detail = 1;
    return q;
}

/**
 * Billing → Time (BRAI-100, workspace admins): everyone's logged time for the month-end review —
 * filter, group, check the billable share and an indicative amount, export to Excel.
 */
export default function TimeOverviewIndex({ workspace, filters, presets, summary, options, currency, detailLimit }: Props) {
    // changes made while a reload is still running build on each other, not on the stale props
    const pending = useRef(filters);
    useEffect(() => {
        pending.current = filters;
    }, [filters]);
    const visit = (next: Partial<TimeOverviewFilters>) => {
        pending.current = { ...pending.current, ...next };
        router.get(route('billing.time.index', workspace.slug), toQuery(pending.current), { preserveState: true, preserveScroll: true, replace: true });
    };
    const exportUrl = route('billing.time.export', { workspace: workspace.slug, ...toQuery(filters, false) });
    const { totals, groups } = summary;
    const period = `${calendarDay(filters.from, { day: 'numeric', month: 'short', year: 'numeric' })} – ${calendarDay(filters.to, { day: 'numeric', month: 'short', year: 'numeric' })}`;

    return (
        <BillingLayout
            title="Time"
            description="Everyone's logged time, for the month-end review and the client."
            actions={
                <Button size="sm" variant="outline" asChild>
                    <a href={exportUrl}>
                        <FileSpreadsheet className="size-4" />
                        Export Excel
                    </a>
                </Button>
            }
        >
            <TimeOverviewFilterBar filters={filters} presets={presets} options={options} onChange={visit} />

            <section aria-label="Totals" className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <Stat label="Hours" value={decimalHours(totals.minutes)} hint={period} testId="time-total-hours" />
                <Stat label="Billable" value={decimalHours(totals.billable_minutes)} hint={billableShare(totals.billable_minutes, totals.minutes)} testId="time-total-billable" />
                <Stat label="Non-billable" value={decimalHours(totals.non_billable_minutes)} testId="time-total-non-billable" />
                <Stat label="Entries" value={String(totals.entries)} testId="time-total-entries" />
                <Stat
                    label="Estimate"
                    value={totals.priced ? money(totals.amount_cents, currency) : '—'}
                    hint="before rounding / VAT"
                    testId="time-total-estimate"
                    className="col-span-2 lg:col-span-1"
                />
            </section>

            <div className="overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm">
                {groups.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-4 py-14 text-center">
                        <Clock className="size-10 text-muted-foreground" />
                        <p className="font-medium">No time logged for these filters</p>
                        <p className="text-sm text-muted-foreground">{period}. Try another period or clear the filters.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm" aria-label="Logged time">
                            <thead className="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-4 py-2.5 font-medium">{GROUP_HEADINGS[filters.group]}</th>
                                    <th scope="col" className="px-4 py-2.5 text-right font-medium">Hours</th>
                                    <th scope="col" className="px-4 py-2.5 text-right font-medium">Billable</th>
                                    <th scope="col" className="w-36 px-4 py-2.5 font-medium">Share</th>
                                    <th scope="col" className="px-4 py-2.5 text-right font-medium">Estimate</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.map((group) => (
                                    <GroupRows key={group.key} group={group} filters={filters} currency={currency} />
                                ))}
                            </tbody>
                            <tfoot className="border-t bg-muted/30 font-semibold">
                                <tr>
                                    <th scope="row" className="px-4 py-2.5 text-left">Total</th>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{decimalHours(totals.minutes)}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{decimalHours(totals.billable_minutes)}</td>
                                    <td className="px-4 py-2.5"><ShareBar billable={totals.billable_minutes} total={totals.minutes} /></td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">{totals.priced ? money(totals.amount_cents, currency) : '—'}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </div>

            <p className="flex items-start gap-2 text-xs text-muted-foreground">
                <Info className="mt-0.5 size-3.5 shrink-0" />
                <span>
                    Estimate before rounding / VAT: hourly activities × their rate, fixed prices once per ticket. Billing reports compute the amounts you send.
                    {summary.detail_truncated && ` Only the first ${detailLimit} entries are listed here; the Excel export has all of them.`}
                </span>
            </p>
        </BillingLayout>
    );
}

const GROUP_HEADINGS: Record<TimeOverviewFilters['group'], string> = {
    ticket: 'Project / ticket',
    person: 'Person',
    activity: 'Activity',
    day: 'Day',
};

function Stat({ label, value, hint, testId, className }: { label: string; value: string; hint?: string; testId: string; className?: string }) {
    return (
        <div className={cn('rounded-xl border bg-card p-3 text-card-foreground shadow-sm sm:p-4', className)} data-testid={testId}>
            <div className="text-xs font-medium text-muted-foreground">{label}</div>
            <div className="mt-1 text-xl font-semibold tabular-nums sm:text-2xl">{value}</div>
            {hint && <div className="mt-0.5 text-xs text-muted-foreground">{hint}</div>}
        </div>
    );
}

function ShareBar({ billable, total }: { billable: number; total: number }) {
    const pct = total > 0 ? Math.round((billable / total) * 100) : 0;
    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" aria-hidden>
                <div className="h-full rounded-full bg-emerald-600 dark:bg-emerald-500" style={{ width: `${pct}%` }} />
            </div>
            <span className="w-10 text-right text-xs tabular-nums text-muted-foreground">{billableShare(billable, total)}</span>
        </div>
    );
}

function Amount({ row, currency }: { row: { priced: boolean; amount_cents: number }; currency: string }) {
    return <>{row.priced ? money(row.amount_cents, currency) : <span className="text-muted-foreground">—</span>}</>;
}

function GroupRows({ group, filters, currency }: { group: TimeOverviewGroup; filters: TimeOverviewFilters; currency: string }) {
    const ticketMode = filters.group === 'ticket';
    const label = filters.group === 'day' ? calendarDay(group.label) : group.label;

    return (
        <>
            <tr className={cn('border-b', ticketMode && 'bg-muted/20')}>
                <th scope="row" className="px-4 py-2.5 text-left font-semibold">
                    <span className="flex flex-wrap items-center gap-2">
                        <span>{label}</span>
                        {ticketMode && group.meta && <span className="font-mono text-xs font-normal text-muted-foreground">{group.meta}</span>}
                        {ticketMode && group.client && <Badge variant="outline" className="font-normal">{group.client}</Badge>}
                        {filters.group === 'activity' && group.meta && (
                            <Badge variant="secondary" className="font-normal">{group.meta === 'fixed' ? 'Fixed price' : 'Hourly'}</Badge>
                        )}
                    </span>
                </th>
                <td className="px-4 py-2.5 text-right font-semibold tabular-nums">{decimalHours(group.minutes)}</td>
                <td className="px-4 py-2.5 text-right tabular-nums">{decimalHours(group.billable_minutes)}</td>
                <td className="px-4 py-2.5"><ShareBar billable={group.billable_minutes} total={group.minutes} /></td>
                <td className="px-4 py-2.5 text-right tabular-nums"><Amount row={group} currency={currency} /></td>
            </tr>
            {ticketMode
                ? group.rows?.map((row) => <TicketRow key={row.key} row={row} currency={currency} detail={filters.detail} />)
                : filters.detail && <EntryRows entries={group.detail ?? []} showTicket />}
        </>
    );
}

function TicketRow({ row, currency, detail }: { row: TimeOverviewTicketRow; currency: string; detail: boolean }) {
    return (
        <Fragment>
            <tr className="border-b last:border-0">
                <th scope="row" className="py-2 pl-8 pr-4 text-left font-normal">
                    <span className="flex min-w-0 items-baseline gap-2">
                        {row.ticket_key && <span className="shrink-0 font-mono text-xs text-muted-foreground">{row.ticket_key}</span>}
                        <span className={cn('min-w-0 break-words', !row.ticket_key && 'italic text-muted-foreground')}>{row.label ?? row.ticket_key}</span>
                    </span>
                </th>
                <td className="px-4 py-2 text-right tabular-nums">{decimalHours(row.minutes)}</td>
                <td className="px-4 py-2 text-right tabular-nums">{decimalHours(row.billable_minutes)}</td>
                <td className="px-4 py-2"><ShareBar billable={row.billable_minutes} total={row.minutes} /></td>
                <td className="px-4 py-2 text-right tabular-nums"><Amount row={row} currency={currency} /></td>
            </tr>
            {detail && <EntryRows entries={row.detail} />}
        </Fragment>
    );
}

function EntryRows({ entries, showTicket = false }: { entries: TimeOverviewEntry[]; showTicket?: boolean }) {
    return (
        <>
            {entries.map((entry) => (
                <tr key={entry.id} className="border-b bg-muted/10 text-xs text-muted-foreground last:border-0" data-testid="time-entry">
                    <td colSpan={5} className="py-1.5 pl-12 pr-4">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                            <span className="tabular-nums">{calendarDay(entry.date, { day: 'numeric', month: 'short' })}</span>
                            <span className="font-medium text-foreground">{entry.person ?? 'Deleted user'}</span>
                            {showTicket && entry.ticket_key && <span className="font-mono">{entry.ticket_key}</span>}
                            <span>{entry.activity ?? 'No activity'}</span>
                            <span className="tabular-nums text-foreground">{decimalHours(entry.minutes)}</span>
                            {!entry.billable && <Badge variant="outline" className="px-1.5 py-0 text-[10px] font-normal">non-billable</Badge>}
                            {entry.locked && (
                                <span className="inline-flex items-center gap-1" title={`Billed in report ${entry.report ?? ''}`}>
                                    <Lock className="size-3" aria-hidden />
                                    <span className="sr-only">Billed in report</span>
                                    {entry.report}
                                </span>
                            )}
                            {entry.note && <span className="min-w-0 basis-full whitespace-pre-line sm:basis-auto">{entry.note}</span>}
                        </div>
                    </td>
                </tr>
            ))}
        </>
    );
}
