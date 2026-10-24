import { useEffect, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { Helpdesk, HelpdeskColumn, ItemPriority, Project, SlaPolicy, SlaTarget, SlaUnit, Workspace } from '@/types';

/**
 * Helpdesk settings → SLA (BRAI-125): first-response and resolution targets per priority, business hours
 * or 24/7, columns that pause the clock, and when to warn. Loads and saves through helpdesks.sla.show /
 * helpdesks.sla.update (HelpdeskSlaController); saving recomputes the open tickets.
 */
interface SlaSettings {
    policy: SlaPolicy;
    priorities: Pick<ItemPriority, 'id' | 'name' | 'slug' | 'color'>[];
    columns: Pick<HelpdeskColumn, 'id' | 'name' | 'color' | 'is_done_column'>[];
    timezones: string[];
}

const DAYS = [
    { iso: 1, label: 'Mon' },
    { iso: 2, label: 'Tue' },
    { iso: 3, label: 'Wed' },
    { iso: 4, label: 'Thu' },
    { iso: 5, label: 'Fri' },
    { iso: 6, label: 'Sat' },
    { iso: 7, label: 'Sun' },
];

const TARGETS = [
    { key: 'first_response', label: 'First response' },
    { key: 'resolution', label: 'Resolution' },
] as const;

const UNITS: { value: SlaUnit; label: string }[] = [
    { value: 'minutes', label: 'minutes' },
    { value: 'hours', label: 'hours' },
    { value: 'days', label: 'days' },
];

const selectClass = 'h-9 rounded-md border border-input bg-background px-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring';

export default function HelpdeskSlaTab({ workspace, project, helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    const params = [workspace.slug, project.slug, helpdesk.slug];
    const [settings, setSettings] = useState<SlaSettings | null>(null);
    const [form, setForm] = useState<SlaPolicy | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [loadError, setLoadError] = useState(false);

    useEffect(() => {
        window.axios
            .get<SlaSettings>(route('helpdesks.sla.show', params))
            .then(({ data }) => {
                setSettings(data);
                setForm({ ...data.policy, targets: { ...data.policy.targets } });
            })
            .catch(() => setLoadError(true));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [helpdesk.id]);

    if (loadError) {
        return (
            <Card>
                <CardContent className="py-6 text-sm text-destructive">The SLA settings could not be loaded.</CardContent>
            </Card>
        );
    }
    if (!settings || !form) {
        return (
            <Card>
                <CardContent className="py-6 text-sm text-muted-foreground">Loading…</CardContent>
            </Card>
        );
    }

    const rows = [{ key: 'none', name: 'No priority' }, ...settings.priorities.map((p) => ({ key: String(p.id), name: p.name }))];
    const pausable = settings.columns.filter((c) => !c.is_done_column);
    const update = (patch: Partial<SlaPolicy>) => setForm((f) => (f ? { ...f, ...patch } : f));

    const target = (row: string, which: 'first_response' | 'resolution'): SlaTarget =>
        form.targets[row]?.[which] ?? { value: '', unit: which === 'resolution' ? 'days' : 'hours' };
    const setTarget = (row: string, which: 'first_response' | 'resolution', patch: Partial<SlaTarget>) =>
        update({
            targets: {
                ...form.targets,
                [row]: {
                    first_response: form.targets[row]?.first_response ?? null,
                    resolution: form.targets[row]?.resolution ?? null,
                    [which]: { ...target(row, which), ...patch },
                },
            },
        });

    const hours = form.business_hours;
    const toggleDay = (iso: number) => {
        if (!hours) return;
        const days = hours.days.includes(iso) ? hours.days.filter((d) => d !== iso) : [...hours.days, iso].sort();
        update({ business_hours: { ...hours, days } });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        window.axios
            .put(route('helpdesks.sla.update', params), {
                enabled: form.enabled,
                targets: form.targets,
                business_hours: form.business_hours,
                timezone: form.timezone,
                pause_column_ids: form.pause_column_ids,
                warn_percent: form.warn_percent,
            })
            .then(({ data }) => {
                setForm({ ...data.policy, targets: { ...data.policy.targets } });
                toast.success(data.recomputed ? `${data.message} ${data.recomputed} open ticket(s) updated.` : data.message);
            })
            .catch((error) => {
                if (error.response?.status === 422) {
                    setErrors(error.response.data.errors ?? {});
                    toast.error('Please check the SLA settings.');
                } else {
                    toast.error('The SLA policy could not be saved.');
                }
            })
            .finally(() => setSaving(false));
    };

    const errorList = Object.values(errors).flat();

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Service levels</CardTitle>
                    <CardDescription>
                        Response and resolution targets for tickets in “{helpdesk.name}”. Tickets show “Respond in …”, “Breached …” or “Met”,
                        and the assignee (or the project admins) are warned before a target is missed.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <label className="flex items-center gap-3 text-sm font-medium">
                        <input
                            type="checkbox"
                            className="size-4 rounded border-input accent-primary"
                            checked={form.enabled}
                            onChange={(e) => update({ enabled: e.target.checked })}
                        />
                        Track SLAs for this helpdesk
                    </label>

                    <div className="-mx-4 overflow-x-auto px-4">
                        <table className="w-full min-w-[560px] text-sm" aria-label="SLA targets">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-2 font-medium">Priority</th>
                                    {TARGETS.map((t) => (
                                        <th key={t.key} className="pb-2 font-medium">{t.label}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.key} className="border-t">
                                        <td className="py-2 pr-3 font-medium">{row.name}</td>
                                        {TARGETS.map((t) => {
                                            const value = target(row.key, t.key);
                                            return (
                                                <td key={t.key} className="py-2 pr-3">
                                                    <div className="flex items-center gap-1.5">
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            step="any"
                                                            inputMode="decimal"
                                                            placeholder="None"
                                                            aria-label={`${t.label} target for ${row.name}`}
                                                            value={value.value ?? ''}
                                                            onChange={(e) => setTarget(row.key, t.key, { value: e.target.value })}
                                                            className="h-9 w-24"
                                                        />
                                                        <select
                                                            aria-label={`${t.label} unit for ${row.name}`}
                                                            className={selectClass}
                                                            value={value.unit}
                                                            onChange={(e) => setTarget(row.key, t.key, { unit: e.target.value as SlaUnit })}
                                                        >
                                                            {UNITS.map((u) => (
                                                                <option key={u.value} value={u.value}>{u.label}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Leave a field empty for no target. Public tickets have no priority until an agent sets one. With business hours, a
                        “day” is one business day and the clock only runs while you are open.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">When the clock runs</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-wrap gap-4 text-sm" role="radiogroup" aria-label="Opening hours">
                        <label className="flex items-center gap-2">
                            <input type="radio" name="sla-hours" className="accent-primary" checked={!hours} onChange={() => update({ business_hours: null })} />
                            24/7
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="sla-hours"
                                className="accent-primary"
                                checked={!!hours}
                                onChange={() => update({ business_hours: hours ?? { days: [1, 2, 3, 4, 5], from: '09:00', to: '17:00' } })}
                            />
                            Business hours
                        </label>
                    </div>

                    {hours && (
                        <div className="space-y-3 rounded-lg border p-3">
                            <div className="flex flex-wrap gap-1.5" role="group" aria-label="Business days">
                                {DAYS.map((d) => {
                                    const on = hours.days.includes(d.iso);
                                    return (
                                        <button
                                            key={d.iso}
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() => toggleDay(d.iso)}
                                            className={cn(
                                                'rounded-full border px-3 py-1 text-xs transition-colors',
                                                on ? 'border-primary bg-primary/10 font-semibold text-primary' : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
                                            )}
                                        >
                                            {d.label}
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="space-y-1">
                                    <Label htmlFor="sla-from">Opens at</Label>
                                    <Input id="sla-from" type="time" className="h-9 w-32" value={hours.from} onChange={(e) => update({ business_hours: { ...hours, from: e.target.value } })} />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="sla-to">Closes at</Label>
                                    <Input id="sla-to" type="time" className="h-9 w-32" value={hours.to} onChange={(e) => update({ business_hours: { ...hours, to: e.target.value } })} />
                                </div>
                                <p className="pb-2 text-xs text-muted-foreground">Closing before opening = an overnight shift.</p>
                            </div>
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="sla-timezone">Time zone</Label>
                        <select id="sla-timezone" className={cn(selectClass, 'w-full max-w-xs')} value={form.timezone} onChange={(e) => update({ timezone: e.target.value })}>
                            {settings.timezones.map((tz) => (
                                <option key={tz} value={tz}>{tz}</option>
                            ))}
                        </select>
                    </div>

                    {pausable.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">Pause the resolution clock in</legend>
                            <div className="flex flex-wrap gap-x-5 gap-y-2">
                                {pausable.map((c) => (
                                    <label key={c.id} className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            className="size-4 rounded border-input accent-primary"
                                            checked={form.pause_column_ids.includes(c.id)}
                                            onChange={(e) =>
                                                update({
                                                    pause_column_ids: e.target.checked
                                                        ? [...form.pause_column_ids, c.id]
                                                        : form.pause_column_ids.filter((id) => id !== c.id),
                                                })
                                            }
                                        />
                                        <span className="size-2 rounded-full" style={{ backgroundColor: c.color || '#94a3b8' }} />
                                        {c.name}
                                    </label>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">For example “Waiting on customer”. The first-response target never pauses.</p>
                        </fieldset>
                    )}

                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4 rounded border-input accent-primary"
                                checked={form.warn_percent !== null}
                                onChange={(e) => update({ warn_percent: e.target.checked ? 80 : null })}
                            />
                            Warn before a breach, at
                        </label>
                        <Input
                            type="number"
                            min={1}
                            max={99}
                            aria-label="Warn at percent of the target"
                            className="h-9 w-20"
                            disabled={form.warn_percent === null}
                            value={form.warn_percent ?? ''}
                            onChange={(e) => update({ warn_percent: e.target.value === '' ? null : Number(e.target.value) })}
                        />
                        <span className="text-muted-foreground">% of the target</span>
                    </div>
                </CardContent>
            </Card>

            {errorList.length > 0 && (
                <ul className="list-disc space-y-1 pl-5 text-sm text-destructive" role="alert">
                    {errorList.map((message, i) => (
                        <li key={i}>{message}</li>
                    ))}
                </ul>
            )}

            <div className="flex justify-end">
                <Button type="submit" disabled={saving}>
                    {saving ? 'Saving…' : 'Save SLA policy'}
                </Button>
            </div>
        </form>
    );
}
