import { useEffect, useState, type FormEvent } from 'react';
import axios from 'axios';
import { Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { WorkspaceWebhook } from '@/types';

/** area label => { event name => label } (DomainEvents::grouped()) */
export type EventGroups = Record<string, Record<string, string>>;

type Errors = Partial<Record<'name' | 'url' | 'events' | 'active', string>>;

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    eventGroups: EventGroups;
    requiresHttps: boolean;
    /** null = create */
    webhook: WorkspaceWebhook | null;
    onSaved: (webhook: WorkspaceWebhook, secret: string | null) => void;
}

/** Create / edit a webhook (BRAI-104): name, URL, events grouped by area or "All events", active. */
export default function WebhookFormDialog({ open, onOpenChange, workspaceSlug, eventGroups, requiresHttps, webhook, onSaved }: Props) {
    const [name, setName] = useState('');
    const [url, setUrl] = useState('');
    const [allEvents, setAllEvents] = useState(true);
    const [events, setEvents] = useState<string[]>([]);
    const [active, setActive] = useState(true);
    const [errors, setErrors] = useState<Errors>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setName(webhook?.name ?? '');
        setUrl(webhook?.url ?? '');
        setAllEvents(webhook ? webhook.events.includes('*') : true);
        setEvents(webhook ? webhook.events.filter((e) => e !== '*') : []);
        setActive(webhook?.active ?? true);
        setErrors({});
    }, [open, webhook]);

    const toggle = (event: string, on: boolean) =>
        setEvents((current) => (on ? [...new Set([...current, event])] : current.filter((e) => e !== event)));

    const toggleGroup = (group: Record<string, string>, on: boolean) => {
        const names = Object.keys(group);
        setEvents((current) => (on ? [...new Set([...current, ...names])] : current.filter((e) => !names.includes(e))));
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        const data = { name, url, events: allEvents ? ['*'] : events, active };
        try {
            const response = webhook
                ? await axios.put(route('webhooks.update', [workspaceSlug, webhook.id]), data)
                : await axios.post(route('webhooks.store', workspaceSlug), data);
            onSaved(response.data.webhook, response.data.secret ?? null);
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                const raw = (error.response.data?.errors ?? {}) as Record<string, string[]>;
                const next: Errors = {};
                for (const [key, messages] of Object.entries(raw)) {
                    const field = (key.startsWith('events') ? 'events' : key) as keyof Errors;
                    next[field] ??= messages[0];
                }
                setErrors(next);
            } else {
                setErrors({ name: 'Could not save the webhook. Please try again.' });
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{webhook ? 'Edit webhook' : 'Add webhook'}</DialogTitle>
                    <DialogDescription>
                        Brainitive sends a signed JSON POST to this URL whenever one of the chosen events happens in this workspace.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-5" noValidate>
                    <div className="space-y-1.5">
                        <Label htmlFor="webhook-name">Name</Label>
                        <Input id="webhook-name" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} placeholder="e.g. Zapier, Slack bridge" aria-invalid={!!errors.name} />
                        {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="webhook-url">Payload URL</Label>
                        <Input id="webhook-url" type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://example.com/brainitive-webhook" className="font-mono text-sm" aria-invalid={!!errors.url} />
                        {errors.url
                            ? <p className="text-xs text-destructive">{errors.url}</p>
                            : <p className="text-xs text-muted-foreground">{requiresHttps ? 'Must use https and' : 'Must'} point to a public address. Redirects are not followed.</p>}
                    </div>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">Events</legend>
                        <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-border p-3 hover:bg-accent/40">
                            <input type="checkbox" className="size-4 rounded border-border" checked={allEvents} onChange={(e) => setAllEvents(e.target.checked)} />
                            <span>
                                <span className="block text-sm font-medium">All events</span>
                                <span className="block text-xs text-muted-foreground">Including events added in future versions.</span>
                            </span>
                        </label>
                        {!allEvents && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {Object.entries(eventGroups).map(([area, group]) => {
                                    const names = Object.keys(group);
                                    const checked = names.filter((n) => events.includes(n)).length;
                                    return (
                                        <div key={area} className="rounded-xl border border-border p-3">
                                            <label className="mb-2 flex cursor-pointer items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                <input
                                                    type="checkbox"
                                                    className="size-3.5 rounded border-border"
                                                    checked={checked === names.length}
                                                    ref={(el) => { if (el) el.indeterminate = checked > 0 && checked < names.length; }}
                                                    onChange={(e) => toggleGroup(group, e.target.checked)}
                                                    aria-label={`All ${area} events`}
                                                />
                                                {area}
                                            </label>
                                            <div className="space-y-1.5">
                                                {Object.entries(group).map(([event, label]) => (
                                                    <label key={event} className="flex cursor-pointer items-start gap-2 text-sm">
                                                        <input type="checkbox" className="mt-0.5 size-4 rounded border-border" checked={events.includes(event)} onChange={(e) => toggle(event, e.target.checked)} />
                                                        <span>
                                                            {label}
                                                            <code className="ml-1.5 text-[11px] text-muted-foreground">{event}</code>
                                                        </span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        {errors.events && <p className="text-xs text-destructive">{errors.events === 'The events field is required.' ? 'Choose at least one event.' : errors.events}</p>}
                    </fieldset>

                    <label className={cn('flex cursor-pointer items-start gap-3 rounded-xl border border-border p-3 hover:bg-accent/40')}>
                        <input type="checkbox" className="mt-0.5 size-4 rounded border-border" checked={active} onChange={(e) => setActive(e.target.checked)} />
                        <span>
                            <span className="block text-sm font-medium">Active</span>
                            <span className="block text-xs text-muted-foreground">Inactive webhooks receive nothing. Switching a disabled webhook back on resets its failure count.</span>
                        </span>
                    </label>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={saving || (!allEvents && events.length === 0)}>
                            {saving && <Loader2 className="size-4 animate-spin" />}
                            {webhook ? 'Save changes' : 'Create webhook'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
