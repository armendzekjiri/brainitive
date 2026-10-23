import { useMemo, useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { toast } from 'sonner';
import type { ProjectTemplateCategory, ProjectTemplateOption, ProjectTemplateSection } from '@/types';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import TemplateSummary, { availableSections, SECTION_LABELS, summaryLine } from '@/components/projects/TemplateSummary';
import {
    ArrowLeft, Bug, CalendarDays, ChevronRight, CircleDollarSign, CodeXml, FilePlus, FileText, GanttChart, Globe,
    Handshake, LayoutGrid, LayoutTemplate, ListChecks, ListTodo, Loader2, Megaphone, Monitor, NotebookPen, Palette,
    PartyPopper, Repeat, Rocket, Search, Server, Share2, SlidersHorizontal, Tag, Target, Trash2, TrendingUp, UserPlus,
    UserRound, Users, Wallet,
} from 'lucide-react';

type IconType = React.ComponentType<{ className?: string }>;

const TEMPLATE_ICONS: Record<string, IconType> = {
    code: CodeXml, repeat: Repeat, bug: Bug, server: Server, megaphone: Megaphone, 'calendar-days': CalendarDays,
    'share-2': Share2, 'party-popper': PartyPopper, rocket: Rocket, globe: Globe, handshake: Handshake, palette: Palette,
    'user-plus': UserPlus, users: Users, 'trending-up': TrendingUp, 'circle-dollar-sign': CircleDollarSign, monitor: Monitor,
    'list-todo': ListTodo, target: Target, 'notebook-pen': NotebookPen, 'layout-template': LayoutTemplate,
};

const CATEGORY_ICONS: Record<string, IconType> = {
    all: LayoutGrid, workspace: LayoutTemplate, software: CodeXml, marketing: Megaphone, agency: Handshake,
    hr: Users, sales: TrendingUp, operations: Wallet, personal: UserRound,
};

/** Icon tile colours per category (light + dark friendly: tinted background, saturated icon). */
const CATEGORY_TONES: Record<string, string> = {
    workspace: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
    software: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    marketing: 'bg-pink-500/10 text-pink-600 dark:text-pink-400',
    agency: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    hr: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    sales: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    operations: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    personal: 'bg-teal-500/10 text-teal-600 dark:text-teal-400',
};

const ALL_SECTIONS: ProjectTemplateSection[] = ['issues', 'pages', 'task_lists', 'plans'];

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

const generateKey = (name: string) => name.replace(/[^a-zA-Z]/g, '').toUpperCase().substring(0, 4);

type View = { step: 'browse' } | { step: 'detail'; id: string } | { step: 'form'; id: string };

/**
 * Jira-style template gallery (BRAI-91): browse by category or search, open a template to
 * see what you get, then name the project. Used by the "New project" popup and page.
 */
export default function TemplateGallery({ workspace, templates, categories, loading = false, variant, onCancel, onDeleted }: {
    workspace: { slug: string; name: string };
    templates: ProjectTemplateOption[];
    categories: ProjectTemplateCategory[];
    loading?: boolean;
    variant: 'dialog' | 'page';
    onCancel?: () => void;
    /** A workspace template was deleted (the caller refreshes its list). */
    onDeleted?: (id: string) => void;
}) {
    const [view, setView] = useState<View>({ step: 'browse' });
    const [category, setCategory] = useState<string>('all');
    const [query, setQuery] = useState('');
    const [deleting, setDeleting] = useState<ProjectTemplateOption | null>(null);
    // One id per open gallery: a retried submit never creates a second project
    const [requestId] = useState(newRequestId);

    const { data, setData, post, processing, errors, transform, clearErrors } = useForm({
        name: '',
        key: '',
        description: '',
        template: '',
        start_date: today(),
        include: ALL_SECTIONS as ProjectTemplateSection[],
    });

    const byId = useMemo(() => new Map(templates.map((t) => [t.id, t])), [templates]);
    const categoryName = (key: string) => categories.find((c) => c.key === key)?.name ?? key;
    const counts = useMemo(() => templates.reduce<Record<string, number>>((acc, t) => ({ ...acc, [t.category]: (acc[t.category] ?? 0) + 1 }), {}), [templates]);

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        return templates.filter((t) => (category === 'all' || t.category === category)
            && (!q || [t.name, t.description ?? '', categoryName(t.category), ...t.tags].join(' ').toLowerCase().includes(q)));
    }, [templates, category, query, categories]);

    const current = view.step !== 'browse' ? byId.get(view.id) ?? null : null;

    const openForm = (id: string) => {
        clearErrors();
        setData((form) => ({ ...form, template: id, include: ALL_SECTIONS }));
        setView({ step: 'form', id });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        transform((form) => (form.template
            ? { ...form, include: form.include.length ? form.include : ['none'], request_id: requestId }
            : { name: form.name, key: form.key, description: form.description }));
        post(route('projects.store', workspace.slug), { preserveScroll: true });
    };

    const remove = (template: ProjectTemplateOption) => {
        router.delete(route('project-templates.destroy', [workspace.slug, template.uuid!]), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`Template “${template.name}” deleted.`);
                // The category may be gone with its last template
                setCategory('all');
                setView({ step: 'browse' });
                onDeleted?.(template.id);
            },
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'The template could not be deleted.'),
        });
    };

    const tile = (template: ProjectTemplateOption | null, size: 'sm' | 'lg' = 'sm') => {
        const Icon = template ? TEMPLATE_ICONS[template.icon] ?? LayoutTemplate : FilePlus;
        return (
            <span className={cn('flex shrink-0 items-center justify-center rounded-lg', size === 'lg' ? 'size-12' : 'size-9',
                template ? CATEGORY_TONES[template.category] ?? CATEGORY_TONES.workspace : 'bg-muted text-muted-foreground')}>
                <Icon className={size === 'lg' ? 'size-6' : 'size-4'} />
            </span>
        );
    };

    const chips = (values: string[]) => (
        <div className="flex flex-wrap gap-1.5">
            {values.map((value, i) => <span key={`${value}-${i}`} className="rounded-[4px] border bg-muted/40 px-2 py-0.5 text-xs">{value}</span>)}
        </div>
    );

    // ── Step 1: browse ──────────────────────────────────────────────────────
    const browse = (
        <div className="flex min-h-0 flex-1">
            <nav aria-label="Template categories" className="hidden w-56 shrink-0 flex-col gap-0.5 overflow-y-auto border-r p-3 md:flex">
                {[{ key: 'all', name: 'All templates' }, ...categories].map((c) => {
                    const Icon = CATEGORY_ICONS[c.key] ?? LayoutGrid;
                    const active = category === c.key;
                    return (
                        <button
                            key={c.key}
                            type="button"
                            onClick={() => setCategory(c.key)}
                            aria-current={active ? 'true' : undefined}
                            className={cn('flex items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm transition-colors',
                                active ? 'bg-secondary font-medium text-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground')}
                        >
                            <Icon className="size-4 shrink-0" />
                            <span className="flex-1 truncate">{c.name}</span>
                            <span className="text-xs tabular-nums text-muted-foreground">{c.key === 'all' ? templates.length + 1 : counts[c.key] ?? 0}</span>
                        </button>
                    );
                })}
            </nav>

            <div className="flex min-w-0 flex-1 flex-col">
                <div className="flex flex-wrap items-center gap-2 border-b p-4">
                    <div className="relative min-w-[200px] flex-1">
                        <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search templates…" className="pl-8" aria-label="Search templates" />
                    </div>
                    <div className="md:hidden">
                        <Select value={category} onValueChange={setCategory}>
                            <SelectTrigger className="w-[180px]" aria-label="Category"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All templates</SelectItem>
                                {categories.map((c) => <SelectItem key={c.key} value={c.key}>{c.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-4">
                    {loading ? (
                        <div role="status" className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" /> Loading templates…</div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {category === 'all' && !query.trim() && (
                                <button type="button" onClick={() => openForm('')} className="flex flex-col items-start gap-2 rounded-lg border border-dashed bg-card p-4 text-left transition-colors hover:border-primary hover:bg-muted/40">
                                    <div className="flex items-center gap-3">{tile(null)}<span className="font-medium">Blank project</span></div>
                                    <p className="text-xs text-muted-foreground">Start empty: one Kanban board with To Do, In Progress and Done.</p>
                                </button>
                            )}
                            {visible.map((template) => (
                                <button
                                    key={template.id}
                                    type="button"
                                    onClick={() => setView({ step: 'detail', id: template.id })}
                                    className="flex flex-col items-start gap-2 rounded-lg border bg-card p-4 text-left transition-colors hover:border-primary hover:bg-muted/40"
                                >
                                    <div className="flex w-full items-center gap-3">
                                        {tile(template)}
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{template.name}</p>
                                            <p className="text-[11px] text-muted-foreground">{categoryName(template.category)}</p>
                                        </div>
                                    </div>
                                    {template.description && <p className="line-clamp-2 text-xs text-muted-foreground">{template.description}</p>}
                                    <div className="mt-auto flex flex-wrap gap-1 pt-1">
                                        {template.tags.map((tag) => <Badge key={tag} variant="secondary" className="px-1.5 py-0 text-[10px] font-normal">{tag}</Badge>)}
                                        {template.source === 'workspace' && <Badge variant="outline" className="px-1.5 py-0 text-[10px] font-normal">{summaryLine(template.summary)}</Badge>}
                                    </div>
                                </button>
                            ))}
                            {visible.length === 0 && (
                                <p className="col-span-full py-12 text-center text-sm text-muted-foreground">
                                    {query.trim() ? `No templates match “${query.trim()}”.` : 'No templates here yet.'}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );

    // ── Step 2: what you get ────────────────────────────────────────────────
    const renderDetail = (template: ProjectTemplateOption) => {
        const s = template.summary;
        return (
            <div className="flex min-h-0 flex-1 flex-col">
                <div className="flex flex-wrap items-start gap-4 border-b p-5">
                    <Button type="button" variant="ghost" size="sm" className="-ml-2 gap-1.5" onClick={() => setView({ step: 'browse' })}>
                        <ArrowLeft className="size-4" /> All templates
                    </Button>
                    <div className="flex min-w-0 flex-1 basis-full items-start gap-4 sm:basis-auto">
                        {tile(template, 'lg')}
                        <div className="min-w-0">
                            <h3 className="text-lg font-semibold leading-tight">{template.name}</h3>
                            <p className="text-xs text-muted-foreground">
                                {categoryName(template.category)}
                                {template.source === 'workspace' && template.created_by ? ` · saved by ${template.created_by} · v${template.version}` : ''}
                            </p>
                            {template.description && <p className="mt-1.5 max-w-2xl text-sm text-muted-foreground">{template.description}</p>}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {template.can_delete && (
                            <Button type="button" variant="ghost" size="sm" className="gap-1.5 text-muted-foreground hover:text-destructive" onClick={() => setDeleting(template)}>
                                <Trash2 className="size-4" /> Delete
                            </Button>
                        )}
                        <Button type="button" onClick={() => openForm(template.id)}>Use template</Button>
                    </div>
                </div>

                <div className="grid min-h-0 flex-1 gap-6 overflow-y-auto p-5 lg:grid-cols-[minmax(0,1fr)_300px]">
                    <div className="space-y-6">
                        {s.boards.map((board, b) => (
                            <section key={`${board.name}-${b}`} className="space-y-2">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Workflow · {board.name} <span className="font-normal normal-case">({board.type === 'scrum' ? 'Scrum' : 'Kanban'})</span>
                                </h4>
                                <div className="flex flex-wrap items-center gap-1">
                                    {board.columns.map((column, i) => (
                                        <span key={`${column}-${i}`} className="flex items-center gap-1">
                                            {i > 0 && <ChevronRight className="size-3.5 text-muted-foreground" aria-hidden />}
                                            <span className={cn('rounded-[4px] border px-2 py-1 text-xs font-medium',
                                                i === board.columns.length - 1 ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-muted/40')}>{column}</span>
                                        </span>
                                    ))}
                                </div>
                            </section>
                        ))}

                        {(s.issue_types?.length ?? 0) > 0 && (
                            <section className="space-y-2">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Issue types</h4>
                                {chips(s.issue_types ?? [])}
                            </section>
                        )}
                        {s.fields.length > 0 && (
                            <section className="space-y-2">
                                <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><SlidersHorizontal className="size-3.5" />Fields</h4>
                                {chips(s.fields)}
                            </section>
                        )}
                        {(s.label_names?.length ?? 0) > 0 && (
                            <section className="space-y-2">
                                <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><Tag className="size-3.5" />Labels</h4>
                                {chips(s.label_names ?? [])}
                            </section>
                        )}
                        {(s.sample_issues?.length ?? 0) > 0 && (
                            <section className="space-y-2">
                                <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Starter issues ({s.issues})</h4>
                                <ul className="divide-y rounded-lg border">
                                    {(s.sample_issues ?? []).map((issue, i) => (
                                        <li key={`${issue.title}-${i}`} className={cn('flex items-center gap-2 px-3 py-2 text-sm', issue.subtask && 'pl-8')}>
                                            <span className="w-16 shrink-0 text-[11px] text-muted-foreground">{issue.type}</span>
                                            <span className="truncate">{issue.title}</span>
                                        </li>
                                    ))}
                                    {s.issues > (s.sample_issues?.length ?? 0) && <li className="px-3 py-2 text-xs text-muted-foreground">+ {s.issues - (s.sample_issues?.length ?? 0)} more</li>}
                                </ul>
                            </section>
                        )}
                    </div>

                    <aside className="space-y-5">
                        {(s.doc_names?.length ?? 0) > 0 && (
                            <section className="space-y-2">
                                <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><FileText className="size-3.5" />Docs</h4>
                                <ul className="space-y-1 text-sm">{(s.doc_names ?? []).map((name, i) => <li key={`${name}-${i}`}>{name}</li>)}</ul>
                            </section>
                        )}
                        {s.task_lists.length > 0 && (
                            <section className="space-y-2">
                                <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><ListChecks className="size-3.5" />Checklists</h4>
                                <ul className="space-y-1 text-sm">{s.task_lists.map((list, i) => <li key={`${list.name}-${i}`}>{list.name} <span className="text-muted-foreground">({list.tasks})</span></li>)}</ul>
                            </section>
                        )}
                        {s.plans.length > 0 && (
                            <section className="space-y-2">
                                <h4 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"><GanttChart className="size-3.5" />Plans</h4>
                                <ul className="space-y-1 text-sm">{s.plans.map((plan, i) => <li key={`${plan}-${i}`}>{plan}</li>)}</ul>
                            </section>
                        )}
                        <p className="rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
                            Due dates count from the start date you pick. Nobody is assigned, and you can leave out issues, docs, checklists or plans in the next step.
                        </p>
                    </aside>
                </div>
            </div>
        );
    };

    // ── Step 3: name the project ────────────────────────────────────────────
    const selected = view.step === 'form' ? byId.get(view.id) ?? null : null;
    const sections = selected ? availableSections(selected.summary) : [];
    const form = view.step === 'form' && (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <div className="flex items-center gap-3 border-b p-5">
                <Button type="button" variant="ghost" size="sm" className="-ml-2 gap-1.5" onClick={() => setView(selected ? { step: 'detail', id: selected.id } : { step: 'browse' })}>
                    <ArrowLeft className="size-4" /> Back
                </Button>
                {tile(selected)}
                <div className="min-w-0">
                    <h3 className="font-semibold leading-tight">{selected ? selected.name : 'Blank project'}</h3>
                    <p className="text-xs text-muted-foreground">New project in {workspace.name}</p>
                </div>
            </div>

            <div className={cn('grid min-h-0 flex-1 gap-6 overflow-y-auto p-5', selected && 'lg:grid-cols-[minmax(0,1fr)_300px]')}>
                <div className="max-w-xl space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="tg-name">Project name</Label>
                        <Input
                            id="tg-name"
                            value={data.name}
                            autoFocus
                            required
                            placeholder="My Project"
                            onChange={(e) => {
                                const value = e.target.value;
                                setData((f) => ({ ...f, name: value, key: !f.key || f.key === generateKey(f.name) ? generateKey(value) : f.key }));
                            }}
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="tg-key">Project key</Label>
                        <Input id="tg-key" value={data.key} required maxLength={10} placeholder="PROJ" className="max-w-[200px] font-mono"
                            onChange={(e) => setData('key', e.target.value.toUpperCase().replace(/[^A-Z]/g, '').substring(0, 10))} />
                        <p className="text-xs text-muted-foreground">Issue IDs look like {data.key || 'PROJ'}-1, {data.key || 'PROJ'}-2.</p>
                        {errors.key && <p className="text-sm text-destructive">{errors.key}</p>}
                    </div>
                    {selected && (
                        <div className="space-y-2">
                            <Label htmlFor="tg-start">Start date</Label>
                            <Input id="tg-start" type="date" value={data.start_date} required className="max-w-[200px]" onChange={(e) => setData('start_date', e.target.value)} />
                            <p className="text-xs text-muted-foreground">Due dates and plan bars are counted in days from this date.</p>
                            {errors.start_date && <p className="text-sm text-destructive">{errors.start_date}</p>}
                        </div>
                    )}
                    <div className="space-y-2">
                        <Label htmlFor="tg-description">Description (optional)</Label>
                        <Textarea id="tg-description" rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} placeholder="What is this project about?" />
                        {errors.description && <p className="text-sm text-destructive">{errors.description}</p>}
                    </div>
                    {selected && sections.length > 0 && (
                        <fieldset className="space-y-1.5">
                            <legend className="mb-1 text-sm font-medium">Include</legend>
                            {sections.map((section) => (
                                <label key={section} className="flex cursor-pointer items-center gap-2 text-sm">
                                    <input type="checkbox" className="size-4 accent-primary" checked={data.include.includes(section)}
                                        onChange={() => setData('include', data.include.includes(section) ? data.include.filter((x) => x !== section) : [...data.include, section])} />
                                    {SECTION_LABELS[section]}
                                </label>
                            ))}
                            <p className="pt-1 text-xs text-muted-foreground">Boards, columns, labels and fields always come along.</p>
                        </fieldset>
                    )}
                    {errors.template && <p className="text-sm text-destructive">{errors.template}</p>}
                    {errors['include.0'] && <p className="text-sm text-destructive">{errors['include.0']}</p>}
                </div>
                {selected && (
                    <aside className="order-first rounded-lg border bg-muted/20 p-4 lg:order-none lg:self-start">
                        <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">What you'll get</p>
                        <TemplateSummary summary={selected.summary} include={data.include} />
                    </aside>
                )}
            </div>

            <div className="flex justify-end gap-2 border-t p-4">
                {onCancel && <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>}
                <Button type="submit" disabled={processing}>{processing ? 'Creating…' : 'Create project'}</Button>
            </div>
        </form>
    );

    return (
        <div className={cn('flex min-h-0 flex-col', variant === 'dialog' ? 'h-full' : 'h-[calc(100vh-10rem)] min-h-[560px] overflow-hidden rounded-xl border bg-background')}>
            {view.step === 'browse' && browse}
            {view.step === 'detail' && (current ? renderDetail(current) : browse)}
            {form}

            <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete the template “{deleting?.name}”?</AlertDialogTitle>
                        <AlertDialogDescription>Projects already created from it stay as they are.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={() => deleting && remove(deleting)}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
