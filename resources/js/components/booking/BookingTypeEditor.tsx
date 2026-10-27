import { router } from '@inertiajs/react';
import { Loader2, Plus, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { LOCATION_LABELS, browserTimezone, timeLabel, whenLabel } from '@/lib/booking';
import { cn } from '@/lib/utils';
import type { BookingConnectionData, BookingDay, BookingLocationKind, BookingQuestion, BookingRange, BookingTypeData } from '@/types';

const DAYS: [BookingDay, string][] = [['mon', 'Monday'], ['tue', 'Tuesday'], ['wed', 'Wednesday'], ['thu', 'Thursday'], ['fri', 'Friday'], ['sat', 'Saturday'], ['sun', 'Sunday']];
const DURATIONS = [15, 30, 45, 60, 90];
const select = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm';

interface Props {
    pageUuid: string;
    timezone: string;
    type: BookingTypeData | null;
    connections: BookingConnectionData[];
    onClose: () => void;
}

/** One meeting type, fully editable (BRAI-158): name + length, location, hours, overrides, rules, questions. */
export function BookingTypeEditor({ pageUuid, timezone, type, connections, onClose }: Props) {
    const [data, setData] = useState<BookingTypeData | null>(type);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [preview, setPreview] = useState<{ slots: string[]; unchecked: string[] } | null>(null);
    const [overrideDate, setOverrideDate] = useState('');

    useEffect(() => { setData(type); setErrors({}); setPreview(null); }, [type]);

    if (!data) return null;
    const set = <K extends keyof BookingTypeData>(key: K, value: BookingTypeData[K]) => setData({ ...data, [key]: value });
    const writable = connections.filter((c) => c.writable);
    const target = connections.find((c) => c.id === data.target_connection_id);
    const callKinds = target?.conference_providers ?? [];
    const custom = !DURATIONS.includes(data.duration_minutes);

    const setRanges = (day: BookingDay, ranges: BookingRange[]) => set('availability', { ...data.availability, weekly: { ...data.availability.weekly, [day]: ranges } });
    const setOverride = (date: string, ranges: BookingRange[] | null) => {
        const overrides = { ...data.availability.overrides };
        if (ranges === null) delete overrides[date];
        else overrides[date] = ranges;
        set('availability', { ...data.availability, overrides });
    };
    const setRule = (key: keyof BookingTypeData['rules'], value: string) =>
        set('rules', { ...data.rules, [key]: key === 'daily_max' ? (value === '' ? null : Number(value)) : Number(value) });
    const setQuestion = (i: number, patch: Partial<BookingQuestion>) => set('questions', data.questions.map((q, j) => (j === i ? { ...q, ...patch } : q)));

    const save = () => {
        setSaving(true);
        router.put(route('booking-types.update', [pageUuid, data.id]), data as never, {
            preserveScroll: true,
            onError: (e) => setErrors(e),
            onSuccess: () => { setErrors({}); onClose(); },
            onFinish: () => setSaving(false),
        });
    };

    const loadPreview = async () => {
        setPreview(null);
        const response = await fetch(route('booking-types.preview', [pageUuid, data.id]), { headers: { Accept: 'application/json' } });
        if (response.ok) setPreview(await response.json());
    };

    const rangeEditor = (ranges: BookingRange[], onChange: (r: BookingRange[]) => void, label: string) => (
        <div className="space-y-2">
            {ranges.map(([from, to], i) => (
                <div key={i} className="flex items-center gap-2">
                    <Input type="time" aria-label={`${label} from`} className="w-28" value={from} onChange={(e) => onChange(ranges.map((r, j) => (j === i ? [e.target.value, r[1]] : r)))} />
                    <span className="text-muted-foreground">–</span>
                    <Input type="time" aria-label={`${label} until`} className="w-28" value={to === '24:00' ? '23:59' : to} onChange={(e) => onChange(ranges.map((r, j) => (j === i ? [r[0], e.target.value === '23:59' ? '24:00' : e.target.value] : r)))} />
                    <Button type="button" variant="ghost" size="icon" aria-label={`Remove ${label} range`} onClick={() => onChange(ranges.filter((_, j) => j !== i))}><X className="h-4 w-4" /></Button>
                </div>
            ))}
            <Button type="button" variant="ghost" size="sm" onClick={() => onChange([...ranges, ranges.length ? [ranges[ranges.length - 1][1] < '23:00' ? ranges[ranges.length - 1][1] : '13:00', '17:00'] : ['09:00', '17:00']])}>
                <Plus className="mr-1 h-3.5 w-3.5" />Add hours
            </Button>
        </div>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Edit “{type?.name}”</DialogTitle>
                    <DialogDescription>Times are in the page’s time zone ({timezone.replace(/_/g, ' ')}).</DialogDescription>
                </DialogHeader>

                <div className="space-y-8">
                    <section className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="type-name">Name</Label>
                            <Input id="type-name" value={data.name} onChange={(e) => set('name', e.target.value)} />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="type-slug">Link ending</Label>
                            <Input id="type-slug" value={data.slug} onChange={(e) => set('slug', e.target.value.toLowerCase())} />
                            {errors.slug && <p className="text-sm text-destructive">{errors.slug}</p>}
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Length</Label>
                            <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Length">
                                {DURATIONS.map((m) => (
                                    <Button key={m} type="button" size="sm" role="radio" aria-checked={data.duration_minutes === m} variant={data.duration_minutes === m ? 'default' : 'outline'} onClick={() => set('duration_minutes', m)}>{m} min</Button>
                                ))}
                                <div className="flex items-center gap-1">
                                    <Input type="number" min={5} max={720} aria-label="Custom length in minutes" className={cn('w-24', custom && 'border-primary')} value={data.duration_minutes} onChange={(e) => set('duration_minutes', Number(e.target.value))} />
                                    <span className="text-sm text-muted-foreground">min</span>
                                </div>
                            </div>
                            {errors.duration_minutes && <p className="text-sm text-destructive">{errors.duration_minutes}</p>}
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="type-description">Description (optional)</Label>
                            <Textarea id="type-description" rows={2} value={data.description ?? ''} onChange={(e) => set('description', e.target.value)} />
                        </div>
                    </section>

                    <section className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="type-target">Add bookings to calendar</Label>
                            <select id="type-target" className={select} value={data.target_connection_id ?? ''} onChange={(e) => set('target_connection_id', e.target.value ? Number(e.target.value) : null)}>
                                <option value="">Don’t add to a calendar</option>
                                {writable.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.provider})</option>)}
                            </select>
                            <p className="text-xs text-muted-foreground">The guest is invited from this calendar.</p>
                            {errors.target_connection_id && <p className="text-sm text-destructive">{errors.target_connection_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="type-location">Where</Label>
                            <select id="type-location" className={select} value={data.location.kind} onChange={(e) => set('location', { kind: e.target.value as BookingLocationKind, value: data.location.value })}>
                                {Object.entries(LOCATION_LABELS).map(([kind, label]) => (
                                    <option key={kind} value={kind} disabled={(kind === 'google_meet' || kind === 'teams') && !callKinds.includes(kind)}>
                                        {label}{(kind === 'google_meet' || kind === 'teams') && !callKinds.includes(kind) ? ' (needs a matching calendar)' : ''}
                                    </option>
                                ))}
                            </select>
                            {['phone', 'in_person', 'link'].includes(data.location.kind) && (
                                <Input
                                    aria-label="Location details"
                                    placeholder={data.location.kind === 'link' ? 'https://…' : data.location.kind === 'phone' ? 'Your number, or “I’ll call you”' : 'Address'}
                                    value={data.location.value ?? ''}
                                    onChange={(e) => set('location', { ...data.location, value: e.target.value })}
                                />
                            )}
                            {errors['location.value'] && <p className="text-sm text-destructive">{errors['location.value']}</p>}
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">Weekly hours</h3>
                        {errors.availability && <p className="text-sm text-destructive">{errors.availability}</p>}
                        <div className="divide-y rounded-xl border">
                            {DAYS.map(([day, label]) => {
                                const ranges = data.availability.weekly[day] ?? [];
                                return (
                                    <div key={day} className="flex flex-wrap items-start gap-4 p-3">
                                        <label className="flex w-32 items-center gap-2 pt-2 text-sm font-medium">
                                            <input type="checkbox" checked={ranges.length > 0} onChange={(e) => setRanges(day, e.target.checked ? [['09:00', '17:00']] : [])} />
                                            {label}
                                        </label>
                                        <div className="flex-1">
                                            {ranges.length > 0 ? rangeEditor(ranges, (r) => setRanges(day, r), label) : <p className="pt-2 text-sm text-muted-foreground">Not bookable</p>}
                                            {errors[`availability.weekly.${day}`] && <p className="text-sm text-destructive">{errors[`availability.weekly.${day}`]}</p>}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">Date overrides</h3>
                        <p className="text-sm text-muted-foreground">Holidays, days off or extra hours on one date.</p>
                        {Object.entries(data.availability.overrides).map(([date, ranges]) => (
                            <div key={date} className="flex flex-wrap items-start gap-4 rounded-xl border p-3">
                                <p className="w-32 pt-2 text-sm font-medium">{date}</p>
                                <div className="flex-1">
                                    {ranges.length > 0 ? rangeEditor(ranges, (r) => setOverride(date, r), date) : (
                                        <div className="flex items-center gap-2 pt-1 text-sm text-muted-foreground">
                                            Day off <Button type="button" variant="ghost" size="sm" onClick={() => setOverride(date, [['09:00', '17:00']])}>Set hours instead</Button>
                                        </div>
                                    )}
                                </div>
                                <Button type="button" variant="ghost" size="icon" aria-label={`Remove override ${date}`} onClick={() => setOverride(date, null)}><Trash2 className="h-4 w-4" /></Button>
                            </div>
                        ))}
                        <div className="flex items-center gap-2">
                            <Input type="date" aria-label="Override date" className="w-44" value={overrideDate} onChange={(e) => setOverrideDate(e.target.value)} />
                            <Button type="button" variant="outline" size="sm" disabled={!overrideDate} onClick={() => { setOverride(overrideDate, []); setOverrideDate(''); }}>Add day off</Button>
                        </div>
                        {errors['availability.overrides'] && <p className="text-sm text-destructive">{errors['availability.overrides']}</p>}
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">Rules</h3>
                        <div className="grid gap-4 sm:grid-cols-3">
                            {([
                                ['step_minutes', 'Start times every (min)', 5, 240],
                                ['buffer_before', 'Free before (min)', 0, 240],
                                ['buffer_after', 'Free after (min)', 0, 240],
                                ['min_notice_minutes', 'Minimum notice (min)', 0, 43200],
                                ['horizon_days', 'Bookable up to (days ahead)', 1, 365],
                            ] as const).map(([key, label, min, max]) => (
                                <div key={key} className="space-y-1.5">
                                    <Label htmlFor={`rule-${key}`}>{label}</Label>
                                    <Input id={`rule-${key}`} type="number" min={min} max={max} value={data.rules[key]} onChange={(e) => setRule(key, e.target.value)} />
                                    {errors[`rules.${key}`] && <p className="text-sm text-destructive">{errors[`rules.${key}`]}</p>}
                                </div>
                            ))}
                            <div className="space-y-1.5">
                                <Label htmlFor="rule-daily_max">Max bookings a day</Label>
                                <Input id="rule-daily_max" type="number" min={1} max={100} placeholder="No limit" value={data.rules.daily_max ?? ''} onChange={(e) => setRule('daily_max', e.target.value)} />
                                {errors['rules.daily_max'] && <p className="text-sm text-destructive">{errors['rules.daily_max']}</p>}
                            </div>
                        </div>
                    </section>

                    <section className="space-y-3">
                        <h3 className="font-medium">Questions</h3>
                        <p className="text-sm text-muted-foreground">Name and email are always asked.</p>
                        {data.questions.map((q, i) => (
                            <div key={q.id || i} className="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_9rem_auto_auto]">
                                <Input aria-label="Question" placeholder="Question" value={q.label} onChange={(e) => setQuestion(i, { label: e.target.value })} />
                                <select aria-label="Answer type" className={select} value={q.type} onChange={(e) => setQuestion(i, { type: e.target.value as BookingQuestion['type'] })}>
                                    <option value="text">Short text</option>
                                    <option value="textarea">Long text</option>
                                    <option value="phone">Phone</option>
                                    <option value="choice">Choice</option>
                                </select>
                                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={q.required} onChange={(e) => setQuestion(i, { required: e.target.checked })} />Required</label>
                                <Button type="button" variant="ghost" size="icon" aria-label="Remove question" onClick={() => set('questions', data.questions.filter((_, j) => j !== i))}><Trash2 className="h-4 w-4" /></Button>
                                {q.type === 'choice' && (
                                    <Input className="sm:col-span-4" aria-label="Options, comma separated" placeholder="Options, comma separated" value={q.options.join(', ')} onChange={(e) => setQuestion(i, { options: e.target.value.split(',').map((o) => o.trimStart()) })} />
                                )}
                                {(errors[`questions.${i}.label`] || errors[`questions.${i}.options`]) && <p className="text-sm text-destructive sm:col-span-4">{errors[`questions.${i}.label`] ?? errors[`questions.${i}.options`]}</p>}
                            </div>
                        ))}
                        <Button type="button" variant="outline" size="sm" disabled={data.questions.length >= 10} onClick={() => set('questions', [...data.questions, { id: '', label: '', type: 'text', required: false, options: [] }])}>
                            <Plus className="mr-1 h-3.5 w-3.5" />Add question
                        </Button>
                    </section>

                    <section className="space-y-2">
                        <div className="flex items-center gap-2">
                            <h3 className="font-medium">Next free times</h3>
                            <Button type="button" variant="ghost" size="sm" onClick={loadPreview}>Check (saved settings)</Button>
                        </div>
                        {preview && (
                            <>
                                {preview.unchecked.length > 0 && <p className="text-sm text-amber-600 dark:text-amber-400">⚠ Couldn’t check {preview.unchecked.join(', ')}: bookings may overlap its events.</p>}
                                <p className="text-sm text-muted-foreground" data-testid="type-preview">
                                    {preview.slots.length ? preview.slots.slice(0, 8).map((s) => `${whenLabel(s, s, browserTimezone()).split(',')[0]} ${timeLabel(s, browserTimezone())}`).join(' · ') : 'No free times in the next two weeks.'}
                                </p>
                            </>
                        )}
                    </section>
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="ghost" onClick={onClose}>Cancel</Button>
                    <Button onClick={save} disabled={saving}>{saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
