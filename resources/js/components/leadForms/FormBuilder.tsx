import { ChangeEvent, useEffect, useMemo, useRef, useState } from 'react';
import { ArrowDown, ArrowUp, Copy, GitBranch, ImagePlus, Loader2, Monitor, Plus, Smartphone, Trash2, Upload, X, type LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    CHOICE_TYPES, FIELD_TYPES, TYPE_ICONS, LeadField, LeadFieldType, LeadFormDefinition, LeadFormPayload, LeadFormSettings, LeadFormTheme, LeadStep,
    describeCondition, fieldsBefore, newField, newId, newStep,
} from '@/lib/leadForms';
import LeadFormRenderer, { DEFAULT_CONSENT_TEXT } from './LeadFormRenderer';
import { ConditionEditor, Field, OptionsEditor, Section, Segmented, Toggle } from './BuilderControls';


const PRESETS: { name: string; theme: Partial<LeadFormTheme> }[] = [
    { name: 'Indigo', theme: { primary: '#4f46e5', background: '#f1f5f9', surface: '#ffffff', text: '#0f172a' } },
    { name: 'Ocean', theme: { primary: '#0369a1', background: '#e0f2fe', surface: '#ffffff', text: '#0c4a6e' } },
    { name: 'Forest', theme: { primary: '#16a34a', background: '#f0fdf4', surface: '#ffffff', text: '#14532d' } },
    { name: 'Sunset', theme: { primary: '#ea580c', background: '#fff7ed', surface: '#ffffff', text: '#431407' } },
    { name: 'Rose', theme: { primary: '#db2777', background: '#fdf2f8', surface: '#ffffff', text: '#500724' } },
    { name: 'Midnight', theme: { primary: '#a78bfa', background: '#0b1020', surface: '#151b2e', text: '#e2e8f0' } },
    { name: 'Mono', theme: { primary: '#111827', background: '#f4f4f5', surface: '#ffffff', text: '#111827' } },
];

type Selection = { step: number; field: number | null };
type SaveState = 'idle' | 'saving' | 'saved' | 'error';
type Part = 'definition' | 'theme' | 'settings';

/** Keys the server derived (from the label) for questions that have none yet. */
function withServerKeys(local: LeadFormDefinition, server: LeadFormDefinition): LeadFormDefinition {
    const keys = new Map(server.steps.flatMap((s) => s.fields).map((f) => [f.id, f.key]));
    if (!local.steps.some((s) => s.fields.some((f) => !f.key && keys.get(f.id)))) return local;
    return { ...local, steps: local.steps.map((s) => ({ ...s, fields: s.fields.map((f) => (!f.key && keys.get(f.id) ? { ...f, key: keys.get(f.id)! } : f)) })) };
}

type Texts = Pick<LeadFormSettings, 'intro_title' | 'intro_text' | 'next_label' | 'back_label' | 'submit_label' | 'after_submit' | 'thank_you_title' | 'thank_you_message' | 'redirect_url' | 'show_branding' | 'consent' | 'consent_text'>;
const TEXT_KEYS: (keyof Texts)[] = ['intro_title', 'intro_text', 'next_label', 'back_label', 'submit_label', 'after_submit', 'thank_you_title', 'thank_you_message', 'redirect_url', 'show_branding', 'consent', 'consent_text'];

interface Props {
    form: LeadFormPayload;
    routeParams: [string, string, string];
    canUpdate: boolean;
    onSaved: (form: LeadFormPayload) => void;
}

