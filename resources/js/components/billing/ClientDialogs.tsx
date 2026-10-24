import { useEffect, useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ProjectIcon } from '@/components/ProjectIcon';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import type { BillingClient, BillingClientProject, PageProps } from '@/types';

/** The server's flash message after a client write (there is no global flash toast). */
export const flashSuccess = (page: { props: unknown }, fallback: string) => toast.success((page.props as PageProps).flash?.success ?? fallback);
export const firstError = (errors: Record<string, string>, fallback = 'Could not save.') => toast.error(Object.values(errors)[0] ?? fallback);

/** Create / edit a client (BRAI-100). `client` null = create. */
export function ClientFormDialog({ workspaceSlug, client, open, onOpenChange, defaultTaxRate }: {
    workspaceSlug: string;
    client: BillingClient | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    defaultTaxRate: number;
}) {
    const form = useForm({ name: '', email: '', address: '', vat_id: '', tax_rate: '', notes: '' });

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({
            name: client?.name ?? '',
            email: client?.email ?? '',
            address: client?.address ?? '',
            vat_id: client?.vat_id ?? '',
            tax_rate: client?.tax_rate === null || client?.tax_rate === undefined ? '' : String(client.tax_rate),
            notes: client?.notes ?? '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, client?.id]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: (page: { props: unknown }) => {
                flashSuccess(page, client ? 'Client saved.' : 'Client created.');
                onOpenChange(false);
            },
        };
        if (client) form.put(route('billing.clients.update', { workspace: workspaceSlug, client: client.id }), options);
        else form.post(route('billing.clients.store', workspaceSlug), options);
    };

    const field = (id: keyof typeof form.data, label: string, input: React.ReactNode, hint?: string) => (
        <div className="space-y-1.5">
            <Label htmlFor={`client-${id}`}>{label}</Label>
            {input}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {form.errors[id] && <p className="text-sm text-destructive">{form.errors[id]}</p>}
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{client ? `Edit ${client.name}` : 'New client'}</DialogTitle>
                    <DialogDescription>Printed on time reports and invoices for this client.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4" id="client-form">
                    {field('name', 'Name', <Input id="client-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required maxLength={160} autoFocus />)}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('email', 'Billing email', <Input id="client-email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} maxLength={255} />)}
                        {field('vat_id', 'VAT ID', <Input id="client-vat_id" value={form.data.vat_id} onChange={(e) => form.setData('vat_id', e.target.value)} maxLength={40} />)}
                    </div>
                    {field(
                        'tax_rate',
                        'VAT rate (%)',
                        <Input id="client-tax_rate" type="number" inputMode="decimal" min={0} max={100} step="0.01" placeholder={String(defaultTaxRate)} value={form.data.tax_rate} onChange={(e) => form.setData('tax_rate', e.target.value)} className="max-w-[10rem]" />,
                        `Leave empty for the workspace default (${defaultTaxRate} %).`,
                    )}
                    {field('address', 'Address', <Textarea id="client-address" rows={3} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} maxLength={2000} />)}
                    {field('notes', 'Notes', <Textarea id="client-notes" rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={5000} />, 'Internal, never printed.')}
                </form>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="client-form" disabled={form.processing}>{client ? 'Save client' : 'Create client'}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Pick the projects billed to a client: syncs the whole set (BRAI-100). */
export function AssignProjectsDialog({ workspaceSlug, client, projects, clientNames, open, onOpenChange }: {
    workspaceSlug: string;
    client: BillingClient | null;
    projects: BillingClientProject[];
    clientNames: Record<number, string>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [selected, setSelected] = useState<number[]>([]);
    const [query, setQuery] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open && client) {
            setSelected(client.projects.map((p) => p.id));
            setQuery('');
        }
    }, [open, client]);

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        return q ? projects.filter((p) => p.name.toLowerCase().includes(q) || p.key?.toLowerCase().includes(q)) : projects;
    }, [projects, query]);

    if (!client) return null;

    const toggle = (id: number) => setSelected((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
    const save = () => {
        setSaving(true);
        router.put(route('billing.clients.projects.update', { workspace: workspaceSlug, client: client.id }), { project_ids: selected }, {
            preserveScroll: true,
            onSuccess: (page) => {
                flashSuccess(page, 'Projects saved.');
                onOpenChange(false);
            },
            onError: (errors) => firstError(errors, 'Could not save the projects. Reload and try again.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Projects of {client.name}</DialogTitle>
                    <DialogDescription>Time on these projects is billed to {client.name}. A project belongs to one client at a time.</DialogDescription>
                </DialogHeader>
                {projects.length > 6 && (
                    <Input placeholder="Search projects…" value={query} onChange={(e) => setQuery(e.target.value)} aria-label="Search projects" />
                )}
                <ul className="max-h-[50vh] space-y-1 overflow-y-auto" aria-label="Workspace projects">
                    {visible.map((project) => {
                        const checked = selected.includes(project.id);
                        const elsewhere = project.client_id && project.client_id !== client.id ? clientNames[project.client_id] : null;
                        return (
                            <li key={project.id}>
                                <label className={cn('flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 transition-colors hover:bg-accent/50', checked && 'border-primary/50 bg-primary/5')}>
                                    <input type="checkbox" className="size-4 rounded border-border bg-background text-primary focus:ring-primary focus:ring-offset-background" checked={checked} onChange={() => toggle(project.id)} />
                                    <ProjectIcon project={project} size="xs" />
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{project.name}</span>
                                    {elsewhere && (
                                        <span className="shrink-0 text-xs text-muted-foreground">{checked ? `moves from ${elsewhere}` : elsewhere}</span>
                                    )}
                                </label>
                            </li>
                        );
                    })}
                    {visible.length === 0 && <li className="px-3 py-6 text-center text-sm text-muted-foreground">No projects found.</li>}
                </ul>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="button" onClick={save} disabled={saving}>Save projects</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
