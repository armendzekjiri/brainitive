import { useState, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { AlertTriangle, ArrowLeft, Ban, CheckCircle2, FileSpreadsheet, FileText, Info, Lock, Send, Trash2, Undo2, Wallet } from 'lucide-react';
import BillingLayout from '@/Layouts/BillingLayout';
import { ReportDocument } from '@/components/billing/reports/ReportDocument';
import { ReportOptionsPanel, ReportStatusBadge, SendReportDialog, draftPatch, type DraftPatch } from '@/components/billing/reports/ReportBits';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { formatDay, formatHours, formatMoney, formatPeriod } from '@/lib/billingReports';
import { cn } from '@/lib/utils';
import type { BillingReportDetail, BillingReportRow, BillingReportSnapshot, BillingReportWarnings, PageProps } from '@/types';

interface Props {
    report: BillingReportDetail;
    /** draft: the live preview; otherwise the snapshot frozen at finalize */
    preview: BillingReportSnapshot | null;
    rows: BillingReportRow[];
    excluded: BillingReportRow[];
    /** draft: the entries the admin reviews; finalize sends them back */
    entryIds: number[];
    warnings: BillingReportWarnings | null;
    projects: { id: number; name: string; key: string | null }[];
    settings: { invoice_mode: boolean; rounding_minutes: number; company_name: string | null };
}

type Confirm = 'finalize' | 'delete' | 'void' | null;

/** One billing report (v1.38.0, BRAI-101): draft builder with live preview, or the finalized document. */
export default function BillingReportShow({ report, preview, rows, excluded, entryIds, warnings, projects, settings }: Props) {
    const { currentWorkspace, errors } = usePage<PageProps & { errors: Record<string, string> }>().props;
    const workspace = currentWorkspace?.slug ?? '';
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const [paidConfirmed, setPaidConfirmed] = useState(false);
    const [sending, setSending] = useState(false);
    const isDraft = report.status === 'draft';
    const isFinal = ['finalized', 'sent', 'paid'].includes(report.status);
    const url = (name: string) => route(name, [workspace, report.id]);
    const money = (cents: number) => formatMoney(cents, report.currency);

    const visit = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const flashToast = (page: { props: unknown }) => {
        const message = (page.props as PageProps).flash?.success;
        if (message) toast.success(message);
    };

    const save = (change: Partial<DraftPatch>) => router.patch(url('billing.reports.update'), { ...draftPatch(report, change) }, { ...visit, preserveState: true });
    const leaveOut = (ids: number[]) => save({ excluded_entry_ids: [...new Set([...report.options.excluded_entry_ids, ...ids])] });
    const includeAgain = (id: number) => save({ excluded_entry_ids: report.options.excluded_entry_ids.filter((x) => x !== id) });
    const noActivityIds = rows.filter((r) => r.billable && r.activity === 'No activity').map((r) => r.id);

    const finalize = () => router.post(url('billing.reports.finalize'), { entry_ids: entryIds }, { ...visit, onSuccess: flashToast });
    const destroy = () => router.delete(url('billing.reports.destroy'), { ...visit, onSuccess: flashToast });
    const markPaid = () => router.post(url('billing.reports.paid'), {}, { ...visit, onSuccess: flashToast });
    const voidReport = () => router.post(url('billing.reports.void'), { confirm: paidConfirmed }, { ...visit, onSuccess: flashToast });

    const documentActions = (
        <>
            {route().has('billing.reports.pdf') && (
                <Button variant="outline" size="sm" asChild>
                    <a href={url('billing.reports.pdf')}><FileText className="size-4" /> PDF</a>
                </Button>
            )}
            {route().has('billing.reports.xlsx') && (
                <Button variant="outline" size="sm" asChild>
                    <a href={url('billing.reports.xlsx')}><FileSpreadsheet className="size-4" /> Excel</a>
                </Button>
            )}
        </>
    );

    return (
        <BillingLayout
            title={report.title ?? 'Report'}
            description={<Link href={route('billing.reports.index', workspace)} className="inline-flex items-center gap-1 hover:text-foreground"><ArrowLeft className="size-3.5" /> All reports</Link>}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="truncate text-lg font-semibold">{report.title}</h2>
                        <ReportStatusBadge status={report.status} />
                        {report.number && <span className="font-mono text-sm text-muted-foreground">{report.kind === 'invoice' ? 'Invoice' : 'No.'} {report.number}</span>}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {report.client?.name ?? 'Deleted client'} · {formatPeriod(report.period_from, report.period_to)}
                    </p>
                    <StatusTrail report={report} />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {documentActions}
                    {isDraft && (
                        <>
                            <Button variant="ghost" size="sm" className="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" onClick={() => setConfirm('delete')} disabled={busy}>
                                <Trash2 className="size-4" /> Delete draft
                            </Button>
                            <Button size="sm" onClick={() => setConfirm('finalize')} disabled={busy || entryIds.length === 0}>
                                <Lock className="size-4" /> Finalize
                            </Button>
                        </>
                    )}
                    {isFinal && route().has('billing.reports.send') && (
                        <Button variant="outline" size="sm" onClick={() => setSending(true)} disabled={busy}><Send className="size-4" /> Send to client</Button>
                    )}
                    {(report.status === 'finalized' || report.status === 'sent') && (
                        <Button variant="outline" size="sm" onClick={markPaid} disabled={busy}><Wallet className="size-4" /> Mark as paid</Button>
                    )}
                    {isFinal && (
                        <Button variant="ghost" size="sm" className="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" onClick={() => { setPaidConfirmed(false); setConfirm('void'); }} disabled={busy}>
                            <Ban className="size-4" /> Void
                        </Button>
                    )}
                </div>
            </div>

            {errors.report && (
                <Notice tone="error" icon={<AlertTriangle className="size-4" />}>{errors.report}</Notice>
            )}

            {isDraft && warnings && (
                <div className="space-y-2">
                    {warnings.nothing_to_bill && (
                        <Notice tone="info" icon={<Info className="size-4" />}>
                            Nothing to bill: there is no unbilled time for these projects in this period.
                        </Notice>
                    )}
                    {warnings.without_activity > 0 && (
                        <Notice tone="warning" icon={<AlertTriangle className="size-4" />} action={
                            <Button variant="outline" size="sm" className="h-7" onClick={() => leaveOut(noActivityIds)} disabled={busy}>Leave them out</Button>
                        }>
                            {warnings.without_activity} {warnings.without_activity === 1 ? 'entry' : 'entries'} without activity — not billed.
                        </Notice>
                    )}
                    {warnings.settings_incomplete && (
                        <Notice tone="warning" icon={<AlertTriangle className="size-4" />}>
                            Your company name is missing, so the document has no sender.{' '}
                            {route().has('billing.settings.edit') && <Link href={route('billing.settings.edit', workspace)} className="font-medium underline underline-offset-2">Open billing settings</Link>}
                        </Notice>
                    )}
                    {warnings.non_billable > 0 && (
                        <p className="text-xs text-muted-foreground">
                            {warnings.non_billable} non-billable {warnings.non_billable === 1 ? 'entry is' : 'entries are'} listed in the details but not charged.
                        </p>
                    )}
                </div>
            )}

            {report.status === 'void' && (
                <Notice tone="info" icon={<Undo2 className="size-4" />}>
                    This report was voided. Its time entries can be billed again{report.number ? `; the number ${report.number} stays used` : ''}.
                </Notice>
            )}

            <div className={cn('grid gap-6', isDraft && 'lg:grid-cols-[minmax(0,1fr)_17rem]')}>
                <div className="min-w-0 space-y-4">
                    {preview ? (
                        <ReportDocument snapshot={preview} rows={rows} onLeaveOut={isDraft ? (id) => leaveOut([id]) : undefined} busy={busy} />
                    ) : (
                        <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">No document stored for this report.</p>
                    )}

                    {isDraft && excluded.length > 0 && (
                        <section className="rounded-xl border bg-card p-4 shadow-sm" aria-label="Left out">
                            <h3 className="text-sm font-semibold">Left out ({excluded.length})</h3>
                            <p className="text-xs text-muted-foreground">Not in this report and not locked: they stay billable later.</p>
                            <ul className="mt-2 divide-y text-sm">
                                {excluded.map((row) => (
                                    <li key={row.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5">
                                        <span className="w-24 tabular-nums text-muted-foreground">{formatDay(row.date)}</span>
                                        <span className="w-32 truncate">{row.person}</span>
                                        <span className="min-w-0 flex-1 truncate">
                                            {row.item_key && <span className="mr-1 font-mono text-xs text-muted-foreground">{row.item_key}</span>}
                                            {row.item_title ?? 'No ticket'} · {row.activity}
                                        </span>
                                        <span className="tabular-nums">{formatHours(row.minutes)}</span>
                                        <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => includeAgain(row.id)} disabled={busy}
                                            aria-label={`Include again ${row.person ?? ''} ${formatDay(row.date)}`}>
                                            Include again
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>

                {isDraft && (
                    <aside className="space-y-3">
                        <ReportOptionsPanel report={report} projects={projects} onChange={save} busy={busy} />
                        <p className="px-1 text-xs text-muted-foreground">
                            {settings.invoice_mode ? 'Invoice mode: finalizing takes the next invoice number.' : 'Time report: no invoice number.'}
                            {settings.rounding_minutes > 0 ? ` Each entry is rounded up to ${settings.rounding_minutes} min.` : ' Exact minutes, no rounding.'}
                        </p>
                    </aside>
                )}
            </div>

            <AlertDialog open={confirm === 'finalize'} onOpenChange={(open) => !open && setConfirm(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Finalize this report?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {entryIds.length} time {entryIds.length === 1 ? 'entry gets' : 'entries get'} locked: nobody can edit, move or delete {entryIds.length === 1 ? 'it' : 'them'} anymore,
                            and no other report can bill {entryIds.length === 1 ? 'it' : 'them'} again.
                            {settings.invoice_mode && ' The report gets the next invoice number.'} Total {preview ? money(preview.totals.gross_cents) : ''}.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={finalize}>Finalize</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={confirm === 'delete'} onOpenChange={(open) => !open && setConfirm(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this draft?</AlertDialogTitle>
                        <AlertDialogDescription>Only the draft goes away. The time entries stay unbilled.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={destroy} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">Delete draft</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={confirm === 'void'} onOpenChange={(open) => !open && setConfirm(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Void this report?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Its time entries are unlocked and can be billed again; fixed prices it charged are freed.
                            The document stays for the record{report.number ? ` and the number ${report.number} is never reused` : ''}.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {report.status === 'paid' && (
                        <label className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                            <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={paidConfirmed} onChange={(e) => setPaidConfirmed(e.target.checked)} />
                            This report is marked as paid. Void it anyway.
                        </label>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={voidReport} disabled={report.status === 'paid' && !paidConfirmed} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">Void report</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {isFinal && route().has('billing.reports.send') && (
                <SendReportDialog open={sending} onOpenChange={setSending} url={url('billing.reports.send')} report={report} />
            )}
        </BillingLayout>
    );
}

function StatusTrail({ report }: { report: BillingReportDetail }) {
    const steps = [
        report.finalized_at && `Finalized ${formatDay(report.finalized_at.slice(0, 10))}`,
        report.sent_at && `sent ${formatDay(report.sent_at.slice(0, 10))}${report.sent_to ? ` to ${report.sent_to}` : ''}`,
        report.paid_at && `paid ${formatDay(report.paid_at.slice(0, 10))}`,
        report.voided_at && `voided ${formatDay(report.voided_at.slice(0, 10))}`,
    ].filter(Boolean);
    if (steps.length === 0) return null;
    return (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <CheckCircle2 className="size-3.5" /> {steps.join(' · ')}
        </p>
    );
}

function Notice({ tone, icon, action, children }: { tone: 'info' | 'warning' | 'error'; icon: ReactNode; action?: ReactNode; children: ReactNode }) {
    return (
        <div
            role={tone === 'error' ? 'alert' : 'status'}
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2 text-sm',
                tone === 'info' && 'border-border bg-muted/50 text-foreground',
                tone === 'warning' && 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200',
                tone === 'error' && 'border-destructive/40 bg-destructive/10 text-destructive',
            )}
        >
            <span className="shrink-0">{icon}</span>
            <span className="min-w-0 flex-1">{children}</span>
            {action}
        </div>
    );
}
