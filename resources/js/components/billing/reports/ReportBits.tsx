import { useEffect, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { STATUS_CLASSES, STATUS_LABELS } from '@/lib/billingReports';
import { cn } from '@/lib/utils';
import type { BillingGroupBy, BillingReportDetail, BillingReportOptions, BillingReportStatus } from '@/types';

/** Shared bits of the billing report pages (v1.38.0, BRAI-101 reports). */

export function ReportStatusBadge({ status, className }: { status: BillingReportStatus; className?: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', STATUS_CLASSES[status], className)}>
            {STATUS_LABELS[status]}
        </span>
    );
}

/** Everything the draft PATCH takes (billing.reports.update). */
export interface DraftPatch extends BillingReportOptions {
    title: string;
    period_from: string;
    period_to: string;
}

export function draftPatch(report: BillingReportDetail, change: Partial<DraftPatch> = {}): DraftPatch {
    return {
        title: report.title ?? '',
        period_from: report.period_from,
        period_to: report.period_to,
        ...report.options,
        ...change,
    };
}

const GROUP_BY_LABELS: Record<BillingGroupBy, string> = { ticket: 'Ticket', activity: 'Activity', person: 'Person' };

/**
 * The draft's options. Every change is saved at once and the preview recomputes (title and dates on
 * blur / change); the parent does the request.
 */
export function ReportOptionsPanel({ report, projects, onChange, busy }: {
    report: BillingReportDetail;
    projects: { id: number; name: string; key: string | null }[];
    onChange: (change: Partial<DraftPatch>) => void;
    busy: boolean;
}) {
    const options = report.options;
    const [title, setTitle] = useState(report.title ?? '');
    useEffect(() => setTitle(report.title ?? ''), [report.title]);
    const selected = (id: number) => options.project_ids === null || options.project_ids.includes(id);

    const toggleProject = (id: number, on: boolean) => {
        const ids = projects.map((p) => p.id).filter((pid) => (pid === id ? on : selected(pid)));
        onChange({ project_ids: ids.length === projects.length ? null : ids });
    };

    return (
        <section className="space-y-5 rounded-xl border bg-card p-4 text-sm shadow-sm" aria-label="Report options">
            <h2 className="font-semibold">Options</h2>

            <div className="space-y-1.5">
                <Label htmlFor="report-title">Title</Label>
                <Input
                    id="report-title"
                    value={title}
                    maxLength={160}
                    onChange={(e) => setTitle(e.target.value)}
                    onBlur={() => title !== (report.title ?? '') && onChange({ title })}
                    onKeyDown={(e) => e.key === 'Enter' && (e.currentTarget as HTMLInputElement).blur()}
                />
            </div>

            <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1.5">
                    <Label htmlFor="report-from">From</Label>
                    <Input id="report-from" type="date" value={report.period_from} max={report.period_to} onChange={(e) => e.target.value && onChange({ period_from: e.target.value })} disabled={busy} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="report-to">To</Label>
                    <Input id="report-to" type="date" value={report.period_to} min={report.period_from} onChange={(e) => e.target.value && onChange({ period_to: e.target.value })} disabled={busy} />
                </div>
            </div>

            <fieldset className="space-y-1.5">
                <legend className="mb-1.5 text-sm font-medium">Projects</legend>
                {projects.length === 0 && <p className="text-muted-foreground">This client has no projects.</p>}
                {projects.map((project) => (
                    <label key={project.id} className="flex items-center gap-2">
                        <input type="checkbox" className="size-4 rounded border-input accent-primary" checked={selected(project.id)} disabled={busy} onChange={(e) => toggleProject(project.id, e.target.checked)} />
                        <span className="truncate">{project.name}</span>
                        {project.key && <span className="font-mono text-xs text-muted-foreground">{project.key}</span>}
                    </label>
                ))}
            </fieldset>

            <div className="space-y-1.5">
                <Label htmlFor="report-group-by">Group lines by</Label>
                <Select value={options.group_by} onValueChange={(value) => onChange({ group_by: value as BillingGroupBy })} disabled={busy}>
                    <SelectTrigger id="report-group-by"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        {(Object.keys(GROUP_BY_LABELS) as BillingGroupBy[]).map((g) => <SelectItem key={g} value={g}>{GROUP_BY_LABELS[g]}</SelectItem>)}
                    </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">Fixed prices always stay one line per ticket.</p>
            </div>

            <div className="space-y-2">
                <label className="flex items-center gap-2">
                    <input type="checkbox" className="size-4 rounded border-input accent-primary" checked={options.show_people} disabled={busy} onChange={(e) => onChange({ show_people: e.target.checked })} />
                    Show people
                </label>
                <label className="flex items-center gap-2">
                    <input type="checkbox" className="size-4 rounded border-input accent-primary" checked={options.show_notes} disabled={busy} onChange={(e) => onChange({ show_notes: e.target.checked })} />
                    Show notes
                </label>
            </div>
        </section>
    );
}

/**
 * Send the report to the client (billing.reports.send, built by the documents part). Kept simple and
 * self-contained on purpose: to / subject / message.
 */
export function SendReportDialog({ open, onOpenChange, url, report }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    url: string;
    report: BillingReportDetail;
}) {
    const defaults = () => ({
        to: report.sent_to ?? report.client_email ?? '',
        subject: [report.kind === 'invoice' ? `Invoice ${report.number ?? ''}`.trim() : null, report.title].filter(Boolean).join(' · '),
        message: '',
    });
    const form = useForm(defaults());

    useEffect(() => {
        if (open) {
            form.setData(defaults());
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                toast.success('Sent to the client.');
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Send to client</DialogTitle>
                    <DialogDescription>The PDF goes along as an attachment. Replies come to you.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="send-to">To</Label>
                        <Input id="send-to" type="email" value={form.data.to} onChange={(e) => form.setData('to', e.target.value)} required maxLength={255} />
                        {form.errors.to && <p className="text-sm text-destructive">{form.errors.to}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="send-subject">Subject</Label>
                        <Input id="send-subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} required maxLength={200} />
                        {form.errors.subject && <p className="text-sm text-destructive">{form.errors.subject}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="send-message">Message</Label>
                        <Textarea id="send-message" rows={5} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} maxLength={5000} placeholder="Hello, please find attached …" />
                        {form.errors.message && <p className="text-sm text-destructive">{form.errors.message}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing || !form.data.to.trim()}>Send</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
