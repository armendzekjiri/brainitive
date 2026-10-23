import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { Helpdesk, Project, Workspace } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

type Settings = Record<string, unknown> & {
    form_title?: string;
    submit_label?: string;
    message_placeholder?: string;
    success_message?: string;
    after_submit?: 'portal' | 'message';
    auto_subject?: string;
    default_field_subject?: { label?: string; hidden?: boolean };
};

/**
 * Helpdesk settings → General: the texts of the public form and the subject field (BRAI-92).
 * Templates fill these in; everything stays editable.
 */
export default function HelpdeskFormTextsCard({ workspace, project, helpdesk }: { workspace: Workspace; project: Project; helpdesk: Helpdesk }) {
    const s = (helpdesk.settings ?? {}) as Settings;
    const [form, setForm] = useState({
        form_title: s.form_title ?? '',
        submit_label: s.submit_label ?? '',
        message_placeholder: s.message_placeholder ?? '',
        success_message: s.success_message ?? '',
        after_submit: s.after_submit ?? 'portal',
        auto_subject: s.auto_subject ?? '',
        subject_label: s.default_field_subject?.label ?? '',
        subject_hidden: !!s.default_field_subject?.hidden,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const set = (key: keyof typeof form, value: string | boolean) => setForm((f) => ({ ...f, [key]: value }));

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        router.put(route('helpdesks.update', [workspace.slug, project.slug, helpdesk.slug]), {
            name: helpdesk.name,
            description: helpdesk.description ?? null,
            settings: {
                form_title: form.form_title || null,
                submit_label: form.submit_label || null,
                message_placeholder: form.message_placeholder || null,
                success_message: form.success_message || null,
                after_submit: form.after_submit,
                auto_subject: form.auto_subject || null,
                default_field_subject: { label: form.subject_label || null, hidden: form.subject_hidden },
            },
        }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => { setErrors({}); toast.success('Form texts saved.'); },
            onError: (errs) => setErrors(errs),
        });
    };

    const error = (key: string) => errors[`settings.${key}`] && <p className="text-sm text-destructive">{errors[`settings.${key}`]}</p>;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Form texts</CardTitle>
                <CardDescription>What visitors see on the widget, embedded form and support page. Leave a text empty to use the default.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={save} className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="ft-title">Form title</Label>
                        <Input id="ft-title" value={form.form_title} maxLength={80} placeholder="Contact support" onChange={(e) => set('form_title', e.target.value)} />
                        {error('form_title')}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="ft-submit">Button label</Label>
                        <Input id="ft-submit" value={form.submit_label} maxLength={40} placeholder="Submit" onChange={(e) => set('submit_label', e.target.value)} />
                        {error('submit_label')}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="ft-subject">Subject field</Label>
                        <div className="flex items-center gap-2">
                            <Input id="ft-subject" value={form.subject_label} maxLength={60} placeholder="Subject" disabled={form.subject_hidden} onChange={(e) => set('subject_label', e.target.value)} />
                            <label className="flex shrink-0 items-center gap-1.5 text-sm">
                                <input type="checkbox" className="size-4 accent-primary" checked={form.subject_hidden} onChange={(e) => set('subject_hidden', e.target.checked)} />
                                Hide
                            </label>
                        </div>
                        <p className="text-xs text-muted-foreground">Hidden: requests are titled automatically, e.g. “{form.auto_subject || helpdesk.name} — Ada Lovelace”.</p>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="ft-auto">Automatic title prefix</Label>
                        <Input id="ft-auto" value={form.auto_subject} maxLength={80} placeholder={helpdesk.name} onChange={(e) => set('auto_subject', e.target.value)} disabled={!form.subject_hidden} />
                        {error('auto_subject')}
                    </div>
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="ft-placeholder">Message placeholder</Label>
                        <Input id="ft-placeholder" value={form.message_placeholder} maxLength={120} placeholder="Type your message…" onChange={(e) => set('message_placeholder', e.target.value)} />
                    </div>
                    <div className="space-y-2">
                        <Label>After sending</Label>
                        <Select value={form.after_submit} onValueChange={(v) => set('after_submit', v)}>
                            <SelectTrigger aria-label="After sending"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="portal">Open the request page (for support)</SelectItem>
                                <SelectItem value="message">Show a thank-you message (for forms)</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="ft-success">Thank-you message</Label>
                        <Textarea id="ft-success" rows={2} value={form.success_message} maxLength={500} placeholder="Thanks! We received your message and will get back to you by email." onChange={(e) => set('success_message', e.target.value)} />
                        {error('success_message')}
                    </div>
                    <div className="flex justify-end sm:col-span-2">
                        <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save form texts'}</Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
