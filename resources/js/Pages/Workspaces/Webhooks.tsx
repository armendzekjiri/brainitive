import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { formatDistanceToNow } from 'date-fns';
import { toast } from 'sonner';
import { AlertTriangle, ArrowLeft, ChevronDown, ChevronUp, KeyRound, Loader2, MoreHorizontal, Pencil, Plus, RefreshCw, Send, Trash2, Webhook as WebhookIcon } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { CopyButton } from '@/components/leadForms/SharePanel';
import WebhookFormDialog, { type EventGroups } from '@/components/webhooks/WebhookFormDialog';
import DeliveriesPanel, { DeliveryStatus } from '@/components/webhooks/DeliveriesPanel';
import WebhookDocs from '@/components/webhooks/WebhookDocs';
import type { WebhookDelivery, WorkspaceWebhook } from '@/types';

/**
 * Workspace settings → Webhooks (v1.39.0, BRAI-104), workspace admins only. Every action is a JSON
 * call; the secret is shown when created or rotated and on "Reveal", never sent as a page prop.
 * Controller: WebhookController.
 */
interface Props {
    workspace: { id: number; name: string; slug: string };
    webhooks: WorkspaceWebhook[];
    eventGroups: EventGroups;
    requiresHttps: boolean;
    maxFailures: number;
}

type SecretView = { webhook: WorkspaceWebhook; secret: string; reason: 'created' | 'rotated' | 'revealed' };

