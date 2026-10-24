import { useState, type ReactNode } from 'react';
import { FileSpreadsheet, FileText, Send } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import SendReportDialog from '@/components/billing/SendReportDialog';
import type { BillingReportStatus } from '@/types';

/**
 * Document actions of a billing report (v1.38.0, BRAI-101): PDF and Excel downloads
 * (billing.reports.pdf / .xlsx, plain links — the server sends an attachment) and "Send to client"
 * (finalized, sent and paid reports only). Self-contained; mount it on the report page.
 */
const SENDABLE: BillingReportStatus[] = ['finalized', 'sent', 'paid'];

export interface ReportDocumentButtonsProps {
    workspaceSlug: string;
    reportId: number;
    status: BillingReportStatus;
    /** The client's email, prefilled in the send dialog. */
    defaultTo?: string | null;
    defaultSubject?: string | null;
    defaultMessage?: string | null;
    /** Shown in the send dialog, e.g. the PDF's file name. */
    attachmentName?: ReactNode;
    /** Drafts are rendered live by the report builder; pass false while no preview is available. */
    canDownload?: boolean;
    size?: 'sm' | 'default';
    className?: string;
    onSent?: () => void;
}

export default function ReportDocumentButtons({
    workspaceSlug,
    reportId,
    status,
    defaultTo,
    defaultSubject,
    defaultMessage,
    attachmentName,
    canDownload = true,
    size = 'sm',
    className,
    onSent,
}: ReportDocumentButtonsProps) {
    const [sending, setSending] = useState(false);
    const params = { workspace: workspaceSlug, billingReport: reportId };
    const canSend = SENDABLE.includes(status);

    return (
        <div className={cn('flex flex-wrap items-center gap-2', className)}>
            {canDownload && (
                <>
                    <Button asChild variant="outline" size={size}>
                        <a href={route('billing.reports.pdf', params)} download>
                            <FileText className="size-4" />
                            PDF
                        </a>
                    </Button>
                    <Button asChild variant="outline" size={size}>
                        <a href={route('billing.reports.xlsx', params)} download>
                            <FileSpreadsheet className="size-4" />
                            Excel
                        </a>
                    </Button>
                </>
            )}
            {canSend && (
                <>
                    <Button type="button" size={size} onClick={() => setSending(true)}>
                        <Send className="size-4" />
                        {status === 'finalized' ? 'Send to client' : 'Send again'}
                    </Button>
                    <SendReportDialog
                        workspaceSlug={workspaceSlug}
                        reportId={reportId}
                        open={sending}
                        onOpenChange={setSending}
                        defaultTo={defaultTo}
                        defaultSubject={defaultSubject}
                        defaultMessage={defaultMessage}
                        attachmentName={attachmentName}
                        onSent={onSent}
                    />
                </>
            )}
        </div>
    );
}
