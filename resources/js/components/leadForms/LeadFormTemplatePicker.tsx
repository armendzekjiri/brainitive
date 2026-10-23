import { FormEvent, useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Columns3, GitBranch, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { LeadTemplateSummary, TYPE_ICONS } from '@/lib/leadForms';
import { LeadOptionIcon } from './LeadFormRenderer';

interface Props {
    workspaceSlug: string;
    projectSlug: string;
    onBack?: () => void;
    onCreated?: () => void;
}

/** Pick a lead form template (or blank), name it, create — opens the builder. */
export default function LeadFormTemplatePicker({ workspaceSlug, projectSlug, onBack, onCreated }: Props) {
    const [catalog, setCatalog] = useState<{ categories: Record<string, string>; templates: LeadTemplateSummary[] } | null>(null);
    const [category, setCategory] = useState('all');
    const [selected, setSelected] = useState('demo_request');
    const [name, setName] = useState('');
    const [nameTouched, setNameTouched] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        window.axios
            .get(route('lead-form-templates.index', workspaceSlug))
            .then(({ data }) => setCatalog(data))
            .catch(() => setError('Could not load the templates.'));
    }, [workspaceSlug]);

    const template = catalog?.templates.find((t) => t.key === selected);
    const visible = useMemo(() => (catalog?.templates ?? []).filter((t) => category === 'all' || t.category === category || t.key === 'blank'), [catalog, category]);

    useEffect(() => {
        if (!nameTouched && template) setName(template.key === 'blank' ? '' : template.name);
    }, [template, nameTouched]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post(route('lead-forms.store', [workspaceSlug, projectSlug]), { name, template: selected }, {
            onSuccess: () => onCreated?.(),
            onError: (errors) => setError(errors.name ?? errors.template ?? 'Could not create the form.'),
            onFinish: () => setProcessing(false),
        });
    };

    if (!catalog) {
        return <div className="flex justify-center py-16">{error ? <p className="text-sm text-destructive">{error}</p> : <Loader2 className="size-6 animate-spin text-muted-foreground" />}</div>;
    }

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-col gap-4">
            <div className="flex flex-wrap gap-1.5">
                {[['all', 'All'], ...Object.entries(catalog.categories)].map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setCategory(key)}
                        className={cn('rounded-full border px-3 py-1 text-xs font-medium transition', category === key ? 'border-primary bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted')}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="grid min-h-0 gap-4 md:grid-cols-[1fr_280px]">
                <div className="grid max-h-[50vh] grid-cols-1 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                    {visible.map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => setSelected(t.key)}
                            className={cn('flex items-start gap-3 rounded-lg border p-3 text-left transition hover:border-primary/50', selected === t.key && 'border-primary bg-primary/5 ring-1 ring-primary/30')}
                        >
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg text-white" style={{ background: t.primary }}>
                                <LeadOptionIcon icon={t.icon} className="size-5" />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">{t.name}</span>
                                <span className="mt-0.5 line-clamp-2 block text-xs text-muted-foreground">{t.description}</span>
                            </span>
                        </button>
                    ))}
                </div>

                {template && (
                    <div className="max-h-[50vh] overflow-y-auto rounded-lg border bg-muted/30 p-3">
                        <p className="text-sm font-semibold">{template.name}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {template.steps.length} {template.steps.length === 1 ? 'step' : 'steps'} · {template.steps.reduce((n, s) => n + s.fields.length, 0)} questions
                        </p>
                        <ol className="mt-3 space-y-2.5">
                            {template.steps.map((s, i) => (
                                <li key={i}>
                                    <p className="flex items-center gap-1.5 text-xs font-medium">
                                        <span className="flex size-4 items-center justify-center rounded bg-background text-[10px] text-muted-foreground">{i + 1}</span>
                                        {s.title}
                                        {s.conditional && <GitBranch className="size-3 text-primary" aria-label="Shown only for some answers" />}
                                    </p>
                                    <ul className="ml-5 mt-1 space-y-0.5">
                                        {s.fields.map((f, j) => {
                                            const Icon = TYPE_ICONS[f.type];
                                            return (
                                                <li key={j} className="flex items-center gap-1.5 truncate text-[11px] text-muted-foreground">
                                                    <Icon className="size-3 shrink-0" />
                                                    {f.label}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </li>
                            ))}
                        </ol>
                        <p className="mt-3 flex items-center gap-1.5 text-xs font-medium">
                            <Columns3 className="size-3.5" />
                            Leads board
                        </p>
                        <div className="mt-1.5 flex flex-wrap gap-1">
                            {template.columns.map((c) => (
                                <span key={c} className="rounded bg-background px-1.5 py-0.5 text-[11px] text-muted-foreground">
                                    {c}
                                </span>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <div className="space-y-2">
                <Label htmlFor="lead-form-name">Form name</Label>
                <Input
                    id="lead-form-name"
                    value={name}
                    onChange={(e) => {
                        setName(e.target.value);
                        setNameTouched(true);
                    }}
                    placeholder="e.g., Website demo requests"
                    required
                    maxLength={255}
                />
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>

            <div className="flex justify-end gap-3">
                {onBack && (
                    <Button type="button" variant="outline" onClick={onBack}>
                        Back
                    </Button>
                )}
                <Button type="submit" disabled={processing || !name.trim()}>
                    {processing ? 'Creating…' : 'Create lead form'}
                </Button>
            </div>
        </form>
    );
}
