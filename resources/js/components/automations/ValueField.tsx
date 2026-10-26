import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { AutomationOptions, AutomationSubject, AutomationValue } from '@/types';

/**
 * The value control of a condition or action in the automation builder (v1.40.0, BRAI-63), by value
 * kind (see AutomationCatalog.php): a native select for ids (stable for keyboard and e2e), a number
 * for "days", a text input for "text".
 */
export const selectClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:opacity-50';

interface Props {
    kind: string;
    value: AutomationValue;
    onChange: (value: AutomationValue) => void;
    options: AutomationOptions;
    subject: AutomationSubject;
    label: string;
    invalid?: boolean;
}

export type Option = { value: string; label: string };
export type OptionGroup = { group: string; options: Option[] };

export function choices(kind: string, options: AutomationOptions, subject: AutomationSubject): Option[] | OptionGroup[] {
    const ids = (rows: { id: number; name: string }[]) => rows.map((r) => ({ value: String(r.id), label: r.name }));
    switch (kind) {
        case 'board':
            return ids(options.boards);
        case 'helpdesk':
            return ids(options.helpdesks);
        case 'item_type':
            return ids(options.itemTypes);
        case 'priority':
            return ids(options.priorities);
        case 'label':
            return ids(options.labels);
        case 'member':
            return ids(options.members);
        case 'assignee':
            return [...(subject === 'item' ? [{ value: 'reporter', label: 'The reporter' }] : []), ...ids(options.members)];
        case 'recipient':
            return [{ value: 'assignee', label: 'The assignee' }, ...ids(options.members)];
        case 'column':
            return (subject === 'ticket' ? options.helpdesks : options.boards).map((c) => ({ group: c.name, options: ids(c.columns) }));
        default:
            return [];
    }
}

/** A select's raw value: "" = nothing, digits = an id, else a keyword ("reporter"). */
export const toValue = (raw: string): AutomationValue => (raw === '' ? null : /^\d+$/.test(raw) ? Number(raw) : raw);

export default function ValueField({ kind, value, onChange, options, subject, label, invalid }: Props) {
    if (kind === 'days') {
        return (
            <div className="flex items-center gap-2">
                <span className="shrink-0 text-sm text-muted-foreground">today +</span>
                <Input type="number" min={0} max={365} aria-label={label} aria-invalid={invalid} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} className="w-24" />
                <span className="text-sm text-muted-foreground">days</span>
            </div>
        );
    }
    if (kind === 'text') {
        return <Input aria-label={label} aria-invalid={invalid} value={(value as string | null) ?? ''} maxLength={200} onChange={(e) => onChange(e.target.value)} placeholder="Text" />;
    }

    const list = choices(kind, options, subject);

    return (
        <select aria-label={label} aria-invalid={invalid} className={cn(selectClass, invalid && 'border-destructive')} value={value === null || value === undefined ? '' : String(value)} onChange={(e) => onChange(toValue(e.target.value))}>
            <option value="">Choose…</option>
            {list.map((entry) =>
                'group' in entry ? (
                    <optgroup key={entry.group} label={entry.group}>
                        {entry.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </optgroup>
                ) : (
                    <option key={entry.value} value={entry.value}>{entry.label}</option>
                ),
            )}
        </select>
    );
}