export default function Webhooks({ workspace, webhooks: initial, eventGroups, requiresHttps, maxFailures }: Props) {
    const [webhooks, setWebhooks] = useState(initial);
    const [form, setForm] = useState<{ open: boolean; webhook: WorkspaceWebhook | null }>({ open: false, webhook: null });
    const [secret, setSecret] = useState<SecretView | null>(null);
    const [confirm, setConfirm] = useState<{ kind: 'delete' | 'rotate'; webhook: WorkspaceWebhook } | null>(null);
    const [openDeliveries, setOpenDeliveries] = useState<number | null>(null);
    const [refresh, setRefresh] = useState(0);
    const [busy, setBusy] = useState<string | null>(null);

    const labels = Object.values(eventGroups).reduce<Record<string, string>>((all, group) => ({ ...all, ...group }), {});
    const replace = (webhook: WorkspaceWebhook) =>
        setWebhooks((list) => (list.some((w) => w.id === webhook.id) ? list.map((w) => (w.id === webhook.id ? { ...w, ...webhook } : w)) : [...list, webhook]));

    const saved = (webhook: WorkspaceWebhook, newSecret: string | null) => {
        replace(webhook);
        setForm({ open: false, webhook: null });
        if (newSecret) {
            setSecret({ webhook, secret: newSecret, reason: 'created' });
            setOpenDeliveries(webhook.id);
        } else {
            toast.success('Webhook saved');
        }
    };

    const reveal = async (webhook: WorkspaceWebhook) => {
        setBusy(`secret-${webhook.id}`);
        try {
            const { data } = await axios.post(route('webhooks.secret', [workspace.slug, webhook.id]));
            setSecret({ webhook, secret: data.secret, reason: 'revealed' });
        } catch {
            toast.error('Could not load the secret.');
        } finally {
            setBusy(null);
        }
    };

    const runConfirmed = async () => {
        if (!confirm) return;
        const { kind, webhook } = confirm;
        setConfirm(null);
        try {
            if (kind === 'delete') {
                await axios.delete(route('webhooks.destroy', [workspace.slug, webhook.id]));
                setWebhooks((list) => list.filter((w) => w.id !== webhook.id));
                toast.success('Webhook deleted');
            } else {
                const { data } = await axios.post(route('webhooks.rotate', [workspace.slug, webhook.id]));
                setSecret({ webhook, secret: data.secret, reason: 'rotated' });
            }
        } catch {
            toast.error(kind === 'delete' ? 'Could not delete the webhook.' : 'Could not rotate the secret.');
        }
    };

    const sendTest = async (webhook: WorkspaceWebhook) => {
        setBusy(`test-${webhook.id}`);
        try {
            const { data } = await axios.post(route('webhooks.test', [workspace.slug, webhook.id]));
            const delivery = data.delivery as WebhookDelivery;
            if (delivery.status === 'success') toast.success(`Test delivered: HTTP ${delivery.response_code}`);
            else if (delivery.status === 'failed') toast.error(`Test failed: ${delivery.error ?? `HTTP ${delivery.response_code}`}`);
            else toast.message('Test queued');
            setOpenDeliveries(webhook.id);
            setRefresh((n) => n + 1);
        } catch (error) {
            toast.error(axios.isAxiosError(error) && error.response?.status === 429 ? 'Too many tests. Try again in a minute.' : 'Could not send the test.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <AppLayout>
            <Head title="Webhooks" />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-8">
                <div>
                    <Link href={route('workspaces.edit', workspace.slug)} className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" />Workspace settings
                    </Link>
                    <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight"><WebhookIcon className="size-6" />Webhooks</h1>
                            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                                Send {workspace.name}’s events (new issues, comments, tickets, leads, time, invoices…) to other tools as signed
                                HTTP POSTs.
                            </p>
                        </div>
                        <Button onClick={() => setForm({ open: true, webhook: null })} className="gap-1.5"><Plus className="size-4" />Add webhook</Button>
                    </div>
                </div>

                {webhooks.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <WebhookIcon className="size-10 text-muted-foreground" />
                            <div>
                                <p className="font-medium">No webhooks yet</p>
                                <p className="text-sm text-muted-foreground">Add one to send events to Zapier, Make, n8n, Slack or your own service.</p>
                            </div>
                            <Button variant="outline" onClick={() => setForm({ open: true, webhook: null })} className="gap-1.5"><Plus className="size-4" />Add your first webhook</Button>
                        </CardContent>
                    </Card>
                ) : (
                    <ul className="space-y-3" aria-label="Webhooks">
                        {webhooks.map((webhook) => {
                            const all = webhook.events.includes('*');
                            const disabled = !webhook.active && !!webhook.disabled_at;
                            const deliveriesOpen = openDeliveries === webhook.id;
                            return (
                                <li key={webhook.id}>
                                    <Card>
                                        <CardContent className="space-y-3 p-4 sm:p-5">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="min-w-0 space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h2 className="font-semibold">{webhook.name}</h2>
                                                        {webhook.active
                                                            ? <Badge variant="secondary" className="bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">Active</Badge>
                                                            : <Badge variant="outline">{disabled ? 'Disabled' : 'Paused'}</Badge>}
                                                        {webhook.last_status && (
                                                            <span className="text-xs text-muted-foreground">
                                                                Last: <DeliveryStatus status={webhook.last_status} />
                                                                {webhook.last_delivery_at && ` · ${formatDistanceToNow(new Date(webhook.last_delivery_at), { addSuffix: true })}`}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="truncate font-mono text-xs text-muted-foreground" title={webhook.url}>{webhook.url}</p>
                                                    <div className="flex flex-wrap gap-1 pt-1">
                                                        {all
                                                            ? <Badge variant="outline" className="font-normal">All events</Badge>
                                                            : webhook.events.map((event) => <Badge key={event} variant="outline" className="font-normal">{labels[event] ?? event}</Badge>)}
                                                    </div>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-2">
                                                    <Button variant="outline" size="sm" className="gap-1.5" onClick={() => void sendTest(webhook)} disabled={busy !== null}>
                                                        {busy === `test-${webhook.id}` ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}Send test
                                                    </Button>
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button variant="ghost" size="icon" className="size-8" aria-label={`More actions for ${webhook.name}`}><MoreHorizontal className="size-4" /></Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem onSelect={() => setForm({ open: true, webhook })}><Pencil className="size-4" />Edit</DropdownMenuItem>
                                                            <DropdownMenuItem onSelect={() => void reveal(webhook)}><KeyRound className="size-4" />Reveal secret</DropdownMenuItem>
                                                            <DropdownMenuItem onSelect={() => setConfirm({ kind: 'rotate', webhook })}><RefreshCw className="size-4" />Rotate secret</DropdownMenuItem>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem className="text-destructive focus:text-destructive" onSelect={() => setConfirm({ kind: 'delete', webhook })}><Trash2 className="size-4" />Delete</DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </div>
                                            </div>

                                            {disabled && (
                                                <p className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                                    Disabled after {maxFailures} failed deliveries in a row. Fix the endpoint, check it with “Send test”, then switch it back on under Edit.
                                                </p>
                                            )}

                                            <div>
                                                <Button variant="ghost" size="sm" className="-ml-2 gap-1.5" onClick={() => setOpenDeliveries(deliveriesOpen ? null : webhook.id)} aria-expanded={deliveriesOpen}>
                                                    {deliveriesOpen ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}Deliveries
                                                </Button>
                                                {deliveriesOpen && (
                                                    <div className="mt-2">
                                                        <DeliveriesPanel workspaceSlug={workspace.slug} webhook={webhook} refreshKey={refresh} onWebhookChanged={replace} />
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                </li>
                            );
                        })}
                    </ul>
                )}

                <WebhookDocs />
            </div>

            <WebhookFormDialog
                open={form.open}
                onOpenChange={(open) => setForm((current) => ({ ...current, open }))}
                workspaceSlug={workspace.slug}
                eventGroups={eventGroups}
                requiresHttps={requiresHttps}
                webhook={form.webhook}
                onSaved={saved}
            />

            <Dialog open={secret !== null} onOpenChange={(open) => !open && setSecret(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{secret?.reason === 'created' ? 'Webhook created' : secret?.reason === 'rotated' ? 'New signing secret' : 'Signing secret'}</DialogTitle>
                        <DialogDescription>
                            {secret?.reason === 'revealed'
                                ? `The secret ${secret.webhook.name} signs its deliveries with.`
                                : 'Copy the signing secret into your receiver now. It verifies X-Brainitive-Signature.'}
                            {secret?.reason === 'rotated' && ' The old secret stops working immediately.'}
                        </DialogDescription>
                    </DialogHeader>
                    {secret && (
                        <div className="flex items-center gap-2">
                            <code className="min-w-0 flex-1 break-all rounded-lg bg-muted px-3 py-2 font-mono text-sm" aria-label="Signing secret">{secret.secret}</code>
                            <CopyButton value={secret.secret} />
                        </div>
                    )}
                    <p className="text-xs text-muted-foreground">Admins can reveal it again from the webhook’s menu, or rotate it if it leaked.</p>
                    <DialogFooter>
                        <Button onClick={() => setSecret(null)}>Done</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog open={confirm !== null} onOpenChange={(open) => !open && setConfirm(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{confirm?.kind === 'delete' ? `Delete “${confirm.webhook.name}”?` : 'Rotate the signing secret?'}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirm?.kind === 'delete'
                                ? 'It stops receiving events at once, and its delivery log is deleted.'
                                : 'Deliveries are signed with a new secret from now on. Update your receiver right away, or it will reject them.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => void runConfirmed()} className={confirm?.kind === 'delete' ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90' : undefined}>
                            {confirm?.kind === 'delete' ? 'Delete webhook' : 'Rotate secret'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
