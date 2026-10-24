import { type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { Loader2, Mail, Paperclip } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

/**
 * "Send to client" (v1.38.0, BRAI-101): mails a finalized billing report as a PDF attachment
 * (POST billing.reports.send; queued, reply-to the signed-in admin). Self-contained: give it the
 * workspace slug, the report id and the defaults; the server refuses drafts and void reports.
 */
export interface SendReportDialogProps {
    workspaceSlug: string;
    reportId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Usually the client's email. */
    defaultTo?: string | null;
    /** e.g. "Invoice 2026-014 · Acme Studio". */
    defaultSubject?: string | null;
    defaultMessage?: string | null;
    /** Shown as the attachment, e.g. "time-report-2026-10-muster-gmbh.pdf". */
    attachmentName?: ReactNode;
    onSent?: () => void;
}

interface SendForm {
    to: string;
    cc: string;
    subject: string;
    message: string;
}

const DEFAULT_MESSAGE = 'Hello,\n\nplease find attached the report for this period.\n\nKind regards';

/**
 * The dialog content unmounts when closed (Radix), so every open starts a fresh form with the
 * current defaults, and a parent re-render never wipes what the user typed.
 */
export default function SendReportDialog({ open, onOpenChange, ...props }: SendReportDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <SendReportForm {...props} onClose={() => onOpenChange(false)} />
            </DialogContent>
        </Dialog>
    );
}

function SendReportForm({
    workspaceSlug,
    reportId,
    defaultTo,
    defaultSubject,
    defaultMessage,
    attachmentName,
    onSent,
    onClose,
}: Omit<SendReportDialogProps, 'open' | 'onOpenChange'> & { onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm<SendForm>({
        to: defaultTo ?? '',
        cc: '',
        subject: defaultSubject ?? '',
        message: defaultMessage ?? DEFAULT_MESSAGE,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('billing.reports.send', { workspace: workspaceSlug, billingReport: reportId }), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Sending to ${data.to || 'the client'}…`);
                onClose();
                onSent?.();
            },
        });
    };

    const statusError = (errors as Record<string, string | undefined>).status;
    const described = (field: keyof SendForm, hint?: boolean) =>
        errors[field] ? `send-report-${field}-error` : hint ? `send-report-${field}-hint` : undefined;

    return (
        <form onSubmit={submit} noValidate className="space-y-4">
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Mail className="size-5 text-primary" />
                    Send to client
                </DialogTitle>
                <DialogDescription>
                    The PDF is attached. Replies come back to your own email address.
                </DialogDescription>
            </DialogHeader>

            {statusError && <p className="rounded-lg border border-destructive/30 bg-destructive/10 p-2 text-sm text-destructive" role="alert">{statusError}</p>}

            <div className="space-y-1.5">
                <Label htmlFor="send-report-to">To</Label>
                <Input id="send-report-to" type="email" autoComplete="email" value={data.to} aria-invalid={!!errors.to} aria-describedby={described('to')} onChange={(event) => setData('to', event.target.value)} required />
                {errors.to && <p id="send-report-to-error" className="text-xs text-destructive">{errors.to}</p>}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="send-report-cc">Cc <span className="font-normal text-muted-foreground">(optional)</span></Label>
                <Input id="send-report-cc" value={data.cc} placeholder="name@example.com, …" aria-invalid={!!errors.cc} aria-describedby={described('cc', true)} onChange={(event) => setData('cc', event.target.value)} />
                {errors.cc
                    ? <p id="send-report-cc-error" className="text-xs text-destructive">{errors.cc}</p>
                    : <p id="send-report-cc-hint" className="text-xs text-muted-foreground">Up to 5 addresses, separated by commas.</p>}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="send-report-subject">Subject</Label>
                <Input id="send-report-subject" value={data.subject} maxLength={200} aria-invalid={!!errors.subject} aria-describedby={described('subject')} onChange={(event) => setData('subject', event.target.value)} required />
                {errors.subject && <p id="send-report-subject-error" className="text-xs text-destructive">{errors.subject}</p>}
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="send-report-message">Message</Label>
                <Textarea id="send-report-message" rows={6} value={data.message} maxLength={5000} aria-invalid={!!errors.message} aria-describedby={described('message')} onChange={(event) => setData('message', event.target.value)} />
                {errors.message && <p id="send-report-message-error" className="text-xs text-destructive">{errors.message}</p>}
            </div>
            {attachmentName && (
                <p className="flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                    <Paperclip className="size-3.5 shrink-0" />
                    <span className="truncate">{attachmentName}</span>
                </p>
            )}

            <DialogFooter className="gap-2 sm:gap-0">
                <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                <Button type="submit" disabled={processing}>
                    {processing ? <Loader2 className="size-4 animate-spin" /> : <Mail className="size-4" />}
                    Send
                </Button>
            </DialogFooter>
        </form>
    );
}
