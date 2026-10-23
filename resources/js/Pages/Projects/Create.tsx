import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { useMemo, useState } from 'react';
import { PageProps, ProjectTemplateOption, ProjectTemplateSection, Workspace } from '@/types';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import TemplateSummary, { availableSections, SECTION_LABELS, summaryLine } from '@/components/projects/TemplateSummary';
import { Check, CodeXml, FilePlus, Globe, LayoutTemplate, Repeat, Rocket, Trash2 } from 'lucide-react';

interface Props extends PageProps {
    workspace: Workspace;
    templates?: ProjectTemplateOption[];
}

const ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
    code: CodeXml,
    repeat: Repeat,
    globe: Globe,
    rocket: Rocket,
    'layout-template': LayoutTemplate,
};

const ALL_SECTIONS: ProjectTemplateSection[] = ['issues', 'pages', 'task_lists', 'plans'];

const CARD = 'flex h-full w-full flex-col items-start gap-2 rounded-lg border p-4 text-left transition-colors';
const CARD_ACTIVE = 'border-primary bg-primary/5 ring-1 ring-primary';
const CARD_IDLE = 'bg-card hover:bg-muted/40';

function today(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function newRequestId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export default function ProjectsCreate({ workspace, templates = [] }: Props) {
    // One id per visit: a retried submit (double click, flaky network) never creates twice
    const [requestId] = useState(newRequestId);
    const [deleting, setDeleting] = useState<ProjectTemplateOption | null>(null);
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        key: '',
        description: '',
        template: '',
        start_date: today(),
        include: ALL_SECTIONS as ProjectTemplateSection[],
    });

    const selected = useMemo(() => templates.find((t) => t.id === data.template) ?? null, [templates, data.template]);
    const sections = selected ? availableSections(selected.summary) : [];
    const builtins = templates.filter((t) => t.source === 'builtin');
    const saved = templates.filter((t) => t.source === 'workspace');

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        transform((form) => (form.template
            ? { ...form, include: form.include.length ? form.include : ['none'], request_id: requestId }
            : { name: form.name, key: form.key, description: form.description }));
        post(route('projects.store', workspace.slug));
    };

    const generateKey = (name: string) => name.replace(/[^a-zA-Z]/g, '').toUpperCase().substring(0, 4);
    const handleNameChange = (value: string) => {
        setData((current) => ({
            ...current,
            name: value,
            key: !current.key || current.key === generateKey(current.name) ? generateKey(value) : current.key,
        }));
    };

    const toggleSection = (section: ProjectTemplateSection) =>
        setData('include', data.include.includes(section) ? data.include.filter((s) => s !== section) : [...data.include, section]);

    const choose = (id: string) => setData((current) => ({ ...current, template: id, include: ALL_SECTIONS }));

    const renderCard = (template: ProjectTemplateOption) => {
        const Icon = ICONS[template.icon] ?? LayoutTemplate;
        const active = data.template === template.id;
        return (
            <div key={template.id} className="relative">
                <button type="button" role="radio" onClick={() => choose(template.id)} aria-checked={active} className={cn(CARD, active ? CARD_ACTIVE : CARD_IDLE)}>
                    <div className="flex w-full items-center gap-2">
                        <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-md', active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>
                            <Icon className="size-4" />
                        </span>
                        <span className={cn('font-medium leading-tight', template.can_delete && 'pr-7')}>{template.name}</span>
                        {active && !template.can_delete && <Check className="ml-auto size-4 shrink-0 text-primary" />}
                    </div>
                    {template.description && <p className="text-xs text-muted-foreground line-clamp-2">{template.description}</p>}
                    <p className="mt-auto text-[11px] text-muted-foreground">{summaryLine(template.summary)}</p>
                </button>
                {template.can_delete && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="absolute right-2 top-2 size-7 text-muted-foreground hover:text-destructive"
                        onClick={() => setDeleting(template)}
                        aria-label={`Delete template ${template.name}`}
                    >
                        <Trash2 className="size-3.5" />
                    </Button>
                )}
            </div>
        );
    };

    return (
        <AppLayout>
            <Head title="Create Project" />

            <div className="p-6">
                <form onSubmit={submit} className="max-w-5xl mx-auto space-y-6">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Create Project</h1>
                        <p className="text-muted-foreground">Add a new project to {workspace.name}</p>
                    </div>

                    {/* Start from */}
                    <section className="space-y-3" aria-labelledby="start-from">
                        <h2 id="start-from" className="text-sm font-semibold">Start from</h2>
                        <div role="radiogroup" aria-labelledby="start-from" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <button type="button" role="radio" onClick={() => setData('template', '')} aria-checked={data.template === ''} className={cn(CARD, data.template === '' ? CARD_ACTIVE : CARD_IDLE)}>
                                <div className="flex w-full items-center gap-2">
                                    <span className={cn('flex size-8 items-center justify-center rounded-md', data.template === '' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>
                                        <FilePlus className="size-4" />
                                    </span>
                                    <span className="font-medium">Blank project</span>
                                    {data.template === '' && <Check className="ml-auto size-4 text-primary" />}
                                </div>
                                <p className="text-xs text-muted-foreground">One Kanban board with To Do, In Progress and Done.</p>
                            </button>
                            {builtins.map(renderCard)}
                        </div>
                        {saved.length > 0 && (
                            <>
                                <h3 className="pt-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">From your workspace</h3>
                                <div role="radiogroup" aria-label="Workspace templates" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{saved.map(renderCard)}</div>
                            </>
                        )}
                        {errors.template && <p className="text-sm text-destructive">{errors.template}</p>}
                    </section>

                    <div className={cn('grid gap-6', selected && 'lg:grid-cols-[minmax(0,1fr)_340px]')}>
                        <Card>
                            <CardHeader>
                                <CardTitle>Project Details</CardTitle>
                                <CardDescription>
                                    {selected
                                        ? `Everything from “${selected.name}” is created fresh — later changes to the template don't touch this project.`
                                        : 'Create a new project to organize your work into boards and tasks.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Project Name</Label>
                                    <Input id="name" type="text" value={data.name} onChange={(e) => handleNameChange(e.target.value)} placeholder="My Project" required />
                                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="key">Project Key</Label>
                                    <Input
                                        id="key"
                                        type="text"
                                        value={data.key}
                                        onChange={(e) => setData('key', e.target.value.toUpperCase().replace(/[^A-Z]/g, '').substring(0, 10))}
                                        placeholder="PROJ"
                                        maxLength={10}
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        This key will be used for issue IDs (e.g., {data.key || 'PROJ'}-1, {data.key || 'PROJ'}-2)
                                    </p>
                                    {errors.key && <p className="text-sm text-destructive">{errors.key}</p>}
                                </div>

                                {selected && (
                                    <div className="space-y-2">
                                        <Label htmlFor="start_date">Start date</Label>
                                        <Input id="start_date" type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} required className="max-w-[200px]" />
                                        <p className="text-xs text-muted-foreground">Due dates and plan bars in the template are counted in days from this date.</p>
                                        {errors.start_date && <p className="text-sm text-destructive">{errors.start_date}</p>}
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <Label htmlFor="description">Description (optional)</Label>
                                    <Textarea id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="A brief description of this project..." rows={3} />
                                    {errors.description && <p className="text-sm text-destructive">{errors.description}</p>}
                                </div>

                                <div className="flex justify-end gap-3">
                                    <Link href={route('workspaces.show', workspace.slug)}>
                                        <Button type="button" variant="outline">Cancel</Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Creating…' : selected ? 'Create from template' : 'Create Project'}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        {selected && (
                            // On phones the preview + include choices come before the submit button
                            <Card className="order-first lg:order-none lg:sticky lg:top-4 self-start">
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        What you'll get
                                        {selected.source === 'workspace' && <Badge variant="secondary" className="text-[10px]">v{selected.version}</Badge>}
                                    </CardTitle>
                                    {selected.source === 'workspace' && selected.created_by && (
                                        <CardDescription className="text-xs">Saved by {selected.created_by}</CardDescription>
                                    )}
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <TemplateSummary summary={selected.summary} include={data.include} />
                                    {sections.length > 0 && (
                                        <fieldset className="space-y-1.5 border-t pt-3">
                                            <legend className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Include</legend>
                                            {sections.map((section) => (
                                                <label key={section} className="flex cursor-pointer items-center gap-2 text-sm">
                                                    <input type="checkbox" className="size-4 accent-primary" checked={data.include.includes(section)} onChange={() => toggleSection(section)} />
                                                    {SECTION_LABELS[section]}
                                                </label>
                                            ))}
                                            <p className="pt-1 text-[11px] text-muted-foreground">Boards, columns, labels and fields always come along. Nobody is assigned.</p>
                                        </fieldset>
                                    )}
                                    {errors['include.0'] && <p className="text-sm text-destructive">{errors['include.0']}</p>}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </form>
            </div>

            <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete the template “{deleting?.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>Projects already created from it stay as they are.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={() => {
                                if (!deleting?.uuid) return;
                                const removed = deleting;
                                router.delete(route('project-templates.destroy', [workspace.slug, removed.uuid!]), {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onSuccess: () => {
                                        if (data.template === removed.id) setData('template', '');
                                        toast.success(`Template “${removed.name}” deleted.`);
                                    },
                                    onError: (errs) => toast.error(Object.values(errs)[0] ?? 'The template could not be deleted.'),
                                });
                            }}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
