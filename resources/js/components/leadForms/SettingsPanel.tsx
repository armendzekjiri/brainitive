import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { ArrowDownToLine, Bell, KeyRound, Mail, Trash2, Webhook } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { cn } from '@/lib/utils';
import { LeadFormPayload, LeadMember } from '@/lib/leadForms';
import { Field, Toggle } from './BuilderControls';
import { CopyButton } from './SharePanel';

interface Props {
    form: LeadFormPayload;
    members: LeadMember[];
    routeParams: [string, string, string];
    canUpdate: boolean;
    canDelete: boolean;
    onSaved: (form: LeadFormPayload) => void;
}

type Editable = {
    name: string;
    confirmation_email: boolean;
    confirmation_subject: string;
    confirmation_body: string;
    notify_user_ids: number[];
    webhook_url: string;
};

export default function SettingsPanel({ form, members, routeParams, canUpdate, canDelete, onSaved }: Props) {
    const initial = (): Editable => ({
        name: form.name,
        confirmation_email: !!form.settings.confirmation_email,
        confirmation_subject: form.settings.confirmation_subject ?? '',
        confirmation_body: form.settings.confirmation_body ?? '',
        notify_user_ids: form.settings.notify_user_ids ?? [],
        webhook_url: form.settings.webhook_url ?? '',
    });
    const [data, setData] = useState<Editable>(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [token, setToken] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const hasEmailField = form.definition.steps.some((s) => s.fields.some((f) => f.maps_to === 'email'));
    const changed = JSON.stringify(data) !== JSON.stringify(initial());

    const save = async () => {
        setSaving(true);
        setErrors({});
        try {
            const { data: res } = await window.axios.put(route('lead-forms.update', routeParams), {
                name: data.name,
                settings: {
                    confirmation_email: data.confirmation_email,
                    confirmation_subject: data.confirmation_subject || null,
                    confirmation_body: data.confirmation_body || null,
                    notify_user_ids: data.notify_user_ids,
                    webhook_url: data.webhook_url.trim() || null,
                },
            });
            onSaved(res.form);
            toast.success('Settings saved');
            if (res.form.name !== form.name) router.reload({ only: ['currentWorkspace'] });
        } catch (e: unknown) {
            const errs = (e as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors ?? {};
            setErrors(Object.fromEntries(Object.entries(errs).map(([k, v]) => [k.replace(/^settings\./, ''), v[0]])));
        } finally {
            setSaving(false);
        }
    };

    const rotate = async () => {
        const { data: res } = await window.axios.post(route('lead-forms.inbound-token', routeParams));
        setToken(res.token);
        onSaved({ ...form, has_inbound_token: true });
    };
    const revoke = async () => {
        await window.axios.delete(route('lead-forms.inbound-token.revoke', routeParams));
        setToken(null);
        onSaved({ ...form, has_inbound_token: false });
    };

    const keys = form.definition.steps.flatMap((s) => s.fields).filter((f) => f.type !== 'consent');
    const curl = `curl -X POST ${form.inbound_url} \\
  -H "Authorization: Bearer ${token ?? '<token>'}" \\
  -H "Content-Type: application/json" \\
  -d '${JSON.stringify(Object.fromEntries(keys.slice(0, 3).map((f) => [f.key, f.options[0]?.label ?? (f.type === 'email' ? 'jane@example.com' : 'value')])))}'`;

    return (
        <fieldset disabled={!canUpdate} className="mx-auto min-w-0 max-w-3xl space-y-6 p-6">
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">General</CardTitle>
                </CardHeader>
                <CardContent>
                    <Field label="Form name">
                        <Input value={data.name} onChange={(e) => setData({ ...data, name: e.target.value })} maxLength={255} />
                    </Field>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Bell className="size-4" />
                        Notify the team
                    </CardTitle>
                    <CardDescription>These members get an email and a notification for every new lead.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="flex flex-wrap gap-2">
                        {members.map((m) => {
                            const on = data.notify_user_ids.includes(m.id);
                            return (
                                <button
                                    key={m.id}
                                    type="button"
                                    onClick={() => setData({ ...data, notify_user_ids: on ? data.notify_user_ids.filter((id) => id !== m.id) : [...data.notify_user_ids, m.id] })}
                                    className={cn('rounded-full border px-3 py-1 text-sm transition', on ? 'border-primary bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-muted')}
                                >
                                    {m.name}
                                </button>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Mail className="size-4" />
                        Confirmation email
                    </CardTitle>
                    <CardDescription>Sent to the lead right after they submit. Use {'{name}'} and {'{form}'}.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <Toggle label="Send a confirmation email" checked={data.confirmation_email} onChange={(v) => setData({ ...data, confirmation_email: v })} />
                    {data.confirmation_email && !hasEmailField && <p className="text-xs text-amber-600">No question is saved as the lead's email yet — set “Save the answer as the lead's Email” on an email question in the builder.</p>}
                    {data.confirmation_email && (
                        <>
                            <Field label="Subject">
                                <Input value={data.confirmation_subject} onChange={(e) => setData({ ...data, confirmation_subject: e.target.value })} maxLength={200} />
                            </Field>
                            <Field label="Message">
                                <Textarea rows={5} value={data.confirmation_body} onChange={(e) => setData({ ...data, confirmation_body: e.target.value })} maxLength={5000} />
                            </Field>
                        </>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Webhook className="size-4" />
                        Send leads to another tool
                    </CardTitle>
                    <CardDescription>We POST every new lead as JSON to this URL (Zapier, Make, n8n, your CRM).</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <Field label="Webhook URL">
                        <Input value={data.webhook_url} onChange={(e) => setData({ ...data, webhook_url: e.target.value })} placeholder="https://hooks.zapier.com/…" maxLength={2048} />
                    </Field>
                    {errors.webhook_url && <p className="text-xs text-destructive">{errors.webhook_url}</p>}
                    {form.webhook_secret && (
                        <Field label="Signing secret" hint="Verify the X-Brainitive-Signature header: sha256= HMAC of the raw body with this secret.">
                            <div className="flex gap-2">
                                <Input readOnly value={form.webhook_secret} className="font-mono text-xs" />
                                <CopyButton value={form.webhook_secret} />
                            </div>
                        </Field>
                    )}
                </CardContent>
            </Card>

            {canUpdate && (
                <div className="flex justify-end">
                    <Button onClick={save} disabled={saving || !changed}>
                        {saving ? 'Saving…' : 'Save settings'}
                    </Button>
                </div>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <ArrowDownToLine className="size-4" />
                        Receive leads from other tools
                    </CardTitle>
                    <CardDescription>Other tools can push leads onto this board. Send JSON keyed by question key.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <Field label="Endpoint">
                        <div className="flex gap-2">
                            <Input readOnly value={form.inbound_url} className="font-mono text-xs" />
                            <CopyButton value={form.inbound_url} />
                        </div>
                    </Field>
                    {token && (
                        <Field label="Token — copy it now, it is shown only once">
                            <div className="flex gap-2">
                                <Input readOnly value={token} className="font-mono text-xs" />
                                <CopyButton value={token} />
                            </div>
                        </Field>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" className="gap-1.5" onClick={rotate}>
                            <KeyRound className="size-4" />
                            {form.has_inbound_token ? 'New token' : 'Create token'}
                        </Button>
                        {form.has_inbound_token && (
                            <Button variant="ghost" size="sm" onClick={revoke}>
                                Turn off
                            </Button>
                        )}
                        <span className="text-xs text-muted-foreground">{form.has_inbound_token ? 'On — a new token replaces the old one.' : 'Off'}</span>
                    </div>
                    <pre className="overflow-x-auto rounded-md bg-muted p-3 font-mono text-[11px] leading-relaxed">{curl}</pre>
                    <p className="text-xs text-muted-foreground">
                        Keys: {keys.map((f) => <code key={f.id} className="mr-1 rounded bg-muted px-1">{f.key}</code>)} — choices accept the option label.
                    </p>
                </CardContent>
            </Card>

            {canDelete && (
                <Card className="border-destructive/40">
                    <CardHeader>
                        <CardTitle className="text-base text-destructive">Delete form</CardTitle>
                        <CardDescription>The form goes offline and its board with {form.leads_count} leads is removed.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button variant="destructive" size="sm" className="gap-1.5" onClick={() => setConfirmDelete(true)}>
                            <Trash2 className="size-4" />
                            Delete form
                        </Button>
                    </CardContent>
                </Card>
            )}

            <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete “{form.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>The public page stops working and the {form.leads_count} leads are removed.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => router.delete(route('lead-forms.destroy', routeParams))}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </fieldset>
    );
}
