import { useState, type FormEvent } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FileText, Plus } from 'lucide-react';
import BillingLayout from '@/Layouts/BillingLayout';
import { ReportStatusBadge } from '@/components/billing/reports/ReportBits';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { STATUS_LABELS, formatDay, formatHours, formatMoney, formatPeriod } from '@/lib/billingReports';
import type { BillingReportStatus, BillingReportSummary, PageProps, Paginated } from '@/types';

interface ClientOption {
    id: number;
    name: string;
    archived: boolean;
    projects_count: number;
}

interface Props {
    reports: Paginated<BillingReportSummary>;
    clients: ClientOption[];
    filters: { status: BillingReportStatus | null; client: number | null };
    defaultPeriod: { from: string; to: string };
}

const ALL = 'all';

/** Workspace → Billing → Reports (v1.38.0, BRAI-101): every report and invoice, newest first. */
export default function BillingReportsIndex({ reports, clients, filters, defaultPeriod }: Props) {
    const { currentWorkspace } = usePage<PageProps>().props;
    const workspace = currentWorkspace?.slug ?? '';
    const [creating, setCreating] = useState(false);
    const billable = clients.filter((c) => !c.archived && c.projects_count > 0);

    const filter = (change: Partial<Props['filters']>) => {
        const next = { ...filters, ...change };
        router.get(route('billing.reports.index', workspace), {
            ...(next.status ? { status: next.status } : {}),
            ...(next.client ? { client: next.client } : {}),
        }, { preserveScroll: true, preserveState: true, replace: true });
    };

    return (
        <BillingLayout
            title="Reports"
            description="Time reports and invoices for your clients, built from the hours your team logged."
            actions={(
                <Button onClick={() => setCreating(true)} disabled={billable.length === 0}>
                    <Plus className="size-4" /> New report
                </Button>
            )}
        >
            <div className="flex flex-wrap items-center gap-2">
                <Select value={filters.status ?? ALL} onValueChange={(v) => filter({ status: v === ALL ? null : (v as BillingReportStatus) })}>
                    <SelectTrigger className="w-40" aria-label="Status"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All statuses</SelectItem>
                        {(Object.keys(STATUS_LABELS) as BillingReportStatus[]).map((s) => <SelectItem key={s} value={s}>{STATUS_LABELS[s]}</SelectItem>)}
                    </SelectContent>
                </Select>
                <Select value={filters.client ? String(filters.client) : ALL} onValueChange={(v) => filter({ client: v === ALL ? null : Number(v) })}>
                    <SelectTrigger className="w-52" aria-label="Client"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All clients</SelectItem>
                        {clients.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}{c.archived ? ' (archived)' : ''}</SelectItem>)}
                    </SelectContent>
                </Select>
            </div>

            {billable.length === 0 && (
                <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                    Reports are made per client. Create a client and assign it to a project first
                    {route().has('billing.clients.index') && <> under <Link href={route('billing.clients.index', workspace)} className="text-primary underline-offset-2 hover:underline">Clients</Link></>}.
                </p>
            )}

            {reports.data.length === 0 ? (
                <div className="rounded-xl border border-dashed p-10 text-center">
                    <FileText className="mx-auto size-8 text-muted-foreground" />
                    <p className="mt-2 font-medium">{filters.status || filters.client ? 'No reports match these filters.' : 'No reports yet'}</p>
                    {!filters.status && !filters.client && <p className="mt-1 text-sm text-muted-foreground">Pick a client and a period; you check the lines before anything is final.</p>}
                </div>
            ) : (
                <ul className="divide-y rounded-xl border bg-card shadow-sm" aria-label="Reports">
                    {reports.data.map((report) => (
                        <li key={report.id}>
                            <Link
                                href={route('billing.reports.show', [workspace, report.id])}
                                className="grid gap-x-4 gap-y-1 p-4 transition-colors hover:bg-muted/50 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_auto_auto] sm:items-center"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {report.number && <span className="font-mono text-xs text-muted-foreground">{report.number}</span>}
                                        <span className="truncate font-medium">{report.title ?? 'Untitled report'}</span>
                                        <ReportStatusBadge status={report.status} />
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {formatPeriod(report.period_from, report.period_to)}
                                        {report.created_at && ` · created ${formatDay(report.created_at.slice(0, 10))}`}
                                    </p>
                                </div>
                                <span className="truncate text-sm">{report.client?.name ?? 'Deleted client'}</span>
                                <span className="flex items-baseline justify-between gap-4 sm:contents">
                                    <span className="text-sm text-muted-foreground sm:text-right">{formatHours(report.total_minutes)}</span>
                                    <span className="text-sm font-medium tabular-nums sm:w-28 sm:text-right" title={report.status === 'draft' ? 'Preview total of the draft' : undefined}>
                                        {formatMoney(report.total_cents, report.currency)}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {reports.last_page > 1 && (
                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>{reports.from}–{reports.to} of {reports.total}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!reports.prev_page_url} onClick={() => reports.prev_page_url && router.get(reports.prev_page_url, {}, { preserveScroll: true })}>Previous</Button>
                        <Button variant="outline" size="sm" disabled={!reports.next_page_url} onClick={() => reports.next_page_url && router.get(reports.next_page_url, {}, { preserveScroll: true })}>Next</Button>
                    </div>
                </div>
            )}

            <NewReportDialog open={creating} onOpenChange={setCreating} clients={billable} defaultPeriod={defaultPeriod} workspace={workspace} />
        </BillingLayout>
    );
}

function NewReportDialog({ open, onOpenChange, clients, defaultPeriod, workspace }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clients: ClientOption[];
    defaultPeriod: { from: string; to: string };
    workspace: string;
}) {
    const form = useForm({ client_id: clients.length === 1 ? String(clients[0].id) : '', period_from: defaultPeriod.from, period_to: defaultPeriod.to });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('billing.reports.store', workspace), { onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>New report</DialogTitle>
                    <DialogDescription>Unbilled time of the client’s projects in this period. You can check and adjust everything before finalizing.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="new-report-client">Client</Label>
                        <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                            <SelectTrigger id="new-report-client"><SelectValue placeholder="Choose a client" /></SelectTrigger>
                            <SelectContent>
                                {clients.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        {form.errors.client_id && <p className="text-sm text-destructive">{form.errors.client_id}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="new-report-from">From</Label>
                            <Input id="new-report-from" type="date" value={form.data.period_from} onChange={(e) => form.setData('period_from', e.target.value)} required />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="new-report-to">To</Label>
                            <Input id="new-report-to" type="date" value={form.data.period_to} onChange={(e) => form.setData('period_to', e.target.value)} required />
                        </div>
                    </div>
                    {(form.errors.period_from || form.errors.period_to) && <p className="text-sm text-destructive">{form.errors.period_from ?? form.errors.period_to}</p>}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing || !form.data.client_id}>Create draft</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
