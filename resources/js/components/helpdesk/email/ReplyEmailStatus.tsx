import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { TicketReply } from '@/types';
import { router } from '@inertiajs/react';
import { Mail, MailWarning, RotateCw } from 'lucide-react';
import { useState } from 'react';

/**
 * How a reply travelled by email (BRAI-79), next to its author in the ticket dialog: received by
 * email, being sent, sent, or failed (with the error and "Retry", same Message-ID).
 */
export default function ReplyEmailStatus({ reply, routeParams }: { reply: TicketReply; routeParams: [string, string, string, number] }) {
    const [busy, setBusy] = useState(false);

    if (!reply.email_message_id) return null;

    if (!reply.email_status) {
        return reply.ticket_contact_id ? (
            <Badge variant="outline" className="gap-1 text-xs"><Mail className="h-3 w-3" /> Via email</Badge>
        ) : null;
    }

    if (reply.email_status === 'failed') {
        const retry = () => {
            setBusy(true);
            router.post(route('tickets.replies.resend-email', [...routeParams, reply.id]), {}, {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setBusy(false),
            });
        };

        return (
            <span className="inline-flex items-center gap-1.5">
                <Badge variant="destructive" className="gap-1 text-xs" title={reply.email_error ?? undefined}>
                    <MailWarning className="h-3 w-3" /> Email failed
                </Badge>
                <Button type="button" variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={retry} disabled={busy} aria-label="Retry sending this reply by email">
                    <RotateCw className="h-3 w-3" /> Retry
                </Button>
            </span>
        );
    }

    return (
        <Badge variant="secondary" className="gap-1 text-xs">
            <Mail className="h-3 w-3" /> {reply.email_status === 'sent' ? 'Emailed' : 'Sending email…'}
        </Badge>
    );
}
