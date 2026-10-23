import { useEffect, useMemo, useState } from 'react';
import { cn } from '@/lib/utils';
import {
    Briefcase, Bug, Building2, Calculator, CalendarClock, ChevronRight, FilePlus, FlaskConical, Handshake,
    HeartHandshake, LifeBuoy, Lightbulb, Loader2, Mail, MailPlus, Monitor, Newspaper, Ticket, Undo2, Users,
} from 'lucide-react';

type IconType = React.ComponentType<{ className?: string }>;

const ICONS: Record<string, IconType> = {
    'life-buoy': LifeBuoy, monitor: Monitor, bug: Bug, 'undo-2': Undo2, mail: Mail, calculator: Calculator,
    'mail-plus': MailPlus, handshake: Handshake, newspaper: Newspaper, ticket: Ticket, 'calendar-clock': CalendarClock,
    'heart-handshake': HeartHandshake, briefcase: Briefcase, users: Users, 'building-2': Building2, lightbulb: Lightbulb,
    'flask-conical': FlaskConical,
};

const TONES: Record<string, string> = {
    support: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    forms: 'bg-pink-500/10 text-pink-600 dark:text-pink-400',
    events: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    people: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    product: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
};

const TYPE_LABELS: Record<string, string> = {
    text: 'Text', rich_text: 'Long text', select: 'Dropdown', radio: 'Single choice', checkboxes: 'Multiple choice',
    checkbox: 'Checkbox', number: 'Number', date: 'Date',
};

export interface HelpdeskTemplate {
    key: string;
    name: string;
    category: string;
    icon: string;
    description: string;
    tags: string[];
    columns: { name: string; color: string; is_done: boolean }[];
    fields: { label: string; type: string; options: string[] | null; is_required: boolean }[];
    form: { title: string; submit_label: string; welcome_message: string; name: string | null; name_required?: boolean; message_required?: boolean; subject: string | null; message: string | null; attachments: boolean; after_submit: 'portal' | 'message' };
}

type Catalog = { categories: { key: string; name: string }[]; templates: HelpdeskTemplate[] };

/**
 * Template choice in "New helpdesk" (BRAI-92): categories, cards and a preview of the form
 * and the board columns the template sets up. `value` is the template key ('' = blank).
 */
export default function HelpdeskTemplatePicker({ workspaceSlug, value, onChange }: {
    workspaceSlug: string;
    value: string;
    onChange: (key: string, template: HelpdeskTemplate | null) => void;
}) {
    const [catalog, setCatalog] = useState<Catalog | null>(null);
    const [failed, setFailed] = useState(false);
    const [category, setCategory] = useState('all');

    useEffect(() => {
        let cancelled = false;
        window.axios.get(route('helpdesk-templates.index', workspaceSlug))
            .then(({ data }: { data: Catalog }) => { if (!cancelled) setCatalog(data); })
            .catch(() => { if (!cancelled) setFailed(true); });
        return () => { cancelled = true; };
    }, [workspaceSlug]);

    const visible = useMemo(() => (catalog?.templates ?? []).filter((t) => category === 'all' || t.category === category), [catalog, category]);
    const selected = catalog?.templates.find((t) => t.key === value) ?? null;

    if (failed) return null; // a blank helpdesk can still be created
    if (!catalog) {
        return <div role="status" className="flex items-center gap-2 py-6 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" /> Loading templates…</div>;
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Template categories">
                {[{ key: 'all', name: 'All' }, ...catalog.categories].map((c) => (
                    <button
                        key={c.key}
                        type="button"
                        role="tab"
                        aria-selected={category === c.key}
                        onClick={() => setCategory(c.key)}
                        className={cn('rounded-full border px-2.5 py-1 text-xs transition-colors',
                            category === c.key ? 'border-primary bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-muted')}
                    >
                        {c.name}
                    </button>
                ))}
            </div>

            <div role="radiogroup" aria-label="Helpdesk template" className="grid max-h-60 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
                {category === 'all' && (
                    <button type="button" role="radio" aria-checked={value === ''} onClick={() => onChange('', null)}
                        className={cn('flex items-start gap-2.5 rounded-lg border p-2.5 text-left transition-colors', value === '' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-muted/40')}>
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"><FilePlus className="size-4" /></span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">Blank helpdesk</span>
                            <span className="block text-[11px] text-muted-foreground">Open → In Progress → Resolved, no extra fields</span>
                        </span>
                    </button>
                )}
                {visible.map((t) => {
                    const Icon = ICONS[t.icon] ?? LifeBuoy;
                    const active = value === t.key;
                    return (
                        <button key={t.key} type="button" role="radio" aria-checked={active} onClick={() => onChange(t.key, t)}
                            className={cn('flex items-start gap-2.5 rounded-lg border p-2.5 text-left transition-colors', active ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-muted/40')}>
                            <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-md', TONES[t.category] ?? TONES.support)}><Icon className="size-4" /></span>
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-medium">{t.name}</span>
                                <span className="line-clamp-2 block text-[11px] text-muted-foreground">{t.description}</span>
                            </span>
                        </button>
                    );
                })}
            </div>

            {selected && (
                <div className="grid gap-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Form preview</p>
                        <p className="text-sm font-medium">{selected.form.title}</p>
                        <ul className="space-y-1 text-xs">
                            {selected.form.name && <li>{selected.form.name}{selected.form.name_required ? ' *' : ''}</li>}
                            <li>Email *</li>
                            {selected.form.subject && <li>{selected.form.subject} *</li>}
                            {selected.form.message && <li>{selected.form.message}{selected.form.message_required ? ' *' : ''}</li>}
                            {selected.fields.map((f) => (
                                <li key={f.label} className="flex items-baseline gap-1.5">
                                    <span className="truncate">{f.label}{f.is_required ? ' *' : ''}</span>
                                    <span className="shrink-0 text-[10px] text-muted-foreground">{TYPE_LABELS[f.type] ?? f.type}{f.options ? ` · ${f.options.length}` : ''}</span>
                                </li>
                            ))}
                            {selected.form.attachments && <li className="text-muted-foreground">Attachments</li>}
                        </ul>
                        <p className="pt-1 text-[11px] text-muted-foreground">Button: “{selected.form.submit_label}” · {selected.form.after_submit === 'message' ? 'shows a thank-you message' : 'opens the request page'}</p>
                    </div>
                    <div className="space-y-1.5">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Board columns</p>
                        <div className="flex flex-wrap items-center gap-1">
                            {selected.columns.map((c, i) => (
                                <span key={c.name} className="flex items-center gap-1">
                                    {i > 0 && <ChevronRight className="size-3 text-muted-foreground" aria-hidden />}
                                    <span className="inline-flex items-center gap-1 rounded-[4px] border bg-background px-1.5 py-0.5 text-[11px]">
                                        <span className="size-1.5 rounded-full" style={{ backgroundColor: c.color }} />
                                        {c.name}
                                    </span>
                                </span>
                            ))}
                        </div>
                        <p className="pt-1 text-[11px] text-muted-foreground">New submissions land in “{selected.columns[0]?.name}”. You can change fields, texts and columns later in the helpdesk settings.</p>
                    </div>
                </div>
            )}
        </div>
    );
}
