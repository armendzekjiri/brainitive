import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Ticket } from '@/types';
import { CheckCircle2, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    /** The ticket being resolved; null = closed. */
    ticket: Ticket | null;
    columnName: string;
    agentName: string;
    processing: boolean;
    onConfirm: (message: string, notify: boolean) => void;
    onCancel: () => void;
}

/**
 * Shown when a ticket moves into a "done" column: a closing message for the customer
 * (saved as a reply) and whether to email them that it's resolved.
 */
export default function ResolveTicketDialog({ ticket, columnName, agentName, processing, onConfirm, onCancel }: Props) {
    const [message, setMessage] = useState('');
    const [notify, setNotify] = useState(true);
    const contact = ticket?.contact ?? null;

    useEffect(() => {
        if (!ticket) return;
        const hello = contact?.name ? `Hi ${contact.name},` : 'Hi,';
        setMessage(`${hello}\n\nyour request "${ticket.subject}" has been resolved. If anything is still not right, just reply on the ticket page.\n\nBest regards,\n${agentName}`);
        setNotify(!!contact);
    }, [ticket?.id]);

    return (
        <Dialog open={!!ticket} onOpenChange={(open) => !open && !processing && onCancel()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <CheckCircle2 className="size-5 text-green-600" />
                        Resolve {ticket?.key}
                    </DialogTitle>
                    <DialogDescription>
                        Moves the ticket to “{columnName}”. The message is added to the ticket as a reply
                        {contact ? ' and can be emailed to the customer.' : '.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-2">
                    <Label htmlFor="resolution-message">Message to the customer</Label>
                    <Textarea id="resolution-message" value={message} onChange={(e) => setMessage(e.target.value)} rows={8} />
                    <p className="text-xs text-muted-foreground">Leave it empty to resolve without a message.</p>
                </div>

                {contact ? (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" className="size-4 accent-primary" checked={notify} onChange={(e) => setNotify(e.target.checked)} />
                        Email {contact.name || contact.email} that the ticket is resolved
                    </label>
                ) : (
                    <p className="text-sm text-muted-foreground">This ticket has no customer email, so nobody is notified.</p>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={onCancel} disabled={processing}>
                        Cancel
                    </Button>
                    <Button onClick={() => onConfirm(message, notify && !!contact)} disabled={processing}>
                        {processing && <Loader2 className="size-4 animate-spin" />}
                        Resolve ticket
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
