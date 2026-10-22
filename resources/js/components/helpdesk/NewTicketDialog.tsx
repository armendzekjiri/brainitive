import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import type { Helpdesk, HelpdeskColumn, HelpdeskField, ItemPriority, Project, User, Workspace } from '@/types';
import { router } from '@inertiajs/react';
import { Loader2, Mail, UserRound } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useMemo, useState } from 'react';

type Values = Record<string, string | string[] | boolean>;

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspace: Workspace;
    project: Project;
    helpdesk: Helpdesk & { columns: HelpdeskColumn[]; fields: HelpdeskField[] };
    priorities: ItemPriority[];
    projectUsers: User[];
    initialColumnId: number | null;
}

/** A default field of the public form (name / message): shown unless hidden, maybe required. */
function defaultField(settings: Record<string, unknown> | undefined, key: string, label: string) {
    const config = (settings?.[key] ?? {}) as { hidden?: boolean; is_required?: boolean; label?: string };
    return { shown: !config.hidden, required: !!config.is_required, label: config.label || label };
}

/**
 * Create a ticket on behalf of a customer: same fields and required rules as the public
 * support form, plus status / priority / assignee.
 */
export default function NewTicketDialog({ open, onOpenChange, workspace, project, helpdesk, priorities, projectUsers, initialColumnId }: Props) {
    const settings = helpdesk.settings as Record<string, unknown> | undefined;
    const name = defaultField(settings, 'default_field_contact_name', 'Name');
    const message = defaultField(settings, 'default_field_description', 'Message');
    const fields = useMemo(() => [...helpdesk.fields].sort((a, b) => a.position - b.position), [helpdesk.fields]);

    const blank = (): Values => ({
        contact_email: '',
        contact_name: '',
        subject: '',
        description: '',
        column_id: String(initialColumnId ?? helpdesk.columns[0]?.id ?? ''),
        priority_id: '',
        assigned_to: '',
        notify_contact: true,
        ...Object.fromEntries(fields.map((f) => [`field_${f.name}`, f.type === 'checkboxes' ? [] : f.type === 'checkbox' ? false : ''])),
    });

    const [values, setValues] = useState<Values>(blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            setValues(blank());
            setErrors({});
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialColumnId]);

    const set = (key: string, value: string | string[] | boolean) => setValues((v) => ({ ...v, [key]: value }));
    const str = (key: string) => (values[key] as string) ?? '';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post(
            route('tickets.store', [workspace.slug, project.slug, helpdesk.slug]),
            {
                ...values,
                priority_id: values.priority_id || null,
                assigned_to: values.assigned_to || null,
                notify_contact: values.notify_contact ? 1 : 0,
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errs) => setErrors(errs),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>New ticket</DialogTitle>
                    <DialogDescription>Create a ticket for a customer, e.g. from a call or an email. Required fields match your support form.</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-5">
                    <section className="space-y-3">
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Customer</h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FieldBlock label="Email" required error={errors.contact_email}>
                                <div className="relative">
                                    <Mail className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        type="email"
                                        value={str('contact_email')}
                                        onChange={(e) => set('contact_email', e.target.value)}
                                        placeholder="customer@example.com"
                                        className="pl-8"
                                        required
                                        autoFocus
                                    />
                                </div>
                            </FieldBlock>
                            {name.shown && (
                                <FieldBlock label={name.label} required={name.required} error={errors.contact_name}>
                                    <div className="relative">
                                        <UserRound className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input value={str('contact_name')} onChange={(e) => set('contact_name', e.target.value)} className="pl-8" required={name.required} />
                                    </div>
                                </FieldBlock>
                            )}
                        </div>
                    </section>

                    <Separator />

                    <section className="space-y-3">
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Request</h3>
                        <FieldBlock label="Subject" required error={errors.subject}>
                            <Input value={str('subject')} onChange={(e) => set('subject', e.target.value)} maxLength={255} required />
                        </FieldBlock>
                        {message.shown && (
                            <FieldBlock label={message.label} required={message.required} error={errors.description}>
                                <Textarea value={str('description')} onChange={(e) => set('description', e.target.value)} rows={4} required={message.required} />
                            </FieldBlock>
                        )}
                        {fields.map((field) => (
                            <CustomField key={field.id} field={field} value={values[`field_${field.name}`]} onChange={(v) => set(`field_${field.name}`, v)} error={errors[`field_${field.name}`]} />
                        ))}
                    </section>

                    <Separator />

                    <section className="grid gap-3 sm:grid-cols-3">
                        <FieldBlock label="Status" error={errors.column_id}>
                            <Select value={str('column_id')} onValueChange={(v) => set('column_id', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {helpdesk.columns.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FieldBlock>
                        <FieldBlock label="Priority" error={errors.priority_id}>
                            <Select value={str('priority_id') || 'default'} onValueChange={(v) => set('priority_id', v === 'default' ? '' : v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="default">Default</SelectItem>
                                    {priorities.map((p) => (
                                        <SelectItem key={p.id} value={String(p.id)}>
                                            {p.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FieldBlock>
                        <FieldBlock label="Assignee" error={errors.assigned_to}>
                            <Select value={str('assigned_to') || 'none'} onValueChange={(v) => set('assigned_to', v === 'none' ? '' : v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Unassigned</SelectItem>
                                    {projectUsers.map((u) => (
                                        <SelectItem key={u.id} value={String(u.id)}>
                                            {u.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FieldBlock>
                    </section>

                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={!!values.notify_contact} onChange={(c) => set('notify_contact', c)} />
                        Email the customer a link to this ticket
                    </label>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Loader2 className="size-4 animate-spin" />}
                            Create ticket
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FieldBlock({ label, required, error, children }: { label: string; required?: boolean; error?: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <Label>
                {label}
                {required && <span className="text-destructive"> *</span>}
            </Label>
            {children}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

function CustomField({ field, value, onChange, error }: { field: HelpdeskField; value: unknown; onChange: (v: string | string[] | boolean) => void; error?: string }) {
    const type = (field.type || 'text').toLowerCase();
    const text = (value as string) ?? '';

    if (type === 'file') {
        return (
            <FieldBlock label={field.label} error={error}>
                <p className="text-xs text-muted-foreground">The customer can upload files from the ticket link; you can attach files in the ticket after creating it.</p>
            </FieldBlock>
        );
    }

    let control: ReactNode;
    if (type === 'select') {
        control = (
            <Select value={text} onValueChange={onChange}>
                <SelectTrigger>
                    <SelectValue placeholder="Choose…" />
                </SelectTrigger>
                <SelectContent>
                    {(field.options ?? []).map((opt) => (
                        <SelectItem key={opt} value={opt}>
                            {opt}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    } else if (type === 'radio') {
        control = (
            <div className="flex flex-wrap gap-3">
                {(field.options ?? []).map((opt) => (
                    <label key={opt} className="flex items-center gap-1.5 text-sm">
                        <input type="radio" name={`field_${field.name}`} checked={text === opt} onChange={() => onChange(opt)} required={field.is_required} />
                        {opt}
                    </label>
                ))}
            </div>
        );
    } else if (type === 'checkboxes') {
        const selected = (value as string[]) ?? [];
        control = (
            <div className="flex flex-wrap gap-3">
                {(field.options ?? []).map((opt) => (
                    <label key={opt} className="flex items-center gap-1.5 text-sm">
                        <Checkbox checked={selected.includes(opt)} onChange={(c) => onChange(c ? [...selected, opt] : selected.filter((s) => s !== opt))} />
                        {opt}
                    </label>
                ))}
            </div>
        );
    } else if (type === 'checkbox') {
        return (
            <div className="space-y-1">
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={value === true} onChange={(c) => onChange(c)} />
                    {field.label}
                    {field.is_required && <span className="text-destructive">*</span>}
                </label>
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
        );
    } else if (type === 'rich_text' || type === 'textarea') {
        control = <Textarea value={text} onChange={(e) => onChange(e.target.value)} rows={3} required={field.is_required} />;
    } else {
        control = (
            <Input
                type={type === 'number' ? 'number' : type === 'date' ? 'date' : 'text'}
                value={text}
                onChange={(e) => onChange(e.target.value)}
                required={field.is_required}
            />
        );
    }

    return (
        <FieldBlock label={field.label} required={field.is_required} error={error}>
            {control}
        </FieldBlock>
    );
}

function Checkbox({ checked, onChange }: { checked: boolean; onChange: (checked: boolean) => void }) {
    return <input type="checkbox" className="size-4 rounded border-input accent-primary" checked={checked} onChange={(e) => onChange(e.target.checked)} />;
}
