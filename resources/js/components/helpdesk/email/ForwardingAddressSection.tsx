import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import type { Helpdesk, Project, Workspace } from '@/types';
import axios from 'axios';
import { Check, Copy, Forward, RefreshCw, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

/** GET helpdesks.forwarding.show (HelpdeskForwardingController). */
interface ForwardingState {
    enabled: boolean;
    address: string | null;
    provider: 'postmark' | 'mailgun' | null;
}

/**
 * Helpdesk settings → Email → "Forwarding address" (BRAI-124): mail sent or forwarded to this
 * address becomes a ticket. Loads and regenerates through its own JSON endpoints.
 */
export default function ForwardingAddressSection({ workspace, project, helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    const params = [workspace.slug, project.slug, helpdesk.slug];
    const [state, setState] = useState<ForwardingState | null>(null);
    const [error, setError] = useState(false);
    const [copied, setCopied] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;
        axios.get<ForwardingState>(route('helpdesks.forwarding.show', params))
            .then(({ data }) => alive && setState(data))
            .catch(() => alive && setError(true));
        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [workspace.slug, project.slug, helpdesk.slug]);

    const copy = async () => {
        if (!state?.address) return;
        try {
            await navigator.clipboard.writeText(state.address);
            setCopied(true);
            toast.success('Address copied');
            setTimeout(() => setCopied(false), 1500);
        } catch {
            toast.error('Copy failed — select the address and copy it manually.');
        }
    };

    const regenerate = async () => {
        setBusy(true);
        try {
            const { data } = await axios.post<ForwardingState>(route('helpdesks.forwarding.regenerate', params));
            setState(data);
            toast.success('New forwarding address created');
        } catch {
            toast.error('Could not create a new address.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="forwarding-address-heading" className="space-y-4">
            <div className="space-y-1">
                <h3 id="forwarding-address-heading" className="flex items-center gap-2 text-base font-semibold">
                    <Forward className="h-4 w-4" /> Forwarding address
                </h3>
                <p className="text-sm text-muted-foreground">
                    Forward your support mailbox to this address (or let customers write to it): every email becomes a ticket, and replies to a ticket land in the same thread.
                </p>
            </div>

            {error && (
                <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive" role="alert">
                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" /> The forwarding address could not be loaded. Reload the page to try again.
                </div>
            )}

            {!state && !error && <Skeleton className="h-10 w-full max-w-xl" />}

            {state && !state.enabled && (
                <div className="space-y-2 rounded-md border bg-muted/40 p-4 text-sm">
                    <p className="font-medium">Forwarding addresses are not set up on this server.</p>
                    <p className="text-muted-foreground">An administrator of this Brainitive installation sets them up once:</p>
                    <ol className="list-decimal space-y-1 pl-5 text-muted-foreground">
                        <li>Pick a domain for incoming mail (e.g. <code className="rounded bg-muted px-1">inbound.example.com</code>) and point its MX records at Postmark or Mailgun.</li>
                        <li>In the provider, route all mail for that domain to <code className="rounded bg-muted px-1">/api/inbound-mail/postmark</code> or <code className="rounded bg-muted px-1">/api/inbound-mail/mailgun</code> on this server.</li>
                        <li>Set <code className="rounded bg-muted px-1">INBOUND_MAIL_DOMAIN</code> and the provider secret in <code className="rounded bg-muted px-1">.env</code>.</li>
                    </ol>
                    <p className="text-muted-foreground">Step-by-step: “Email to helpdesk” in <code className="rounded bg-muted px-1">docs/self-hosting.md</code>.</p>
                </div>
            )}

            {state?.enabled && state.address && (
                <div className="space-y-5">
                    <div className="max-w-xl space-y-1.5">
                        <Label htmlFor="helpdesk-forwarding-address">Forwarding address</Label>
                        <div className="flex gap-2">
                            <Input id="helpdesk-forwarding-address" readOnly value={state.address} className="font-mono text-xs" onFocus={(e) => e.currentTarget.select()} />
                            <Button type="button" variant="outline" size="icon" onClick={copy} aria-label="Copy forwarding address">
                                {copied ? <Check className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">Keep it private: anyone who knows it can open tickets.</p>
                    </div>

                    <div className="grid gap-4 text-sm md:grid-cols-2">
                        <div className="space-y-1.5">
                            <p className="font-medium">Gmail / Google Workspace</p>
                            <ol className="list-decimal space-y-1 pl-5 text-muted-foreground">
                                <li>Settings → See all settings → <span className="text-foreground">Forwarding and POP/IMAP</span> → Add a forwarding address, paste the address above.</li>
                                <li>Google sends a confirmation code to it: it arrives here as a ticket. Copy the code into Gmail.</li>
                                <li>Choose “Forward a copy of incoming mail to” this address and save.</li>
                            </ol>
                        </div>
                        <div className="space-y-1.5">
                            <p className="font-medium">Outlook / Microsoft 365</p>
                            <ol className="list-decimal space-y-1 pl-5 text-muted-foreground">
                                <li>Settings → Mail → <span className="text-foreground">Forwarding</span> → Enable forwarding, paste the address above, keep a copy if you like, and save.</li>
                                <li>Microsoft 365: if forwarding is blocked, an admin allows automatic external forwarding in the outbound spam policy (or forwards with a mail flow rule).</li>
                            </ol>
                        </div>
                    </div>
                    <p className="text-sm text-muted-foreground">Any mailbox, alias or domain host that can forward or redirect mail works the same way.</p>

                    <div className="border-t pt-4">
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="outline" disabled={busy}><RefreshCw className="h-4 w-4" /> Regenerate address</Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Create a new forwarding address?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Mail to the current address is refused from then on. Update your mailbox’s forwarding rule with the new address.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction onClick={regenerate}>Create new address</AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>
            )}
        </section>
    );
}