export default function FormBuilder({ form, routeParams, canUpdate, onSaved }: Props) {
    const [definition, setDefinition] = useState<LeadFormDefinition>(form.definition);
    const [theme, setTheme] = useState<LeadFormTheme>(form.theme);
    const [texts, setTexts] = useState<Texts>(() => pickTexts(form.settings));
    const [selection, setSelection] = useState<Selection>({ step: 0, field: definition.steps[0]?.fields.length ? 0 : null });
    const [panel, setPanel] = useState<'question' | 'design' | 'texts'>('question');
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
    const [saveState, setSaveState] = useState<SaveState>('idle');
    const [saveError, setSaveError] = useState<string | null>(null);
    const dirty = useRef<Set<Part>>(new Set());
    const failed = useRef<Map<Part, string>>(new Map());
    const inFlight = useRef(false);
    const timer = useRef<number>();

    const step: LeadStep | undefined = definition.steps[selection.step];
    const field: LeadField | undefined = selection.field !== null ? step?.fields[selection.field] : undefined;

    // ── autosave: one request at a time, each part on its own (a bad setting never blocks question edits) ──
    const latest = useRef({ definition, theme, texts, routeParams, onSaved });
    latest.current = { definition, theme, texts, routeParams, onSaved };

    const showStatus = () => {
        const error = failed.current.values().next().value as string | undefined;
        setSaveError(error ?? null);
        setSaveState(error ? 'error' : dirty.current.size || inFlight.current ? 'saving' : 'saved');
    };

    // Only reads refs, so it is safe to call after unmount (leaving the Builder tab)
    const flush = async () => {
        window.clearTimeout(timer.current);
        if (inFlight.current) return; // the running loop picks up new edits
        inFlight.current = true;
        try {
            while (dirty.current.size) {
                const part = dirty.current.values().next().value as Part;
                dirty.current.delete(part);
                const now = latest.current;
                if (part === 'settings' && now.texts.after_submit === 'redirect' && !now.texts.redirect_url?.trim()) {
                    failed.current.set(part, 'Enter the page to send people to after they submit (Texts).');
                    continue;
                }
                const value = part === 'definition' ? now.definition : part === 'theme' ? now.theme : now.texts;
                try {
                    const { data } = await window.axios.put(route('lead-forms.update', now.routeParams), { [part]: value });
                    failed.current.delete(part);
                    latest.current.onSaved(data.form);
                    // Never replace what the user is typing with the trimmed server copy: only take
                    // the keys the server derived for questions that had none
                    if (part === 'definition') setDefinition((d) => withServerKeys(d, data.form.definition));
                } catch (e: unknown) {
                    const errors = (e as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors;
                    failed.current.set(part, errors ? (Object.values(errors)[0]?.[0] ?? 'Could not save.') : 'Could not save — check your connection.');
                }
            }
        } finally {
            inFlight.current = false;
        }
        showStatus();
    };

    const schedule = (part: Part) => {
        if (!canUpdate) return;
        dirty.current.add(part);
        setSaveState('saving');
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => void flush(), 700);
    };

    // Leaving the Builder tab unmounts it: save what is pending instead of dropping it
    useEffect(
        () => () => {
            if (dirty.current.size) void flush();
        },
        [], // eslint-disable-line react-hooks/exhaustive-deps
    );
    useEffect(() => {
        const warn = (e: BeforeUnloadEvent) => {
            if (dirty.current.size || failed.current.size || inFlight.current) e.preventDefault();
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, []);

    // ── definition edits ──
    const updateDefinition = (fn: (d: LeadFormDefinition) => LeadFormDefinition) => {
        setDefinition((d) => fn(structuredClone(d)));
        schedule('definition');
    };
    const updateField = (patch: Partial<LeadField>) =>
        updateDefinition((d) => {
            const f = d.steps[selection.step].fields[selection.field!];
            Object.assign(f, patch);
            return d;
        });
    const updateStep = (patch: Partial<LeadStep>) =>
        updateDefinition((d) => {
            Object.assign(d.steps[selection.step], patch);
            return d;
        });

    const addField = (type: LeadFieldType, stepIndex = selection.step) => {
        const f = newField(type);
        if (type === 'email' && !definition.steps.some((s) => s.fields.some((x) => x.maps_to === 'email'))) f.maps_to = 'email';
        const at = stepIndex === selection.step && selection.field !== null ? selection.field + 1 : definition.steps[stepIndex].fields.length;
        updateDefinition((d) => {
            d.steps[stepIndex].fields.splice(at, 0, f);
            return d;
        });
        setSelection({ step: stepIndex, field: at });
        setPanel('question');
    };

    const addStep = () => {
        const at = definition.steps.length;
        updateDefinition((d) => {
            d.steps.push(newStep(at + 1));
            return d;
        });
        setSelection({ step: at, field: null });
        setPanel('question');
    };

    const moveField = (by: number) => {
        if (selection.field === null) return;
        const s = selection.step;
        const f = selection.field;
        const fields = definition.steps[s].fields;
        // across steps: to the end of the previous / start of the next step
        if (f + by < 0 && s > 0) {
            updateDefinition((d) => {
                const [x] = d.steps[s].fields.splice(f, 1);
                d.steps[s - 1].fields.push(x);
                return d;
            });
            setSelection({ step: s - 1, field: definition.steps[s - 1].fields.length });
            return;
        }
        if (f + by >= fields.length && s < definition.steps.length - 1) {
            updateDefinition((d) => {
                const [x] = d.steps[s].fields.splice(f, 1);
                d.steps[s + 1].fields.unshift(x);
                return d;
            });
            setSelection({ step: s + 1, field: 0 });
            return;
        }
        if (f + by < 0 || f + by >= fields.length) return;
        updateDefinition((d) => {
            const [x] = d.steps[s].fields.splice(f, 1);
            d.steps[s].fields.splice(f + by, 0, x);
            return d;
        });
        setSelection({ step: s, field: f + by });
    };

    const duplicateField = () => {
        if (!field) return;
        const copy: LeadField = { ...structuredClone(field), id: newId('f'), key: '', label: `${field.label} (copy)`, maps_to: null, options: field.options.map((o) => ({ ...o, id: newId('o') })) };
        updateDefinition((d) => {
            d.steps[selection.step].fields.splice(selection.field! + 1, 0, copy);
            return d;
        });
        setSelection({ step: selection.step, field: selection.field! + 1 });
    };

    const removeField = () => {
        if (selection.field === null) return;
        const id = field?.id;
        updateDefinition((d) => {
            d.steps[selection.step].fields.splice(selection.field!, 1);
            // conditions on the removed question go too
            for (const s of d.steps) {
                if (s.visible_if?.field === id) s.visible_if = null;
                for (const f of s.fields) if (f.visible_if?.field === id) f.visible_if = null;
            }
            return d;
        });
        const remaining = definition.steps[selection.step].fields.length - 1;
        setSelection({ step: selection.step, field: remaining > 0 ? Math.min(selection.field, remaining - 1) : null });
    };

    const moveStep = (by: number) => {
        const s = selection.step;
        if (s + by < 0 || s + by >= definition.steps.length) return;
        updateDefinition((d) => {
            const [x] = d.steps.splice(s, 1);
            d.steps.splice(s + by, 0, x);
            return d;
        });
        setSelection({ step: s + by, field: null });
    };

    const removeStep = () => {
        if (definition.steps.length <= 1) return;
        const ids = new Set(step?.fields.map((f) => f.id));
        updateDefinition((d) => {
            d.steps.splice(selection.step, 1);
            for (const s of d.steps) {
                if (s.visible_if && ids.has(s.visible_if.field)) s.visible_if = null;
                for (const f of s.fields) if (f.visible_if && ids.has(f.visible_if.field)) f.visible_if = null;
            }
            return d;
        });
        setSelection({ step: Math.max(0, selection.step - 1), field: null });
    };

    // Conditions that pointed at a removed answer would never match again: drop them
    const changeOptions = (options: LeadField['options']) => {
        if (!field) return;
        const ids = new Set(options.map((o) => o.id));
        const stale = (c?: LeadField['visible_if']) => !!c && c.field === field.id && CHOICE_TYPES.includes(field.type) && c.value !== null && ['equals', 'not_equals'].includes(c.op) && !ids.has(c.value);
        updateDefinition((d) => {
            d.steps[selection.step].fields[selection.field!].options = options;
            for (const s of d.steps) {
                if (stale(s.visible_if)) s.visible_if = null;
                for (const f of s.fields) if (stale(f.visible_if)) f.visible_if = null;
            }
            return d;
        });
    };

    const changeType = (type: LeadFieldType) => {
        if (!field) return;
        const fresh = newField(type);
        updateField({
            type,
            options: CHOICE_TYPES.includes(type) ? (field.options.length ? field.options : fresh.options) : [],
            layout: fresh.layout,
            required: type === 'hidden' ? false : field.required,
            maps_to: type === 'email' ? field.maps_to ?? 'email' : field.maps_to,
        });
    };

    const setThemeKey = <K extends keyof LeadFormTheme>(key: K, value: LeadFormTheme[K]) => {
        setTheme((t) => ({ ...t, [key]: value }));
        schedule('theme');
    };
    const setText = <K extends keyof Texts>(key: K, value: Texts[K]) => {
        setTexts((t) => ({ ...t, [key]: value }));
        schedule('settings');
    };

    const previewSettings = useMemo(() => ({ ...form.settings, ...texts }), [form.settings, texts]);
    const mapped = new Map(definition.steps.flatMap((s) => s.fields).filter((f) => f.maps_to).map((f) => [f.maps_to!, f.id]));

    return (
        <div className="flex h-full min-h-0">
            {/* ── outline ── */}
            <aside className="hidden w-72 shrink-0 flex-col border-r bg-background md:flex">
                <div className="flex-1 space-y-3 overflow-y-auto p-3">
                    {definition.steps.map((s, si) => (
                        <div key={s.id} className={cn('rounded-lg border bg-card transition', selection.step === si && selection.field === null && 'border-primary ring-1 ring-primary/30')}>
                            <button type="button" onClick={() => setSelection({ step: si, field: null })} className="flex w-full items-center gap-2 px-3 py-2 text-left">
                                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold text-muted-foreground">{si + 1}</span>
                                <span className="min-w-0 flex-1 truncate text-sm font-medium">{s.title}</span>
                                {s.visible_if && <GitBranch className="size-3.5 shrink-0 text-primary" aria-label="Conditional step" />}
                            </button>
                            <div className="space-y-0.5 px-1.5 pb-1.5">
                                {s.fields.map((f, fi) => {
                                    const Icon = TYPE_ICONS[f.type];
                                    const active = selection.step === si && selection.field === fi;
                                    return (
                                        <button
                                            key={f.id}
                                            type="button"
                                            onClick={() => {
                                                setSelection({ step: si, field: fi });
                                                setPanel('question');
                                            }}
                                            className={cn('flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs transition', active ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground')}
                                        >
                                            <Icon className="size-3.5 shrink-0" />
                                            <span className="min-w-0 flex-1 truncate">{f.label}</span>
                                            {f.visible_if && <GitBranch className="size-3 shrink-0 text-primary" />}
                                            {f.required && <span className="text-primary">*</span>}
                                        </button>
                                    );
                                })}
                                {canUpdate && <AddQuestion onAdd={(t) => addField(t, si)} compact />}
                            </div>
                        </div>
                    ))}
                    {canUpdate && (
                        <Button variant="outline" size="sm" className="w-full gap-1.5 border-dashed" onClick={addStep} disabled={definition.steps.length >= 20}>
                            <Plus className="size-4" />
                            Add step
                        </Button>
                    )}
                </div>
                <div className="flex h-9 items-center gap-1.5 border-t px-3 text-xs text-muted-foreground">
                    {saveState === 'saving' && (
                        <>
                            <Loader2 className="size-3 animate-spin" /> Saving…
                        </>
                    )}
                    {saveState === 'saved' && 'All changes saved'}
                    {saveState === 'error' && <span className="truncate text-destructive" title={saveError ?? ''}>{saveError}</span>}
                    {saveState === 'idle' && (canUpdate ? 'Changes save automatically' : 'View only')}
                </div>
            </aside>

            {/* ── preview ── */}
            <section className="flex min-w-0 flex-1 flex-col bg-muted/40">
                <div className="flex items-center justify-center gap-1 border-b bg-background/80 py-1.5">
                    <Segmented
                        value={device}
                        onChange={setDevice}
                        options={[
                            { value: 'desktop', label: 'Desktop' },
                            { value: 'mobile', label: 'Mobile' },
                        ]}
                    />
                    <span className="ml-2 hidden text-xs text-muted-foreground sm:inline">
                        {device === 'desktop' ? <Monitor className="inline size-3.5" /> : <Smartphone className="inline size-3.5" />} Live preview — try it out
                    </span>
                </div>
                <p className="border-b bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300 md:hidden">
                    Editing questions and design needs a larger screen — here you can try the form.
                </p>
                <div className="flex-1 overflow-auto p-4">
                    <div className={cn('mx-auto overflow-hidden rounded-xl border bg-background shadow-sm transition-all', device === 'mobile' ? 'w-[390px]' : 'w-full max-w-5xl')}>
                        <LeadFormRenderer name={form.name} definition={definition} theme={theme} settings={previewSettings} previewStep={selection.step} />
                    </div>
                </div>
            </section>

            {/* ── inspector ── */}
            <aside className="hidden w-80 shrink-0 flex-col border-l bg-background md:flex">
                <div className="border-b p-2">
                    <Segmented
                        value={panel}
                        onChange={setPanel}
                        options={[
                            { value: 'question', label: field ? 'Question' : 'Step' },
                            { value: 'design', label: 'Design' },
                            { value: 'texts', label: 'Texts' },
                        ]}
                    />
                </div>
                <fieldset disabled={!canUpdate} className="min-h-0 min-w-0 flex-1 overflow-y-auto">
                    {panel === 'question' && field && (
                        <>
                            <Section
                                title="Question"
                                action={
                                    <div className="flex gap-0.5">
                                        <IconButton label="Move up" icon={ArrowUp} onClick={() => moveField(-1)} />
                                        <IconButton label="Move down" icon={ArrowDown} onClick={() => moveField(1)} />
                                        <IconButton label="Duplicate" icon={Copy} onClick={duplicateField} />
                                        <IconButton label="Delete question" icon={Trash2} onClick={removeField} danger />
                                    </div>
                                }
                            >
                                <Field label="Type">
                                    <Select value={field.type} onValueChange={(v) => changeType(v as LeadFieldType)}>
                                        <SelectTrigger className="h-8">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {FIELD_TYPES.map((t) => {
                                                const Icon = TYPE_ICONS[t.type];
                                                return (
                                                    <SelectItem key={t.type} value={t.type}>
                                                        <span className="flex items-center gap-2">
                                                            <Icon className="size-3.5" />
                                                            {t.label}
                                                        </span>
                                                    </SelectItem>
                                                );
                                            })}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field label="Question">
                                    <Textarea rows={2} value={field.label} onChange={(e) => updateField({ label: e.target.value })} maxLength={200} className="min-h-0 resize-none text-sm" />
                                </Field>
                                {field.type !== 'hidden' && (
                                    <Field label="Help text">
                                        <Input value={field.help ?? ''} onChange={(e) => updateField({ help: e.target.value || null })} placeholder="Optional hint below the question" maxLength={300} className="h-8" />
                                    </Field>
                                )}
                                {['short_text', 'long_text', 'email', 'phone', 'number', 'select'].includes(field.type) && (
                                    <Field label="Placeholder">
                                        <Input value={field.placeholder ?? ''} onChange={(e) => updateField({ placeholder: e.target.value || null })} maxLength={120} className="h-8" />
                                    </Field>
                                )}
                                {field.type !== 'hidden' && <Toggle label="Required" checked={field.required} onChange={(required) => updateField({ required })} />}
                            </Section>

                            {CHOICE_TYPES.includes(field.type) && (
                                <Section title="Answers">
                                    {field.type !== 'select' && (
                                        <Segmented
                                            value={field.layout ?? 'cards'}
                                            onChange={(layout) => updateField({ layout })}
                                            options={[
                                                { value: 'cards', label: 'Cards' },
                                                { value: 'list', label: 'List' },
                                            ]}
                                        />
                                    )}
                                    <OptionsEditor field={field} onChange={changeOptions} />
                                </Section>
                            )}

                            {(field.type === 'number' || field.type === 'multi_choice') && (
                                <Section title={field.type === 'number' ? 'Limits' : 'How many answers'}>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Field label="Min">
                                            <Input type="number" value={field.min ?? ''} onChange={(e) => updateField({ min: e.target.value === '' ? null : Number(e.target.value) })} className="h-8" />
                                        </Field>
                                        <Field label="Max">
                                            <Input type="number" value={field.max ?? ''} onChange={(e) => updateField({ max: e.target.value === '' ? null : Number(e.target.value) })} className="h-8" />
                                        </Field>
                                    </div>
                                </Section>
                            )}

                            <Section title="Logic">
                                <ConditionEditor subject="question" condition={field.visible_if} candidates={fieldsBefore(definition, selection.step, selection.field!)} onChange={(visible_if) => updateField({ visible_if })} />
                            </Section>

                            <Section title="Lead card">
                                {['short_text', 'email', 'phone'].includes(field.type) && (
                                    <Field label="Save the answer as the lead's" hint="Shown on the card and used for the confirmation email.">
                                        <Select value={field.maps_to ?? 'none'} onValueChange={(v) => updateField({ maps_to: v === 'none' ? null : (v as LeadField['maps_to']) })}>
                                            <SelectTrigger className="h-8">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">— nothing —</SelectItem>
                                                {(['name', 'email', 'phone', 'company'] as const).map((m) => (
                                                    <SelectItem key={m} value={m} disabled={mapped.has(m) && mapped.get(m) !== field.id}>
                                                        {m[0].toUpperCase() + m.slice(1)}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </Field>
                                )}
                                <Field label="Key" hint={field.type === 'hidden' ? `Fill it from the link: ?${field.key || 'key'}=value` : 'Name of the answer in webhooks and the inbound API.'}>
                                    <Input value={field.key} onChange={(e) => updateField({ key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })} placeholder="from the question" maxLength={60} className="h-8 font-mono text-xs" />
                                </Field>
                            </Section>
                        </>
                    )}

                    {panel === 'question' && !field && step && (
                        <>
                            <Section
                                title={`Step ${selection.step + 1}`}
                                action={
                                    <div className="flex gap-0.5">
                                        <IconButton label="Move step up" icon={ArrowUp} onClick={() => moveStep(-1)} />
                                        <IconButton label="Move step down" icon={ArrowDown} onClick={() => moveStep(1)} />
                                        {definition.steps.length > 1 && <IconButton label="Delete step" icon={Trash2} onClick={removeStep} danger />}
                                    </div>
                                }
                            >
                                <Field label="Title">
                                    <Input value={step.title} onChange={(e) => updateStep({ title: e.target.value })} maxLength={120} className="h-8" />
                                </Field>
                                <Field label="Description">
                                    <Textarea rows={2} value={step.description ?? ''} onChange={(e) => updateStep({ description: e.target.value || null })} maxLength={500} className="min-h-0 resize-none text-sm" />
                                </Field>
                            </Section>
                            <Section title="Logic">
                                <ConditionEditor subject="step" condition={step.visible_if} candidates={fieldsBefore(definition, selection.step)} onChange={(visible_if) => updateStep({ visible_if })} />
                                {step.visible_if && <p className="text-[11px] text-muted-foreground">Skipped unless {describeCondition(step.visible_if, definition)}.</p>}
                            </Section>
                            <Section title="Questions">
                                {canUpdate && <AddQuestion onAdd={(t) => addField(t)} />}
                            </Section>
                        </>
                    )}

                    {panel === 'design' && <DesignPanel theme={theme} setThemeKey={setThemeKey} routeParams={routeParams} apply={(t) => { setTheme((prev) => ({ ...prev, ...t })); schedule('theme'); }} />}

                    {panel === 'texts' && (
                        <>
                            <Section title="Start">
                                <Field label="Headline">
                                    <Input value={texts.intro_title ?? ''} onChange={(e) => setText('intro_title', e.target.value || null)} maxLength={160} className="h-8" />
                                </Field>
                                <Field label="Intro">
                                    <Textarea rows={3} value={texts.intro_text ?? ''} onChange={(e) => setText('intro_text', e.target.value || null)} maxLength={1000} className="text-sm" />
                                </Field>
                            </Section>
                            <Section title="Buttons">
                                <div className="grid grid-cols-3 gap-2">
                                    <Field label="Next">
                                        <Input value={texts.next_label} onChange={(e) => setText('next_label', e.target.value)} maxLength={40} className="h-8" />
                                    </Field>
                                    <Field label="Back">
                                        <Input value={texts.back_label} onChange={(e) => setText('back_label', e.target.value)} maxLength={40} className="h-8" />
                                    </Field>
                                    <Field label="Submit">
                                        <Input value={texts.submit_label} onChange={(e) => setText('submit_label', e.target.value)} maxLength={40} className="h-8" />
                                    </Field>
                                </div>
                            </Section>
                            <Section title="After submit">
                                <Segmented
                                    value={texts.after_submit}
                                    onChange={(v) => setText('after_submit', v)}
                                    options={[
                                        { value: 'message', label: 'Thank-you screen' },
                                        { value: 'redirect', label: 'Go to a page' },
                                    ]}
                                />
                                {texts.after_submit === 'redirect' ? (
                                    <Field label="Page URL" hint="For example your own thank-you page or a booking link.">
                                        <Input value={texts.redirect_url ?? ''} onChange={(e) => setText('redirect_url', e.target.value || null)} placeholder="https://" maxLength={2048} className="h-8" />
                                    </Field>
                                ) : (
                                    <>
                                        <Field label="Title">
                                            <Input value={texts.thank_you_title ?? ''} onChange={(e) => setText('thank_you_title', e.target.value || null)} maxLength={160} className="h-8" />
                                        </Field>
                                        <Field label="Message">
                                            <Textarea rows={3} value={texts.thank_you_message ?? ''} onChange={(e) => setText('thank_you_message', e.target.value || null)} maxLength={2000} className="text-sm" />
                                        </Field>
                                    </>
                                )}
                            </Section>
                            <Section title="Consent checkbox">
                                <Segmented
                                    value={texts.consent ?? 'off'}
                                    onChange={(v) => setText('consent', v)}
                                    options={[
                                        { value: 'off', label: 'None' },
                                        { value: 'optional', label: 'Optional' },
                                        { value: 'required', label: 'Required' },
                                    ]}
                                />
                                {(texts.consent ?? 'off') !== 'off' && (
                                    <Field
                                        label="Checkbox text"
                                        hint="Shown above the submit button and saved with each lead. {organization} = your workspace's legal name. Add your privacy policy link in Workspace settings → Legal."
                                    >
                                        <Textarea
                                            rows={3}
                                            value={texts.consent_text ?? ''}
                                            onChange={(e) => setText('consent_text', e.target.value || null)}
                                            placeholder={DEFAULT_CONSENT_TEXT}
                                            maxLength={1000}
                                            className="text-sm"
                                            data-testid="lead-consent-text"
                                        />
                                    </Field>
                                )}
                            </Section>
                            <Section title="Branding">
                                <Toggle label="Show “Powered by Brainitive”" checked={texts.show_branding} onChange={(v) => setText('show_branding', v)} />
                            </Section>
                        </>
                    )}
                </fieldset>
            </aside>
        </div>
    );
}

function pickTexts(settings: LeadFormSettings): Texts {
    return Object.fromEntries(TEXT_KEYS.map((k) => [k, settings[k]])) as Texts;
}

function IconButton({ label, icon: Icon, onClick, danger }: { label: string; icon: LucideIcon; onClick: () => void; danger?: boolean }) {
    return (
        <button type="button" onClick={onClick} title={label} aria-label={label} className={cn('rounded p-1 text-muted-foreground hover:bg-muted', danger ? 'hover:text-destructive' : 'hover:text-foreground')}>
            <Icon className="size-3.5" />
        </button>
    );
}

function AddQuestion({ onAdd, compact }: { onAdd: (type: LeadFieldType) => void; compact?: boolean }) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                {compact ? (
                    <button type="button" className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                        <Plus className="size-3.5" />
                        Add question
                    </button>
                ) : (
                    <Button variant="outline" size="sm" className="w-full gap-1.5">
                        <Plus className="size-4" />
                        Add question
                    </Button>
                )}
            </PopoverTrigger>
            <PopoverContent className="w-[420px] p-2" align="start" side="right">
                <div className="grid grid-cols-2 gap-1">
                    {FIELD_TYPES.map((t) => {
                        const Icon = TYPE_ICONS[t.type];
                        return (
                            <button
                                key={t.type}
                                type="button"
                                onClick={() => {
                                    onAdd(t.type);
                                    setOpen(false);
                                }}
                                className="flex items-start gap-2.5 rounded-md p-2 text-left hover:bg-muted"
                            >
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                    <Icon className="size-4" />
                                </span>
                                <span>
                                    <span className="block text-sm font-medium">{t.label}</span>
                                    <span className="block text-[11px] leading-tight text-muted-foreground">{t.description}</span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function DesignPanel({
    theme,
    setThemeKey,
    apply,
    routeParams,
}: {
    theme: LeadFormTheme;
    setThemeKey: <K extends keyof LeadFormTheme>(key: K, value: LeadFormTheme[K]) => void;
    apply: (t: Partial<LeadFormTheme>) => void;
    routeParams: [string, string, string];
}) {
    return (
        <>
            <Section title="Colours">
                <div className="flex flex-wrap gap-1.5">
                    {PRESETS.map((p) => (
                        <button
                            key={p.name}
                            type="button"
                            title={p.name}
                            onClick={() => apply(p.theme)}
                            className={cn('flex h-7 items-center gap-1 rounded-full border px-1.5 text-[11px]', theme.primary === p.theme.primary && 'ring-2 ring-primary/40')}
                            style={{ background: p.theme.background, color: p.theme.text }}
                        >
                            <span className="size-3.5 rounded-full" style={{ background: p.theme.primary }} />
                            {p.name}
                        </button>
                    ))}
                </div>
                <div className="grid grid-cols-2 gap-2">
                    {(
                        [
                            ['primary', 'Brand'],
                            ['background', 'Background'],
                            ['surface', 'Form'],
                            ['text', 'Text'],
                        ] as const
                    ).map(([key, label]) => (
                        <label key={key} className="flex items-center gap-2 rounded-md border px-2 py-1.5 text-xs">
                            <input type="color" value={theme[key]} onChange={(e) => setThemeKey(key, e.target.value)} className="size-6 cursor-pointer rounded border-0 bg-transparent p-0" />
                            <span className="flex-1">{label}</span>
                            <span className="font-mono text-[10px] text-muted-foreground">{theme[key]}</span>
                        </label>
                    ))}
                </div>
            </Section>
            <Section title="Style">
                <Field label="Font">
                    <Select value={theme.font} onValueChange={(v) => setThemeKey('font', v as LeadFormTheme['font'])}>
                        <SelectTrigger className="h-8">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="inter">Modern (Inter)</SelectItem>
                            <SelectItem value="rounded">Friendly (Hanken Grotesk)</SelectItem>
                            <SelectItem value="serif">Classic (Serif)</SelectItem>
                            <SelectItem value="system">System</SelectItem>
                            <SelectItem value="mono">Technical (Mono)</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                <Field label="Corners">
                    <Segmented value={theme.radius} onChange={(v) => setThemeKey('radius', v)} options={[{ value: 'none', label: 'Square' }, { value: 'md', label: 'Soft' }, { value: 'lg', label: 'Round' }, { value: 'full', label: 'Pill' }]} />
                </Field>
                <Field label="Buttons">
                    <Segmented value={theme.button} onChange={(v) => setThemeKey('button', v)} options={[{ value: 'solid', label: 'Filled' }, { value: 'outline', label: 'Outline' }]} />
                </Field>
                <Field label="Layout">
                    <Segmented value={theme.layout} onChange={(v) => setThemeKey('layout', v)} options={[{ value: 'card', label: 'Card' }, { value: 'split', label: 'Split' }, { value: 'full', label: 'Full' }]} />
                </Field>
                <Field label="Progress">
                    <Segmented value={theme.progress} onChange={(v) => setThemeKey('progress', v)} options={[{ value: 'bar', label: 'Bar' }, { value: 'steps', label: 'Steps' }, { value: 'none', label: 'None' }]} />
                </Field>
            </Section>
            <Section title="Images">
                <ImageInput label="Logo" value={theme.logo_url} onChange={(v) => setThemeKey('logo_url', v)} routeParams={routeParams} />
                <ImageInput label={theme.layout === 'split' ? 'Side image' : 'Cover image'} value={theme.cover_url} onChange={(v) => setThemeKey('cover_url', v)} routeParams={routeParams} />
            </Section>
        </>
    );
}

function ImageInput({ label, value, onChange, routeParams }: { label: string; value: string | null; onChange: (v: string | null) => void; routeParams: [string, string, string] }) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);

    const upload = async (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        setUploading(true);
        setError(null);
        try {
            const body = new FormData();
            body.append('file', file);
            const { data } = await window.axios.post(route('lead-forms.assets', routeParams), body);
            onChange(data.url);
        } catch (err: unknown) {
            const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors;
            setError(errors?.file?.[0] ?? 'Upload failed.');
        } finally {
            setUploading(false);
        }
    };

    return (
        <Field label={label}>
            {value ? (
                <div className="flex items-center gap-2 rounded-md border p-1.5">
                    <img src={value} alt="" className="size-10 rounded object-cover" />
                    <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{value.split('/').pop()}</span>
                    <button type="button" onClick={() => onChange(null)} className="rounded p-1 text-muted-foreground hover:bg-muted" aria-label={`Remove ${label}`}>
                        <X className="size-4" />
                    </button>
                </div>
            ) : (
                <Button type="button" variant="outline" size="sm" className="w-full gap-1.5" onClick={() => input.current?.click()} disabled={uploading}>
                    {uploading ? <Loader2 className="size-4 animate-spin" /> : label === 'Logo' ? <Upload className="size-4" /> : <ImagePlus className="size-4" />}
                    Upload {label.toLowerCase()}
                </Button>
            )}
            <input ref={input} type="file" accept="image/png,image/jpeg,image/gif,image/webp" className="hidden" onChange={upload} />
            {error && <p className="text-[11px] text-destructive">{error}</p>}
        </Field>
    );
}
