import { ReactElement, ReactNode, cloneElement, isValidElement, useId, useState } from 'react';
import { ArrowDown, ArrowUp, GitBranch, Plus, Smile, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { CHOICE_TYPES, CONDITION_OPS, LEAD_ICONS, LeadCondition, LeadField, LeadOption, newId } from '@/lib/leadForms';
import { LeadOptionIcon } from './LeadFormRenderer';

export function Section({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
    return (
        <div className="space-y-3 border-b px-4 py-4 last:border-b-0">
            <div className="flex items-center justify-between">
                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
                {action}
            </div>
            {children}
        </div>
    );
}

/** A labelled control: the label is linked to a single input / textarea child. */
export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
    const fallbackId = useId();
    const linkable = isValidElement(children) && typeof children.type !== 'string' ? true : isValidElement(children) && ['input', 'textarea', 'select'].includes(children.type as string);
    const child = children as ReactElement<{ id?: string }>;
    const id = linkable ? (child.props.id ?? fallbackId) : undefined;
    return (
        <div className="space-y-1.5">
            <Label className="text-xs" htmlFor={id}>
                {label}
            </Label>
            {linkable ? cloneElement(child, { id }) : children}
            {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
        </div>
    );
}

export function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
    return (
        <label className="flex cursor-pointer items-center justify-between gap-3 text-sm">
            <span>{label}</span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={cn('relative h-5 w-9 shrink-0 rounded-full transition-colors', checked ? 'bg-primary' : 'bg-muted-foreground/30')}
            >
                <span className={cn('absolute top-0.5 size-4 rounded-full bg-white shadow transition-all', checked ? 'left-[18px]' : 'left-0.5')} />
            </button>
        </label>
    );
}

/** Segmented control for a few options. */
export function Segmented<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
    return (
        <div className="flex rounded-md bg-muted p-0.5">
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    onClick={() => onChange(o.value)}
                    className={cn('flex-1 rounded px-2 py-1 text-xs font-medium transition', value === o.value ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground')}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

export function IconPicker({ value, onChange }: { value?: string | null; onChange: (icon: string | null) => void }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [emoji, setEmoji] = useState('');
    const names = Object.keys(LEAD_ICONS).filter((n) => n.includes(query.toLowerCase().trim()));

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button type="button" className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground hover:bg-muted" aria-label="Choose icon">
                    {value ? <LeadOptionIcon icon={value} className="size-4 text-base" /> : <Smile className="size-4 opacity-50" />}
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-2" align="start">
                <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search icons…" className="mb-2 h-8" autoFocus />
                <div className="grid max-h-52 grid-cols-8 gap-1 overflow-y-auto">
                    {names.map((name) => (
                        <button
                            key={name}
                            type="button"
                            title={name}
                            onClick={() => {
                                onChange(name);
                                setOpen(false);
                            }}
                            className={cn('flex size-8 items-center justify-center rounded hover:bg-muted', value === name && 'bg-primary/10 text-primary')}
                        >
                            <LeadOptionIcon icon={name} className="size-4" />
                        </button>
                    ))}
                </div>
                <div className="mt-2 flex items-center gap-1.5 border-t pt-2">
                    <Input value={emoji} onChange={(e) => setEmoji(e.target.value)} placeholder="…or an emoji 🚀" className="h-8" maxLength={8} />
                    <Button
                        size="sm"
                        variant="secondary"
                        className="h-8"
                        disabled={!emoji.trim()}
                        onClick={() => {
                            onChange(emoji.trim());
                            setEmoji('');
                            setOpen(false);
                        }}
                    >
                        Use
                    </Button>
                    {value && (
                        <Button
                            size="sm"
                            variant="ghost"
                            className="h-8 px-2"
                            aria-label="No icon"
                            onClick={() => {
                                onChange(null);
                                setOpen(false);
                            }}
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

export function OptionsEditor({ field, onChange }: { field: LeadField; onChange: (options: LeadOption[]) => void }) {
    const options = field.options;
    const withIcons = field.type !== 'select';
    const set = (i: number, patch: Partial<LeadOption>) => onChange(options.map((o, j) => (j === i ? { ...o, ...patch } : o)));
    const moveOption = (i: number, by: number) => {
        const next = [...options];
        const [o] = next.splice(i, 1);
        next.splice(i + by, 0, o);
        onChange(next);
    };

    return (
        <div className="space-y-1.5">
            {options.map((option, i) => (
                <div key={option.id} className="group flex items-center gap-1.5">
                    {withIcons && <IconPicker value={option.icon} onChange={(icon) => set(i, { icon })} />}
                    <Input value={option.label} onChange={(e) => set(i, { label: e.target.value })} className="h-8" maxLength={120} />
                    <div className="flex opacity-0 transition group-focus-within:opacity-100 group-hover:opacity-100">
                        <button type="button" disabled={i === 0} onClick={() => moveOption(i, -1)} className="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" aria-label="Move up">
                            <ArrowUp className="size-3.5" />
                        </button>
                        <button type="button" disabled={i === options.length - 1} onClick={() => moveOption(i, 1)} className="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" aria-label="Move down">
                            <ArrowDown className="size-3.5" />
                        </button>
                        <button type="button" disabled={options.length <= 1} onClick={() => onChange(options.filter((_, j) => j !== i))} className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive disabled:opacity-30" aria-label="Remove option">
                            <Trash2 className="size-3.5" />
                        </button>
                    </div>
                </div>
            ))}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 gap-1.5 text-muted-foreground"
                disabled={options.length >= 50}
                onClick={() => onChange([...options, { id: newId('o'), label: `Option ${options.length + 1}`, icon: null }])}
            >
                <Plus className="size-3.5" />
                Add option
            </Button>
        </div>
    );
}

/** "Show this … only when <question> <is> <value>". */
export function ConditionEditor({ condition, candidates, onChange, subject }: { condition?: LeadCondition | null; candidates: LeadField[]; onChange: (c: LeadCondition | null) => void; subject: string }) {
    const usable = candidates.filter((f) => f.type !== 'consent');
    if (!condition) {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 w-full gap-1.5"
                disabled={usable.length === 0}
                onClick={() => {
                    const first = usable[usable.length - 1];
                    onChange({ field: first.id, op: CHOICE_TYPES.includes(first.type) ? 'equals' : 'answered', value: CHOICE_TYPES.includes(first.type) ? first.options[0]?.id ?? null : null });
                }}
            >
                <GitBranch className="size-3.5" />
                {usable.length === 0 ? `Add questions before this ${subject} to use logic` : `Only show this ${subject} when…`}
            </Button>
        );
    }

    const source = usable.find((f) => f.id === condition.field);
    const op = CONDITION_OPS.find((o) => o.op === condition.op);
    const isChoice = !!source && CHOICE_TYPES.includes(source.type);
    const ops = CONDITION_OPS.filter((o) => (isChoice ? ['equals', 'not_equals', 'answered', 'not_answered'].includes(o.op) : source?.type === 'number' || source?.type === 'rating' ? o.op !== 'includes' : !['gt', 'lt'].includes(o.op)));

    return (
        <div className="space-y-2 rounded-md border border-primary/30 bg-primary/5 p-2.5">
            <div className="flex items-center justify-between text-xs font-medium text-primary">
                <span className="flex items-center gap-1.5">
                    <GitBranch className="size-3.5" />
                    Show only when
                </span>
                <button type="button" onClick={() => onChange(null)} className="rounded p-0.5 hover:bg-primary/10" aria-label="Remove condition">
                    <X className="size-3.5" />
                </button>
            </div>
            <Select
                value={source ? condition.field : ''}
                onValueChange={(id) => {
                    const f = usable.find((x) => x.id === id)!;
                    const choice = CHOICE_TYPES.includes(f.type);
                    onChange({ field: id, op: choice ? 'equals' : 'answered', value: choice ? f.options[0]?.id ?? null : null });
                }}
            >
                <SelectTrigger className="h-8 bg-background text-xs">
                    <SelectValue placeholder="Pick a question" />
                </SelectTrigger>
                <SelectContent>
                    {usable.map((f) => (
                        <SelectItem key={f.id} value={f.id} className="text-xs">
                            {f.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Select value={condition.op} onValueChange={(v) => onChange({ ...condition, op: v as LeadCondition['op'] })}>
                <SelectTrigger className="h-8 bg-background text-xs">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {ops.map((o) => (
                        <SelectItem key={o.op} value={o.op} className="text-xs">
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {op?.needsValue &&
                (isChoice ? (
                    <Select value={condition.value ?? ''} onValueChange={(v) => onChange({ ...condition, value: v })}>
                        <SelectTrigger className="h-8 bg-background text-xs">
                            <SelectValue placeholder="Pick an answer" />
                        </SelectTrigger>
                        <SelectContent>
                            {source!.options.map((o) => (
                                <SelectItem key={o.id} value={o.id} className="text-xs">
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : (
                    <Input value={condition.value ?? ''} onChange={(e) => onChange({ ...condition, value: e.target.value })} placeholder="Value" className="h-8 bg-background text-xs" maxLength={200} />
                ))}
        </div>
    );
}
