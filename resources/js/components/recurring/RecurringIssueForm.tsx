import { FormEvent, useEffect, useState } from 'react';
import axios from 'axios';
import { CalendarClock, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import { cn } from '@/lib/utils';
import type { RecurrenceFrequency, RecurringIssueOptions } from '@/types';
import {
    BoardRouteParams, FREQUENCY_UNITS, MONTH_WEEKS, RecurringIssueDraft, WEEKDAYS, errorMessage, formatDate, isoWeekday, ruleOf,
} from './recurrence';

const NONE = 'none';

type Ends = 'never' | 'date' | 'count';

interface Props {
    params: BoardRouteParams;
    options: RecurringIssueOptions;
    timezone: string;
    initial: RecurringIssueDraft;
    /** Editing an existing template (its state shapes the preview). */
    templateId?: number;
    saving: boolean;
    onSubmit: (draft: RecurringIssueDraft) => void;
    onCancel: () => void;
}

/**
 * Create / edit a recurring issue template (BRAI-123): the issue fields, the schedule and a preview of
 * the next 5 dates computed by the server (the same maths the scheduler uses).
 */
export function RecurringIssueForm({ params, options, timezone, initial, templateId, saving, onSubmit, onCancel }: Props) {
    const [draft, setDraft] = useState<RecurringIssueDraft>(initial);
    const [ends, setEnds] = useState<Ends>(initial.end_date ? 'date' : initial.max_occurrences ? 'count' : 'never');
    const [preview, setPreview] = useState<{ dates: string[]; summary: string } | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    const set = <K extends keyof RecurringIssueDraft>(key: K, value: RecurringIssueDraft[K]) => setDraft((d) => ({ ...d, [key]: value }));

    const payload = (): RecurringIssueDraft => ({
        ...draft,
        end_date: ends === 'date' ? draft.end_date : null,
        max_occurrences: ends === 'count' ? draft.max_occurrences : null,
    });

    // the preview follows every schedule change (debounced)
    const rule = JSON.stringify(ruleOf(payload()));
    useEffect(() => {
        let cancelled = false;
        setPreviewLoading(true);
        const timer = window.setTimeout(() => {
            axios.post(route('recurring-issues.preview', params), { ...JSON.parse(rule), id: templateId ?? null })
                .then(({ data }) => { if (!cancelled) { setPreview(data); setPreviewError(null); } })
                .catch((error) => { if (!cancelled) { setPreview(null); setPreviewError(errorMessage(error, 'Could not compute the dates.')); } })
                .finally(() => { if (!cancelled) setPreviewLoading(false); });
        }, 300);
        return () => { cancelled = true; window.clearTimeout(timer); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rule, templateId]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        onSubmit(payload());
    };

    const toggleWeekday = (iso: number) => {
        const next = draft.weekdays.includes(iso) ? draft.weekdays.filter((d) => d !== iso) : [...draft.weekdays, iso].sort();
        set('weekdays', next.length ? next : [iso]); // at least one day
    };

    const toggleLabel = (id: number) =>
        set('label_ids', draft.label_ids.includes(id) ? draft.label_ids.filter((l) => l !== id) : [...draft.label_ids, id]);

    const unit = FREQUENCY_UNITS[draft.frequency][draft.interval === 1 ? 0 : 1];

    return (
        <form onSubmit={submit} className="flex flex-col gap-5" aria-label={templateId ? 'Edit recurring issue' : 'New recurring issue'}>
            {/* ── The issue ── */}
            <section className="flex flex-col gap-3">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="recurring-title">Title</Label>
                    <Input
                        id="recurring-title"
                        value={draft.title}
                        onChange={(e) => set('title', e.target.value)}
                        placeholder="Weekly report {{date}}"
                        maxLength={255}
                        required
                        autoFocus
                    />
                    <p className="text-[12px] text-muted-foreground">
                        <code className="rounded bg-muted px-1">{'{{date}}'}</code> becomes the date of each issue.
                    </p>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Description</Label>
                    <div className="rounded-md border">
                        <RichLexicalEditor
                            content={draft.description}
                            onChange={(html) => set('description', html || null)}
                            placeholder="What should be done each time?"
                            showToolbar={false}
                            minHeight="72px"
                            maxHeight="200px"
                        />
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Choice
                        label="Type"
                        value={draft.item_type_id}
                        noneLabel="Default type"
                        items={options.types}
                        onChange={(v) => set('item_type_id', v)}
                    />
                    <Choice
                        label="Priority"
                        value={draft.priority_id}
                        noneLabel="Default priority"
                        items={options.priorities}
                        onChange={(v) => set('priority_id', v)}
                    />
                    <Choice
                        label="Assignee"
                        value={draft.assigned_to}
                        noneLabel="Unassigned"
                        items={options.members}
                        onChange={(v) => set('assigned_to', v)}
                    />
                    <Choice
                        label="Column"
                        value={draft.column_id}
                        noneLabel={`First column${options.columns[0] ? ` (${options.columns[0].name})` : ''}`}
                        items={options.columns}
                        onChange={(v) => set('column_id', v)}
                    />
                </div>

                {options.labels.length > 0 && (
                    <div className="flex flex-col gap-1.5">
                        <Label>Labels</Label>
                        <div className="flex flex-wrap gap-1.5">
                            {options.labels.map((label) => {
                                const on = draft.label_ids.includes(label.id);
                                return (
                                    <button
                                        key={label.id}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => toggleLabel(label.id)}
                                        className={cn(
                                            'inline-flex h-6 items-center gap-1.5 rounded-full border px-2.5 text-[12px] transition-colors',
                                            on ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        <span className="size-2 rounded-full" style={{ background: label.color }} />
                                        {label.name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Label htmlFor="recurring-due">Due</Label>
                    <Input
                        id="recurring-due"
                        type="number"
                        min={0}
                        max={365}
                        className="h-8 w-20"
                        value={draft.due_in_days ?? ''}
                        placeholder="–"
                        onChange={(e) => set('due_in_days', e.target.value === '' ? null : Math.max(0, Number(e.target.value)))}
                    />
                    <span className="text-[13px] text-muted-foreground">days after each date (empty = no due date)</span>
                </div>
            </section>

            {/* ── The schedule ── */}
            <section className="flex flex-col gap-3 border-t pt-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Label htmlFor="recurring-frequency">Repeat</Label>
                    <Select value={draft.frequency} onValueChange={(v) => set('frequency', v as RecurrenceFrequency)}>
                        <SelectTrigger id="recurring-frequency" className="h-8 w-32" aria-label="Repeat">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="daily">Daily</SelectItem>
                            <SelectItem value="weekly">Weekly</SelectItem>
                            <SelectItem value="monthly">Monthly</SelectItem>
                            <SelectItem value="yearly">Yearly</SelectItem>
                        </SelectContent>
                    </Select>
                    <Label htmlFor="recurring-interval" className="ml-2">Every</Label>
                    <Input
                        id="recurring-interval"
                        type="number"
                        min={1}
                        max={99}
                        className="h-8 w-16"
                        value={draft.interval}
                        onChange={(e) => set('interval', Math.min(99, Math.max(1, Number(e.target.value) || 1)))}
                    />
                    <span className="text-[13px] text-muted-foreground">{unit}</span>
                </div>

                {draft.frequency === 'weekly' && (
                    <div className="flex flex-wrap gap-1.5" role="group" aria-label="On these days">
                        {WEEKDAYS.map((day) => {
                            const on = draft.weekdays.includes(day.iso);
                            return (
                                <button
                                    key={day.iso}
                                    type="button"
                                    aria-pressed={on}
                                    aria-label={day.long}
                                    onClick={() => toggleWeekday(day.iso)}
                                    className={cn(
                                        'h-8 w-11 rounded-md border text-[12px] font-medium transition-colors',
                                        on ? 'border-primary bg-primary text-primary-foreground' : 'border-border text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {day.short}
                                </button>
                            );
                        })}
                    </div>
                )}

                {draft.frequency === 'monthly' && (
                    <div className="flex flex-col gap-2 text-[13px]" role="radiogroup" aria-label="Day of the month">
                        <label className="flex flex-wrap items-center gap-2">
                            <input type="radio" name="monthly-mode" checked={draft.monthly_mode !== 'weekday'} onChange={() => set('monthly_mode', 'day')} />
                            On day
                            <Input
                                type="number"
                                min={1}
                                max={31}
                                aria-label="Day of the month"
                                className="h-8 w-16"
                                value={draft.month_day ?? 1}
                                onChange={(e) => setDraft((d) => ({ ...d, monthly_mode: 'day', month_day: Math.min(31, Math.max(1, Number(e.target.value) || 1)) }))}
                            />
                            {(draft.month_day ?? 0) >= 29 && <span className="text-muted-foreground">(the last day in shorter months)</span>}
                        </label>
                        <label className="flex flex-wrap items-center gap-2">
                            <input type="radio" name="monthly-mode" checked={draft.monthly_mode === 'weekday'} onChange={() => set('monthly_mode', 'weekday')} />
                            On the
                            <NativeSelect
                                label="Which week"
                                value={draft.month_week ?? 1}
                                onChange={(v) => setDraft((d) => ({ ...d, monthly_mode: 'weekday', month_week: v }))}
                                items={MONTH_WEEKS}
                            />
                            <NativeSelect
                                label="Which day"
                                value={draft.month_weekday ?? 1}
                                onChange={(v) => setDraft((d) => ({ ...d, monthly_mode: 'weekday', month_weekday: v }))}
                                items={[...WEEKDAYS.map((w) => ({ value: w.iso, label: w.long })), { value: 0, label: 'weekday (Mon–Fri)' }]}
                            />
                        </label>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Label htmlFor="recurring-start">Start date</Label>
                    <Input
                        id="recurring-start"
                        type="date"
                        className="h-8 w-40"
                        value={draft.start_date}
                        required
                        onChange={(e) => {
                            const start = e.target.value;
                            if (!start) return;
                            // a weekly rule that only had the old start's weekday follows the new one
                            setDraft((d) => ({
                                ...d,
                                start_date: start,
                                weekdays: d.weekdays.length === 1 && d.weekdays[0] === isoWeekday(d.start_date) ? [isoWeekday(start)] : d.weekdays,
                            }));
                        }}
                    />
                </div>

                <div className="flex flex-col gap-2 text-[13px]" role="radiogroup" aria-label="Ends">
                    <span className="text-sm font-medium">Ends</span>
                    <label className="flex items-center gap-2">
                        <input type="radio" name="recurring-ends" checked={ends === 'never'} onChange={() => setEnds('never')} />
                        Never
                    </label>
                    <label className="flex flex-wrap items-center gap-2">
                        <input type="radio" name="recurring-ends" checked={ends === 'date'} onChange={() => setEnds('date')} />
                        On
                        <Input
                            type="date"
                            aria-label="End date"
                            className="h-8 w-40"
                            value={draft.end_date ?? ''}
                            min={draft.start_date}
                            onChange={(e) => { setEnds('date'); set('end_date', e.target.value || null); }}
                        />
                    </label>
                    <label className="flex flex-wrap items-center gap-2">
                        <input type="radio" name="recurring-ends" checked={ends === 'count'} onChange={() => setEnds('count')} />
                        After
                        <Input
                            type="number"
                            min={1}
                            max={1000}
                            aria-label="Number of issues"
                            className="h-8 w-20"
                            value={draft.max_occurrences ?? ''}
                            onChange={(e) => { setEnds('count'); set('max_occurrences', e.target.value === '' ? null : Math.max(1, Number(e.target.value))); }}
                        />
                        issues
                    </label>
                </div>

                <div className="rounded-lg border bg-muted/40 p-3" aria-live="polite">
                    <div className="flex items-center gap-2 text-[13px] font-medium">
                        <CalendarClock className="size-4 text-muted-foreground" />
                        {preview?.summary ?? 'Next dates'}
                        {previewLoading && <Loader2 className="size-3.5 animate-spin text-muted-foreground" />}
                    </div>
                    {previewError ? (
                        <p className="mt-1.5 text-[12px] text-destructive">{previewError}</p>
                    ) : (
                        <ol className="mt-1.5 flex flex-col gap-0.5 text-[12.5px] text-muted-foreground" aria-label="Next dates">
                            {preview?.dates.map((date) => <li key={date}>{formatDate(date)}</li>)}
                            {preview && preview.dates.length === 0 && <li>No more dates: the series has ended.</li>}
                        </ol>
                    )}
                    <p className="mt-2 text-[11.5px] text-muted-foreground">
                        Each issue is created from midnight ({timezone}) on its date. Missed dates are not created later, except the latest one.
                    </p>
                </div>
            </section>

            <div className="flex justify-end gap-2 border-t pt-4">
                <Button type="button" variant="ghost" onClick={onCancel}>Cancel</Button>
                <Button type="submit" disabled={saving || !draft.title.trim()}>
                    {saving && <Loader2 className="mr-1.5 size-4 animate-spin" />}
                    {templateId ? 'Save changes' : 'Create recurring issue'}
                </Button>
            </div>
        </form>
    );
}

/** A labelled shadcn select over {id, name} with a "none" entry. */
function Choice({ label, value, noneLabel, items, onChange }: {
    label: string;
    value: number | null;
    noneLabel: string;
    items: { id: number; name: string }[];
    onChange: (value: number | null) => void;
}) {
    const id = `recurring-${label.toLowerCase()}`;
    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value === null ? NONE : String(value)} onValueChange={(v) => onChange(v === NONE ? null : Number(v))}>
                <SelectTrigger id={id} className="h-9" aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{noneLabel}</SelectItem>
                    {items.map((item) => (
                        <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

/** Small inline select inside a sentence ("On the [last] [Friday]"). */
function NativeSelect({ label, value, items, onChange }: {
    label: string;
    value: number;
    items: { value: number; label: string }[];
    onChange: (value: number) => void;
}) {
    return (
        <select
            aria-label={label}
            value={value}
            onChange={(e) => onChange(Number(e.target.value))}
            className="h-8 rounded-md border border-input bg-background px-2 text-[13px]"
        >
            {items.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
        </select>
    );
}
