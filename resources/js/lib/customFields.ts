import type { Item, ItemField, ItemFieldOption, ItemFieldType } from '@/types';

/**
 * Board custom fields (BRAI-77): read, format, filter and sort values on the client.
 *
 * A value is typed by its field: text → string, number → number, date → 'YYYY-MM-DD',
 * checkbox → boolean, select → option id. `null` = no value. 0 and false are values.
 */
export type FieldValue = string | number | boolean | null;

export const FIELD_TYPE_LABELS: Record<ItemFieldType, string> = {
    text: 'Text',
    number: 'Number',
    date: 'Date',
    checkbox: 'Checkbox',
    select: 'Single select',
};

export function fieldValue(item: Pick<Item, 'field_values'>, field: Pick<ItemField, 'id' | 'type'>): FieldValue {
    const row = item.field_values?.find((v) => v.item_field_id === field.id);
    if (!row) return null;
    switch (field.type) {
        case 'text': return row.value_text;
        case 'number': return row.value_number === null ? null : Number(row.value_number);
        case 'date': return row.value_date ? String(row.value_date).slice(0, 10) : null;
        case 'checkbox': return row.value_bool === null ? null : Boolean(row.value_bool);
        case 'select': return row.option_id;
    }
}

export function fieldOption(field: Pick<ItemField, 'options'>, id: FieldValue): ItemFieldOption | undefined {
    return typeof id === 'number' ? field.options.find((o) => o.id === id) : undefined;
}

/** Options a user can pick: active ones, plus the item's current option if it was archived. */
export function selectableOptions(field: ItemField, current: FieldValue): ItemFieldOption[] {
    return field.options.filter((o) => !o.archived_at || o.id === current);
}

/** Plain text for lists and tooltips; '' when there's no value. */
export function formatFieldValue(field: ItemField, value: FieldValue): string {
    if (value === null || value === undefined) return '';
    switch (field.type) {
        case 'checkbox': return value ? 'Yes' : 'No';
        case 'number': return Number(value).toLocaleString(undefined, { maximumFractionDigits: 6 });
        case 'date': {
            const [y, m, d] = String(value).split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString();
        }
        case 'select': return fieldOption(field, value)?.label ?? '';
        default: return String(value);
    }
}

// ── Filters: one URL param per field, `f{id}=…` ─────────────────────────────────
//   none / any          → no value / has a value (every type)
//   select: 3,5,none    → one of these options (none = also issues without a value)
//   checkbox: yes / no
//   text: ~acme         → contains (case-insensitive)
//   number/date: 10..20, 10.., ..20 (inclusive)

export type FieldFilter =
    | { kind: 'empty' }
    | { kind: 'any' }
    | { kind: 'options'; ids: number[]; empty: boolean }
    | { kind: 'bool'; value: boolean }
    | { kind: 'text'; q: string }
    | { kind: 'range'; min: string | null; max: string | null };

export type FieldFilters = Record<number, FieldFilter>;

export const fieldParam = (fieldId: number) => `f${fieldId}`;

export function parseFieldFilter(field: ItemField, raw: string | null): FieldFilter | null {
    const value = (raw ?? '').trim();
    if (!value) return null;
    if (value === 'none') return { kind: 'empty' };
    // "Has a value" exists for text / number / date only (select and checkbox list the values).
    if (value === 'any') return field.type === 'select' || field.type === 'checkbox' ? null : { kind: 'any' };

    switch (field.type) {
        case 'select': {
            const parts = value.split(',');
            const ids = parts.map(Number).filter((n) => Number.isInteger(n) && n > 0 && field.options.some((o) => o.id === n));
            const empty = parts.includes('none');
            return ids.length || empty ? { kind: 'options', ids, empty } : null;
        }
        case 'checkbox':
            return value === 'yes' || value === 'no' ? { kind: 'bool', value: value === 'yes' } : null;
        case 'text':
            return value.startsWith('~') && value.length > 1 ? { kind: 'text', q: value.slice(1) } : null;
        case 'number':
        case 'date': {
            const [min, max, extra] = value.split('..');
            if (extra !== undefined || max === undefined) return null;
            const valid = (v: string) => (field.type === 'number' ? v !== '' && Number.isFinite(Number(v)) : /^\d{4}-\d{2}-\d{2}$/.test(v));
            const lo = min && valid(min) ? min : null;
            const hi = max && valid(max) ? max : null;
            return lo || hi ? { kind: 'range', min: lo, max: hi } : null;
        }
    }
    return null;
}

