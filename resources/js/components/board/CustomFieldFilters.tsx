import { useEffect, useState } from 'react';
import type { ItemField } from '@/types';
import { cn } from '@/lib/utils';
import { Input } from '@/components/ui/input';
import type { FieldFilter, FieldFilters } from '@/lib/customFields';

const SECTION_TITLE = 'text-[11px] font-semibold text-muted-foreground uppercase tracking-wide';

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-xs border transition-all',
                active
                    ? 'border-primary bg-primary/10 text-primary font-semibold'
                    : 'border-transparent bg-muted/60 text-muted-foreground hover:bg-muted',
            )}
        >
            {children}
        </button>
    );
}

/**
 * Filter controls for the board's custom fields (inside the Filter popover). One filter per
 * field; `onChange(fieldId, null)` clears it.
 */
export function CustomFieldFilters({ fields, filters, onChange }: {
    fields: ItemField[];
    filters: FieldFilters;
    onChange: (fieldId: number, filter: FieldFilter | null) => void;
}) {
    if (fields.length === 0) return null;

    return (
        <>
            {fields.map((field) => (
                <div key={field.id} className="space-y-2">
                    <p className={SECTION_TITLE}>{field.name}</p>
                    <FieldFilterControl field={field} filter={filters[field.id] ?? null} onChange={(f) => onChange(field.id, f)} />
                </div>
            ))}
        </>
    );
}

function FieldFilterControl({ field, filter, onChange }: { field: ItemField; filter: FieldFilter | null; onChange: (filter: FieldFilter | null) => void }) {
    const presence = (kind: 'empty' | 'any') => (
        <Chip active={filter?.kind === kind} onClick={() => onChange(filter?.kind === kind ? null : { kind })}>
            {kind === 'empty' ? 'Empty' : 'Has a value'}
        </Chip>
    );

    if (field.type === 'select') {
        const current = filter?.kind === 'options' ? filter : { kind: 'options' as const, ids: [] as number[], empty: filter?.kind === 'empty' };
        const update = (ids: number[], empty: boolean) => onChange(ids.length || empty ? { kind: 'options', ids, empty } : null);
        return (
            <div className="flex flex-wrap gap-1.5">
                {field.options.filter((o) => !o.archived_at || current.ids.includes(o.id)).map((o) => {
                    const active = current.ids.includes(o.id);
                    return (
                        <Chip key={o.id} active={active} onClick={() => update(active ? current.ids.filter((id) => id !== o.id) : [...current.ids, o.id], current.empty)}>
                            {o.color && <span className="size-2 rounded-full" style={{ backgroundColor: o.color }} />}
                            {o.label}
                        </Chip>
                    );
                })}
                <Chip active={current.empty} onClick={() => update(current.ids, !current.empty)}>Empty</Chip>
            </div>
        );
    }

    if (field.type === 'checkbox') {
        const is = (value: boolean) => filter?.kind === 'bool' && filter.value === value;
        return (
            <div className="flex flex-wrap gap-1.5">
                <Chip active={is(true)} onClick={() => onChange(is(true) ? null : { kind: 'bool', value: true })}>Yes</Chip>
                <Chip active={is(false)} onClick={() => onChange(is(false) ? null : { kind: 'bool', value: false })}>No</Chip>
                {presence('empty')}
            </div>
        );
    }

    if (field.type === 'text') {
        return (
            <div className="space-y-1.5">
                <TextFilter value={filter?.kind === 'text' ? filter.q : ''} onCommit={(q) => onChange(q ? { kind: 'text', q } : null)} label={field.name} />
                <div className="flex flex-wrap gap-1.5">{presence('empty')}{presence('any')}</div>
            </div>
        );
    }

    // number / date: inclusive range
    const range = filter?.kind === 'range' ? filter : { kind: 'range' as const, min: null, max: null };
    const setRange = (min: string | null, max: string | null) => onChange(min || max ? { kind: 'range', min, max } : null);
    const inputType = field.type === 'date' ? 'date' : 'number';
    return (
        <div className="space-y-1.5">
            <div className="flex items-center gap-1.5">
                <Input
                    type={inputType}
                    step="any"
                    value={range.min ?? ''}
                    onChange={(e) => setRange(e.target.value || null, range.max)}
                    className="h-8 min-w-0 px-2 text-xs"
                    aria-label={`${field.name} from`}
                    placeholder="From"
                />
                <span className="text-xs text-muted-foreground">–</span>
                <Input
                    type={inputType}
                    step="any"
                    value={range.max ?? ''}
                    onChange={(e) => setRange(range.min, e.target.value || null)}
                    className="h-8 min-w-0 px-2 text-xs"
                    aria-label={`${field.name} to`}
                    placeholder="To"
                />
            </div>
            <div className="flex flex-wrap gap-1.5">{presence('empty')}{presence('any')}</div>
        </div>
    );
}

/** Text "contains" input: applies on Enter / blur so the URL doesn't change on every key. */
function TextFilter({ value, onCommit, label }: { value: string; onCommit: (q: string) => void; label: string }) {
    const [draft, setDraft] = useState(value);
    useEffect(() => setDraft(value), [value]);
    const commit = () => { if (draft.trim() !== value) onCommit(draft.trim()); };
    return (
        <Input
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => { if (e.key === 'Enter') commit(); }}
            placeholder="Contains…"
            className="h-8 text-xs"
            aria-label={`${label} contains`}
        />
    );
}
