import type {
    AutomationCatalog,
    AutomationCondition,
    AutomationConditionValue,
    AutomationCustomField,
    AutomationMatch,
    AutomationOperator,
    AutomationOperatorValue,
    AutomationOptions,
    AutomationSubject,
} from '@/types';
import { choices, type Option, type OptionGroup } from './ValueField';

/**
 * Pure helpers of the automation condition builder (BRAI-144): which fields and operators fit the
 * rule's subject (from AutomationCatalog::forFrontend, the one source of truth), the value a new
 * operator starts with, the choices of an id field, and a condition in words (the same wording as
 * AutomationConditions::describe on the server).
 */
export const CUSTOM_PREFIX = 'custom:';

export interface ResolvedField {
    key: string;
    label: string;
    source: string | null;
    operators: string[];
    custom: AutomationCustomField | null;
}

/** Fields a rule on this subject may use: catalog fields + the project's custom fields (issues). */
export function fieldsFor(catalog: AutomationCatalog, options: AutomationOptions, subject: AutomationSubject): ResolvedField[] {
    const standard = catalog.conditionFields
        .filter((f) => f.subjects.includes(subject))
        .map((f) => ({ key: f.key, label: f.label, source: f.source, operators: f.operators, custom: null }));
    if (subject !== 'item') return standard;

    const custom = options.customFields
        .filter((f) => catalog.customFieldOperators[f.type])
        .map((f) => ({
            key: `${CUSTOM_PREFIX}${f.id}`,
            label: f.name,
            source: f.type === 'select' ? 'custom_option' : null,
            operators: catalog.customFieldOperators[f.type],
            custom: f,
        }));
    return [...standard, ...custom];
}

export function operatorOf(catalog: AutomationCatalog, key: string): AutomationOperator | undefined {
    return catalog.operators.find((o) => o.key === key);
}

/** The value an operator starts with, keeping what still fits from the previous one. */
export function nextValue(shape: AutomationOperatorValue, previous: AutomationConditionValue, previousShape: AutomationOperatorValue | undefined): AutomationConditionValue {
    if (shape === previousShape) return previous;
    if (shape === 'many' && previousShape === 'one' && typeof previous === 'number') return [previous];
    if (shape === 'one' && previousShape === 'many' && Array.isArray(previous)) return previous[0] ?? null;
    switch (shape) {
        case 'many':
            return [];
        case 'text':
            return '';
        case 'days':
            return { days: 7 };
        default:
            return null;
    }
}

/** A blank condition on the first field that fits. */
export function blankCondition(catalog: AutomationCatalog, fields: ResolvedField[]): AutomationCondition {
    const field = fields[0];
    const operator = field.operators[0];
    return { field: field.key, operator, value: nextValue(operatorOf(catalog, operator)?.value ?? null, null, undefined) };
}

/** Id choices of a field (flat; column choices come grouped by board / helpdesk). */
export function fieldChoices(field: ResolvedField, options: AutomationOptions, subject: AutomationSubject): Option[] | OptionGroup[] {
    if (field.source === 'custom_option') {
        return (field.custom?.options ?? []).map((o) => ({ value: String(o.id), label: o.name }));
    }
    return field.source ? choices(field.source, options, subject) : [];
}

export function flatChoices(list: Option[] | OptionGroup[]): Option[] {
    return list.flatMap((entry) => ('group' in entry ? entry.options.map((o) => ({ ...o, label: `${entry.group} → ${o.label}` })) : [entry]));
}

const numberWords = (value: unknown) => (typeof value === 'number' ? String(value) : String(value ?? ''));

/** "Labels has none of Bug, Duplicate" — for the sentence preview. */
export function describeCondition(
    condition: AutomationCondition,
    catalog: AutomationCatalog,
    options: AutomationOptions,
    subject: AutomationSubject,
): string {
    const field = fieldsFor(catalog, options, subject).find((f) => f.key === condition.field);
    if (!field) return condition.field.startsWith(CUSTOM_PREFIX) ? 'Custom field (field removed)' : condition.field;
    const operator = operatorOf(catalog, condition.operator);
    if (!operator || !field.operators.includes(operator.key)) return `${field.label} …`;

    const names = new Map(flatChoices(fieldChoices(field, options, subject)).map((o) => [o.value, o.label]));
    const name = (id: unknown) => names.get(String(id)) ?? '…';
    const value = condition.value;
    if (operator.key === 'within_next_days' && typeof value === 'object' && value !== null && !Array.isArray(value) && value.days === 0) {
        return `${field.label} is today`;
    }

    let words: string | null;
    switch (operator.value) {
        case null:
            words = null;
            break;
        case 'one':
            words = value === null ? '…' : name(value);
            break;
        case 'many':
            words = Array.isArray(value) && value.length > 0 ? value.map(name).join(', ') : '…';
            break;
        case 'text':
            words = typeof value === 'string' && value.trim() !== '' ? `"${value.trim()}"` : '…';
            break;
        case 'days': {
            const days = typeof value === 'object' && value !== null && !Array.isArray(value) ? value.days : null;
            words = days === null || Number.isNaN(days) ? '…' : `${days} ${days === 1 ? 'day' : 'days'}`;
            break;
        }
        default:
            words = value === null || value === '' ? '…' : numberWords(value);
    }
    return words === null ? `${field.label} ${operator.label}` : `${field.label} ${operator.label} ${words}`;
}

/** "if A and B" / "if A or B". */
export const joinConditions = (labels: string[], match: AutomationMatch) => labels.join(match === 'any' ? ' or ' : ' and ');