export function serializeFieldFilter(filter: FieldFilter | null | undefined): string {
    if (!filter) return '';
    switch (filter.kind) {
        case 'empty': return 'none';
        case 'any': return 'any';
        case 'options': return [...filter.ids, ...(filter.empty ? ['none'] : [])].join(',');
        case 'bool': return filter.value ? 'yes' : 'no';
        case 'text': return filter.q ? `~${filter.q}` : '';
        case 'range': return filter.min || filter.max ? `${filter.min ?? ''}..${filter.max ?? ''}` : '';
    }
}

export function readFieldFilters(params: URLSearchParams, fields: ItemField[]): FieldFilters {
    const filters: FieldFilters = {};
    for (const field of fields) {
        const filter = parseFieldFilter(field, params.get(fieldParam(field.id)));
        if (filter) filters[field.id] = filter;
    }
    return filters;
}

export function matchesFieldFilter(item: Pick<Item, 'field_values'>, field: ItemField, filter: FieldFilter): boolean {
    const value = fieldValue(item, field);
    switch (filter.kind) {
        case 'empty': return value === null;
        case 'any': return value !== null;
        case 'options': return value === null ? filter.empty : filter.ids.includes(value as number);
        case 'bool': return value === filter.value;
        case 'text': return value !== null && String(value).toLowerCase().includes(filter.q.toLowerCase());
        case 'range': {
            if (value === null) return false;
            if (field.type === 'number') {
                const n = Number(value);
                return (filter.min === null || n >= Number(filter.min)) && (filter.max === null || n <= Number(filter.max));
            }
            const d = String(value);
            return (filter.min === null || d >= filter.min) && (filter.max === null || d <= filter.max);
        }
    }
}

/** Short summary of an active filter for chips ("Gold, Silver", "≥ 10", "empty"). */
export function describeFieldFilter(field: ItemField, filter: FieldFilter): string {
    switch (filter.kind) {
        case 'empty': return 'empty';
        case 'any': return 'has a value';
        case 'options': return [...filter.ids.map((id) => fieldOption(field, id)?.label ?? '?'), ...(filter.empty ? ['empty'] : [])].join(', ');
        case 'bool': return filter.value ? 'Yes' : 'No';
        case 'text': return `contains “${filter.q}”`;
        case 'range': {
            const fmt = (v: string) => formatFieldValue(field, field.type === 'number' ? Number(v) : v);
            if (filter.min && filter.max) return `${fmt(filter.min)} – ${fmt(filter.max)}`;
            return filter.min ? `≥ ${fmt(filter.min)}` : `≤ ${fmt(filter.max!)}`;
        }
    }
}

// ── Sorting ─────────────────────────────────────────────────────────────────────

export type SortDirection = 'asc' | 'desc';

/**
 * Compare two possibly-missing values: missing ones always go last, whatever the direction,
 * so "sort by budget" never starts with a wall of empty rows.
 */
export function compareNullable<T>(a: T | null | undefined, b: T | null | undefined, dir: SortDirection, compare: (x: T, y: T) => number): number {
    const aEmpty = a === null || a === undefined;
    const bEmpty = b === null || b === undefined;
    if (aEmpty || bEmpty) return aEmpty === bEmpty ? 0 : aEmpty ? 1 : -1;
    const result = compare(a as T, b as T);
    return dir === 'asc' ? result : -result;
}

export const compareText = (a: string, b: string) => a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true });

/** Comparator for one custom field (select sorts by option order, not by label). */
export function fieldComparator(field: ItemField, dir: SortDirection) {
    const rank = (id: number) => {
        const index = field.options.findIndex((o) => o.id === id);
        return index === -1 ? Number.MAX_SAFE_INTEGER : index;
    };
    return (a: Pick<Item, 'field_values'>, b: Pick<Item, 'field_values'>) =>
        compareNullable(fieldValue(a, field), fieldValue(b, field), dir, (x, y) => {
            switch (field.type) {
                case 'number': return Number(x) - Number(y);
                case 'checkbox': return Number(x) - Number(y);
                case 'select': return rank(x as number) - rank(y as number);
                default: return compareText(String(x), String(y));
            }
        });
}
