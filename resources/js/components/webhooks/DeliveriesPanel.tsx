import { Fragment, useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { formatDistanceToNow } from 'date-fns';
import { toast } from 'sonner';
import { CheckCircle2, ChevronDown, ChevronRight, Clock, Loader2, RefreshCw, RotateCcw, XCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { WebhookDelivery, WebhookDeliveryStatus, WorkspaceWebhook } from '@/types';

const STATUS: Record<WebhookDeliveryStatus, { label: string; icon: typeof CheckCircle2; className: string }> = {
    success: { label: 'Delivered', icon: CheckCircle2, className: 'text-green-600 dark:text-green-500' },
    failed: { label: 'Failed', icon: XCircle, className: 'text-destructive' },
    pending: { label: 'Pending', icon: Clock, className: 'text-muted-foreground' },
};

export function DeliveryStatus({ status }: { status: WebhookDeliveryStatus }) {
    const { label, icon: Icon, className } = STATUS[status];
    return (
        <span className={cn('inline-flex items-center gap-1.5 text-sm font-medium', className)}>
            <Icon className="size-4" aria-hidden />
            {label}
        </span>
    );
}

/** One line naming what the event was about ("WEB-12 Homepage hero"), from the envelope's data. */
function subject(payload: Record<string, unknown>): string | null {
    const data = (payload.data ?? {}) as Record<string, Record<string, unknown> | undefined>;
    const join = (...parts: unknown[]) => parts.filter((p) => typeof p === 'string' && p !== '').join(' ') || null;
    if (data.item) return join(data.item.key, data.item.title);
    if (data.ticket) return join(data.ticket.key, data.ticket.subject);
    if (data.page) return join(data.page.title);
    if (data.sprint) return join(data.sprint.name);
    if (data.lead) return join(data.lead.name ?? data.lead.email);
    if (data.time_entry) return join(data.time_entry.duration, (data.time_entry.item as Record<string, unknown> | null)?.key);
    if (data.report) return join(data.report.number ?? data.report.title);
    if (data.webhook) return join(data.webhook.name);
    return null;
}

function ago(iso: string | null): string {
    return iso ? formatDistanceToNow(new Date(iso), { addSuffix: true }) : '—';
}

interface Props {
    workspaceSlug: string;
    webhook: WorkspaceWebhook;
    /** bump to reload (after Send test) */
    refreshKey: number;
    onWebhookChanged: (webhook: WorkspaceWebhook) => void;
}

/**
 * Recent deliveries of one webhook (BRAI-104): status, response code, duration, time; a row expands
 * to the request envelope and the response. Polls while a delivery is pending (queued / sent after
 * the response on a sync queue).
 */
export default function DeliveriesPanel({ workspaceSlug, webhook, refreshKey, onWebhookChanged }: Props) {
    const [rows, setRows] = useState<WebhookDelivery[] | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [expanded, setExpanded] = useState<number | null>(null);
    const [redelivering, setRedelivering] = useState<number | null>(null);
    const polls = useRef(0);
    const onChanged = useRef(onWebhookChanged);
    onChanged.current = onWebhookChanged;

    const load = useCallback(async (before?: number) => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('webhooks.deliveries', [workspaceSlug, webhook.id]), { params: before ? { before } : {} });
            setRows((current) => (before && current ? [...current, ...data.data] : data.data));
            setHasMore(data.has_more);
            if (!before) onChanged.current(data.webhook);
        } catch {
            toast.error('Could not load the deliveries.');
        } finally {
            setLoading(false);
        }
    }, [workspaceSlug, webhook.id]);

    useEffect(() => {
        polls.current = 0;
        void load();
    }, [load, refreshKey]);

    // something still on its way: look again a few times
    useEffect(() => {
        if (!rows?.some((d) => d.status === 'pending') || polls.current >= 15) return;
        const timer = window.setTimeout(() => {
            polls.current += 1;
            void load();
        }, 2000);
        return () => window.clearTimeout(timer);
    }, [rows, load]);

    const redeliver = async (delivery: WebhookDelivery) => {
        setRedelivering(delivery.id);
        try {
            const { data } = await axios.post(route('webhooks.redeliver', [workspaceSlug, webhook.id, delivery.id]));
            const copy = data.delivery as WebhookDelivery;
            if (copy.status === 'success') toast.success(`Redelivered: HTTP ${copy.response_code}`);
            else if (copy.status === 'failed') toast.error(`Redelivery failed: ${copy.error ?? `HTTP ${copy.response_code}`}`);
            else toast.message('Redelivery queued');
            polls.current = 0;
            await load();
        } catch (error) {
            toast.error(axios.isAxiosError(error) && error.response?.status === 429 ? 'Too many redeliveries. Try again in a minute.' : 'Could not redeliver.');
        } finally {
            setRedelivering(null);
        }
    };

    if (rows === null) {
        return <div className="flex items-center gap-2 p-4 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" />Loading deliveries…</div>;
    }

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <h4 className="text-sm font-semibold">Recent deliveries</h4>
                <Button type="button" variant="ghost" size="sm" onClick={() => { polls.current = 0; void load(); }} disabled={loading} aria-label="Refresh deliveries">
                    <RefreshCw className={cn('size-4', loading && 'animate-spin')} />
                </Button>
            </div>
            {rows.length === 0 ? (
                <p className="rounded-xl border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                    No deliveries yet. Use “Send test” or trigger one of the subscribed events. Deliveries are kept for 30 days.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-border">
                    <table className="w-full text-sm" aria-label={`Deliveries of ${webhook.name}`}>
                        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wider text-muted-foreground">
                            <tr>
                                <th className="w-8 px-2 py-2" />
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2 font-medium">Event</th>
                                <th className="px-3 py-2 font-medium">Code</th>
                                <th className="px-3 py-2 font-medium">Duration</th>
                                <th className="px-3 py-2 font-medium">Time</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((delivery) => {
                                const open = expanded === delivery.id;
                                return (
                                    <Fragment key={delivery.id}>
                                        <tr className="border-t border-border">
                                            <td className="px-2 py-2">
                                                <Button type="button" variant="ghost" size="icon" className="size-7" onClick={() => setExpanded(open ? null : delivery.id)} aria-expanded={open} aria-label={open ? 'Hide details' : 'Show details'}>
                                                    {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                                </Button>
                                            </td>
                                            <td className="px-3 py-2"><DeliveryStatus status={delivery.status} /></td>
                                            <td className="max-w-[16rem] px-3 py-2">
                                                <code className="text-xs">{delivery.event}</code>
                                                {subject(delivery.payload) && <div className="truncate text-xs text-muted-foreground">{subject(delivery.payload)}</div>}
                                            </td>
                                            <td className="px-3 py-2 tabular-nums">{delivery.response_code ?? '—'}</td>
                                            <td className="px-3 py-2 tabular-nums">{delivery.duration_ms !== null ? `${delivery.duration_ms} ms` : '—'}</td>
                                            <td className="px-3 py-2 text-muted-foreground" title={delivery.created_at ?? undefined}>{ago(delivery.created_at)}</td>
                                            <td className="px-3 py-2 text-right">
                                                <Button type="button" variant="outline" size="sm" className="gap-1.5" onClick={() => void redeliver(delivery)} disabled={redelivering !== null}>
                                                    {redelivering === delivery.id ? <Loader2 className="size-3.5 animate-spin" /> : <RotateCcw className="size-3.5" />}
                                                    Redeliver
                                                </Button>
                                            </td>
                                        </tr>
                                        {open && (
                                            <tr className="border-t border-border bg-muted/30">
                                                <td colSpan={7} className="space-y-3 px-4 py-3">
                                                    <p className="text-xs text-muted-foreground">
                                                        Delivery <code>{delivery.uuid}</code> · event id <code>{delivery.event_id}</code> · {delivery.attempts} {delivery.attempts === 1 ? 'attempt' : 'attempts'}
                                                        {delivery.error && <> · <span className="text-destructive">{delivery.error}</span></>}
                                                    </p>
                                                    <div className="grid gap-3 lg:grid-cols-2">
                                                        <div className="min-w-0">
                                                            <div className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Request body</div>
                                                            <pre className="max-h-80 overflow-auto rounded-lg bg-background p-3 text-xs">{JSON.stringify(delivery.payload, null, 2)}</pre>
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Response {delivery.response_code ? `(HTTP ${delivery.response_code})` : ''}</div>
                                                            <pre className="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-background p-3 text-xs">{delivery.response_body || (delivery.status === 'pending' ? 'Waiting…' : 'No response body')}</pre>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            {hasMore && (
                <Button type="button" variant="ghost" size="sm" onClick={() => void load(rows[rows.length - 1]?.id)} disabled={loading}>
                    Load older deliveries
                </Button>
            )}
        </div>
    );
}
