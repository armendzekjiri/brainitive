import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { AutomationCatalog, AutomationCondition, AutomationConditionValue, AutomationOperatorValue, AutomationOptions, AutomationSubject } from '@/types';
import { selectClass, toValue } from './ValueField';
import { fieldChoices, flatChoices, nextValue, operatorOf, type ResolvedField } from './conditions';

/**
 * One condition of the automation builder (BRAI-144): Field → Operator (only the ones that fit the
 * field) → a value control that fits the operator (one id, several ids as chips, text, number, date,
 * "next N days", or nothing for "is empty" and friends). Native selects: stable for keyboards, phones
 * and e2e. A custom field that was removed stays visible as "Field removed" until it is changed.
 */
interface Props {
    index: number;
    count: number;
    condition: AutomationCondition;
    fields: ResolvedField[];
    catalog: AutomationCatalog;
    options: AutomationOptions;
    subject: AutomationSubject;
    errors: { field?: string; operator?: string; value?: string };
    onChange: (condition: AutomationCondition) => void;
    onMove: (by: -1 | 1) => void;
    onRemove: () => void;
}

export default function ConditionRow({ index, count, condition, fields, catalog, options, subject, errors, onChange, onMove, onRemove }: Props) {
    const n = index + 1;
    const field = fields.find((f) => f.key === condition.field);
    const operator = operatorOf(catalog, condition.operator);
    const standardFields = fields.filter((f) => !f.custom);
    const customFields = fields.filter((f) => f.custom);
    const error = errors.field ?? errors.operator ?? errors.value;

    const changeField = (key: string) => {
        const next = fields.find((f) => f.key === key);
        if (!next) return;
        // keep the operator when the new field has it too ("is" → "is"), else its first one
        const op = next.operators.includes(condition.operator) ? condition.operator : next.operators[0];
        const shape = operatorOf(catalog, op)?.value ?? null;
        const keep = next.source === field?.source && op === condition.operator;
        onChange({ field: key, operator: op, value: keep ? condition.value : nextValue(shape, null, undefined) });
    };

    const changeOperator = (key: string) => {
        const shape = operatorOf(catalog, key)?.value ?? null;
        onChange({ ...condition, operator: key, value: nextValue(shape, condition.value, operator?.value) });
    };

    const setValue = (value: AutomationConditionValue) => onChange({ ...condition, value });

    return (
        <div className="space-y-1 rounded-lg border p-3" data-testid={`condition-${n}`}>
            {/* phones: number + buttons on top, the three controls full width below */}
            <div className="flex flex-wrap items-start gap-2 sm:flex-nowrap">
                <span className="order-1 mt-2 w-5 shrink-0 text-xs font-semibold text-muted-foreground">{n}.</span>
                <div className="order-3 grid min-w-0 basis-full gap-2 sm:order-2 sm:flex-1 sm:basis-0 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.4fr)]">
                    <select
                        aria-label={`Condition ${n} field`}
                        aria-invalid={!!errors.field}
                        className={cn(selectClass, (errors.field || !field) && 'border-destructive')}
                        value={condition.field}
                        onChange={(e) => changeField(e.target.value)}
                    >
                        {!field && <option value={condition.field} disabled>Field removed</option>}
                        {standardFields.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
                        {customFields.length > 0 && (
                            <optgroup label="Custom fields">
                                {customFields.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
                            </optgroup>
                        )}
                    </select>

                    <select
                        aria-label={`Condition ${n} operator`}
                        aria-invalid={!!errors.operator}
                        className={cn(selectClass, errors.operator && 'border-destructive')}
                        value={condition.operator}
                        disabled={!field}
                        onChange={(e) => changeOperator(e.target.value)}
                    >
                        {!field?.operators.includes(condition.operator) && <option value={condition.operator} disabled>{operator?.label ?? condition.operator}</option>}
                        {(field?.operators ?? []).map((key) => <option key={key} value={key}>{operatorOf(catalog, key)?.label ?? key}</option>)}
                    </select>

                    {field && operator?.value && (
                        <ConditionValue
                            label={`Condition ${n} value`}
                            shape={operator.value}
                            field={field}
                            value={condition.value}
                            onChange={setValue}
                            options={options}
                            subject={subject}
                            maxList={catalog.maxList}
                            invalid={!!errors.value}
                        />
                    )}
                </div>
                <div className="order-2 ml-auto flex shrink-0 sm:order-3 sm:ml-0">
                    <Button type="button" variant="ghost" size="icon" aria-label={`Move condition ${n} up`} disabled={index === 0} onClick={() => onMove(-1)}><ArrowUp className="size-4" /></Button>
                    <Button type="button" variant="ghost" size="icon" aria-label={`Move condition ${n} down`} disabled={index === count - 1} onClick={() => onMove(1)}><ArrowDown className="size-4" /></Button>
                    <Button type="button" variant="ghost" size="icon" aria-label={`Remove condition ${n}`} onClick={onRemove}><X className="size-4" /></Button>
                </div>
            </div>
            {!field && !error && <p className="text-sm text-destructive sm:pl-7">This field was removed. Pick another field or remove the condition.</p>}
            {error && <p className="text-sm text-destructive sm:pl-7">{error}</p>}
        </div>
    );
}

interface ValueProps {
    label: string;
    shape: AutomationOperatorValue;
    field: ResolvedField;
    value: AutomationConditionValue;
    onChange: (value: AutomationConditionValue) => void;
    options: AutomationOptions;
    subject: AutomationSubject;
    maxList: number;
    invalid: boolean;
}

function ConditionValue({ label, shape, field, value, onChange, options, subject, maxList, invalid }: ValueProps) {
    const inputClass = cn(invalid && 'border-destructive');

    switch (shape) {
        case 'text':
            return <Input aria-label={label} aria-invalid={invalid} className={inputClass} value={typeof value === 'string' ? value : ''} maxLength={200} onChange={(e) => onChange(e.target.value)} placeholder="Text" />;
        case 'number':
            return (
                <Input
                    type="number"
                    step="any"
                    aria-label={label}
                    aria-invalid={invalid}
                    className={inputClass}
                    value={typeof value === 'number' || typeof value === 'string' ? value : ''}
                    onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
                    placeholder="Number"
                />
            );
        case 'date':
            return <Input type="date" aria-label={label} aria-invalid={invalid} className={inputClass} value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value || null)} />;
        case 'days': {
            const days = typeof value === 'object' && value !== null && !Array.isArray(value) ? value.days : '';
            return (
                <div className="flex items-center gap-2">
                    <Input
                        type="number"
                        min={0}
                        max={365}
                        aria-label={label}
                        aria-invalid={invalid}
                        className={cn('w-24', inputClass)}
                        value={Number.isNaN(days) ? '' : days}
                        onChange={(e) => onChange({ days: e.target.value === '' ? Number.NaN : Number(e.target.value) })}
                    />
                    <span className="text-sm text-muted-foreground">days</span>
                </div>
            );
        }
        case 'many':
            return <ManyValue label={label} field={field} value={Array.isArray(value) ? value : []} onChange={onChange} options={options} subject={subject} maxList={maxList} invalid={invalid} />;
        default: {
            const list = fieldChoices(field, options, subject);
            return (
                <select aria-label={label} aria-invalid={invalid} className={cn(selectClass, invalid && 'border-destructive')} value={value === null || typeof value === 'object' ? '' : String(value)} onChange={(e) => onChange(toValue(e.target.value) as AutomationConditionValue)}>
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
    }
}

/** Several ids: chips you can remove, and a select that adds one more. */
function ManyValue({ label, field, value, onChange, options, subject, maxList, invalid }: Omit<ValueProps, 'shape' | 'value'> & { value: number[] }) {
    const all = flatChoices(fieldChoices(field, options, subject));
    const names = new Map(all.map((o) => [o.value, o.label]));
    const left = all.filter((o) => !value.includes(Number(o.value)));

    return (
        <div className="space-y-1.5">
            <select
                aria-label={label}
                aria-invalid={invalid}
                className={cn(selectClass, invalid && 'border-destructive')}
                value=""
                disabled={left.length === 0 || value.length >= maxList}
                onChange={(e) => e.target.value !== '' && onChange([...value, Number(e.target.value)])}
            >
                <option value="">{all.length === 0 ? 'Nothing to pick yet' : value.length === 0 ? 'Choose…' : 'Add another…'}</option>
                {left.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            {value.length > 0 && (
                <ul className="flex flex-wrap gap-1.5" aria-label={label.replace(/ value$/, ' chosen values')}>
                    {value.map((id) => {
                        const name = names.get(String(id)) ?? '(deleted)';
                        return (
                            <li key={id} className="inline-flex max-w-full items-center gap-1 rounded-full bg-primary/10 py-0.5 pl-2.5 pr-1 text-xs font-medium text-primary">
                                <span className="truncate">{name}</span>
                                <button type="button" className="rounded-full p-0.5 hover:bg-primary/20" aria-label={`Remove ${name}`} onClick={() => onChange(value.filter((v) => v !== id))}>
                                    <X className="size-3" />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
