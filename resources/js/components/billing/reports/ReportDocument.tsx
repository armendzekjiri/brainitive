import { Fragment, useState } from 'react';
import { ChevronRight, MinusCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDay, formatHours, formatMoney, formatPeriod, formatTaxRate } from '@/lib/billingReports';
import { cn } from '@/lib/utils';
import type { BillingGroupBy, BillingReportRow, BillingReportSnapshot, BillingSnapshotLine } from '@/types';

/**
 * A billing report rendered like the document the client gets (v1.38.0, BRAI-101): groups per
 * project → lines, subtotal, VAT, total. Renders snapshot v1 as it is (a draft's live preview or a
 * finalized report's frozen snapshot) and never recomputes money. Each line expands into its time
 * entries; on a draft every entry can be left out. The table scrolls inside its card on phones.
 */
export function ReportDocument({ snapshot, rows, onLeaveOut, busy = false }: {
    snapshot: BillingReportSnapshot;
    /** entries by id for the expandable lists (a draft: all, with person + note) */
    rows: BillingReportRow[];
    /** draft only: leave an entry out of the report */
    onLeaveOut?: (entryId: number) => void;
    busy?: boolean;
}) {
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const byId = new Map(rows.map((row) => [row.id, row]));
    const money = (cents: number) => formatMoney(cents, snapshot.currency);
    const showPerson = snapshot.groups.some((g) => g.lines.some((l) => l.person));
    const columns = showPerson ? 6 : 5;
    const kindLabel = snapshot.kind === 'invoice' ? 'Invoice' : 'Time report';
    const { totals } = snapshot;

    return (
        <div className="overflow-x-auto rounded-xl border bg-card text-card-foreground shadow-sm" data-testid="report-document">
            <div className="min-w-[640px] space-y-6 p-5 sm:p-8">
                <header className="flex flex-wrap items-start justify-between gap-6">
                    <div className="min-w-0 space-y-1">
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {kindLabel}{snapshot.number ? ` ${snapshot.number}` : ''}
                        </p>
                        <h2 className="text-xl font-semibold">{snapshot.title}</h2>
                        <p className="text-sm text-muted-foreground">
                            Period {formatPeriod(snapshot.period.from, snapshot.period.to)} · Issued {formatDay(snapshot.issued_on)}
                        </p>
                    </div>
                    <div className="grid gap-4 text-sm sm:grid-cols-2 sm:text-right">
                        <div>
                            <p className="text-xs font-medium text-muted-foreground">To</p>
                            <p className="font-medium">{snapshot.client.name || '—'}</p>
                            {snapshot.client.address && <p className="whitespace-pre-line text-muted-foreground">{snapshot.client.address}</p>}
                        </div>
                        <div>
                            <p className="text-xs font-medium text-muted-foreground">From</p>
                            <p className="font-medium">{snapshot.issuer.company_name || <span className="italic text-muted-foreground">Company name missing</span>}</p>
                            {snapshot.issuer.address && <p className="whitespace-pre-line text-muted-foreground">{snapshot.issuer.address}</p>}
                        </div>
                    </div>
                </header>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <th className="py-2 pr-3 font-medium">Description</th>
                            <th className="py-2 pr-3 font-medium">Activity</th>
                            {showPerson && <th className="py-2 pr-3 font-medium">Person</th>}
                            <th className="py-2 pr-3 text-right font-medium">Hours</th>
                            <th className="py-2 pr-3 text-right font-medium">Rate</th>
                            <th className="py-2 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {snapshot.groups.length === 0 && (
                            <tr>
                                <td colSpan={columns} className="py-10 text-center text-muted-foreground">Nothing to bill in this period.</td>
                            </tr>
                        )}
                        {snapshot.groups.map((group) => (
                            <Fragment key={group.project.id}>
                                <tr>
                                    <th colSpan={columns} scope="colgroup" className="pb-1 pt-5 text-left text-sm font-semibold">
                                        {group.project.name}
                                        {group.project.key && <span className="ml-2 font-mono text-xs font-normal text-muted-foreground">{group.project.key}</span>}
                                    </th>
                                </tr>
                                {group.lines.map((line, index) => {
                                    const key = `${group.project.id}-${index}`;
                                    const expanded = !!open[key];
                                    return (
                                        <Fragment key={key}>
                                            <LineRow
                                                line={line}
                                                groupBy={snapshot.options.group_by}
                                                showPerson={showPerson}
                                                money={money}
                                                expanded={expanded}
                                                onToggle={() => setOpen((o) => ({ ...o, [key]: !o[key] }))}
                                            />
                                            {expanded && (
                                                <tr className="bg-muted/40">
                                                    <td colSpan={columns} className="px-2 py-2">
                                                        <ul className="divide-y divide-border/60" aria-label={`Time entries of ${lineLabel(line)}`}>
                                                            {line.entry_ids.map((id) => {
                                                                const row = byId.get(id);
                                                                return (
                                                                    <li key={id} className="flex items-center gap-3 py-1.5 text-xs">
                                                                        <span className="w-24 shrink-0 tabular-nums text-muted-foreground">{row ? formatDay(row.date) : `#${id}`}</span>
                                                                        <span className="w-32 shrink-0 truncate">{row?.person ?? ''}</span>
                                                                        <span className="w-16 shrink-0 text-right tabular-nums">{row ? formatHours(row.minutes) : ''}</span>
                                                                        <span className="min-w-0 flex-1 truncate text-muted-foreground">{row?.note ?? ''}</span>
                                                                        {onLeaveOut && (
                                                                            <Button
                                                                                type="button"
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                className="h-7 shrink-0 px-2 text-xs"
                                                                                disabled={busy}
                                                                                onClick={() => onLeaveOut(id)}
                                                                                aria-label={`Leave out ${row ? `${row.person ?? ''} ${formatDay(row.date)} ${formatHours(row.minutes)}` : `entry ${id}`}`}
                                                                            >
                                                                                <MinusCircle className="size-3.5" /> Leave out
                                                                            </Button>
                                                                        )}
                                                                    </li>
                                                                );
                                                            })}
                                                        </ul>
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                                <tr className="border-t text-muted-foreground">
                                    <td colSpan={showPerson ? 3 : 2} className="py-1.5 pr-3 text-xs">Subtotal {group.project.name}</td>
                                    <td className="py-1.5 pr-3 text-right text-xs tabular-nums">{formatHours(group.billed_minutes)}</td>
                                    <td />
                                    <td className="py-1.5 text-right text-xs tabular-nums">{money(group.net_cents)}</td>
                                </tr>
                            </Fragment>
                        ))}
                    </tbody>
                </table>

                <dl className="ml-auto w-full max-w-xs space-y-1.5 border-t pt-4 text-sm" aria-label="Totals">
                    {snapshot.prices_include_tax ? (
                        <>
                            <TotalRow label="Net" value={money(totals.net_cents)} />
                            <TotalRow label={`incl. VAT ${formatTaxRate(snapshot.tax_rate_bp)}`} value={money(totals.tax_cents)} />
                        </>
                    ) : (
                        <>
                            <TotalRow label="Subtotal" value={money(totals.net_cents)} />
                            <TotalRow label={`VAT ${formatTaxRate(snapshot.tax_rate_bp)}`} value={money(totals.tax_cents)} />
                        </>
                    )}
                    <TotalRow label="Total" value={money(totals.gross_cents)} strong testId="report-total" />
                    <p className="pt-1 text-right text-xs text-muted-foreground">
                        {formatHours(totals.billed_minutes)} billed
                        {totals.billed_minutes !== totals.minutes && ` (${formatHours(totals.minutes)} logged, rounded up to ${snapshot.rounding_minutes} min per entry)`}
                    </p>
                </dl>
            </div>
        </div>
    );
}

function lineLabel(line: BillingSnapshotLine): string {
    return [line.item_key ?? line.item_title, line.activity.name, line.person].filter(Boolean).join(' ');
}

function LineRow({ line, groupBy, showPerson, money, expanded, onToggle }: {
    line: BillingSnapshotLine;
    groupBy: BillingGroupBy;
    showPerson: boolean;
    money: (cents: number) => string;
    expanded: boolean;
    onToggle: () => void;
}) {
    const fixed = line.activity.pricing_type === 'fixed';
    return (
        <tr className="border-t border-border/60 align-top" data-testid="report-line">
            <td className="py-2 pr-3">
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={expanded}
                    aria-label={`${expanded ? 'Hide' : 'Show'} entries of ${lineLabel(line)}`}
                    className="flex items-start gap-1.5 text-left hover:text-primary"
                >
                    <ChevronRight className={cn('mt-0.5 size-3.5 shrink-0 text-muted-foreground transition-transform', expanded && 'rotate-90')} />
                    <span className="min-w-0">
                        {line.item_key && <span className="mr-1.5 font-mono text-xs text-muted-foreground">{line.item_key}</span>}
                        {line.item_title ?? (line.item_key ? '' : (groupBy === 'person' ? line.person : line.activity.name))}
                    </span>
                </button>
                {line.notes.length > 0 && (
                    <ul className="ml-5 mt-1 list-disc space-y-0.5 pl-3 text-xs text-muted-foreground">
                        {line.notes.map((note) => <li key={note}>{note}</li>)}
                    </ul>
                )}
            </td>
            <td className="py-2 pr-3">
                {line.activity.name}
                {line.fixed_already_billed && <span className="mt-0.5 block text-xs text-muted-foreground">Fixed price, already billed</span>}
            </td>
            {showPerson && <td className="py-2 pr-3 text-muted-foreground">{line.person}</td>}
            <td className="py-2 pr-3 text-right tabular-nums">
                {formatHours(line.billed_minutes)}
                {line.billed_minutes !== line.minutes && <span className="block text-xs text-muted-foreground">{formatHours(line.minutes)} logged</span>}
            </td>
            <td className="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-muted-foreground">
                {fixed ? `${money(line.rate_cents)} flat` : `${money(line.rate_cents)}/h`}
            </td>
            <td className="whitespace-nowrap py-2 text-right font-medium tabular-nums">{money(line.amount_cents)}</td>
        </tr>
    );
}

function TotalRow({ label, value, strong, testId }: { label: string; value: string; strong?: boolean; testId?: string }) {
    return (
        <div className={cn('flex justify-between gap-6', strong && 'border-t pt-1.5 text-base font-semibold')}>
            <dt>{label}</dt>
            <dd className="tabular-nums" data-testid={testId}>{value}</dd>
        </div>
    );
}
