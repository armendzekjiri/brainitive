import { LogActivityPicker } from '@/components/billing/LogActivityPicker';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatHoursMinutes, localToday } from '@/lib/timesheet';
import { cn } from '@/lib/utils';
import type { TimeLogActivity, TimesheetEntry, TimesheetIssueOption, TimesheetProject } from '@/types';
import { router } from '@inertiajs/react';
import { Loader2, Search, X } from 'lucide-react';
import { useEffect, useId, useState, type FormEvent, type KeyboardEvent } from 'react';
import { toast } from 'sonner';

type PickedItem = TimesheetIssueOption;

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    /** null = a new entry */
    entry: TimesheetEntry | null;
    /** Day of a new entry. */
    day: string;
    projects: TimesheetProject[];
    activities: TimeLogActivity[];
    activityRequired: boolean;
    defaultActivityId: number | null;
}

/**
 * Add or edit one of your time entries (v1.38.0, BRAI-99): day, project, optional issue,
 * duration ("1h 30m"), activity (no prices), billable and note. Entries logged before the
 * timesheet existed (`legacy`) keep their issue and minutes.
 */
export default function TimeEntryDialog({ open, onOpenChange, workspaceSlug, entry, day, projects, activities, activityRequired, defaultActivityId }: Props) {
    const legacy = entry?.source === 'legacy';
    const [spentOn, setSpentOn] = useState(day);
    const [projectId, setProjectId] = useState('');
    const [item, setItem] = useState<PickedItem | null>(null);
    const [time, setTime] = useState('');
    const [activityId, setActivityId] = useState('');
    const [billable, setBillable] = useState(true);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    // an archived activity stays pickable on the entry that already has it
    const current = entry?.activity ?? null;
    const pickable = current && !activities.some((a) => a.id === current.id) ? [...activities, current] : activities;

    useEffect(() => {
        if (!open) return;
        setErrors({});
        if (entry) {
            setSpentOn(entry.spent_on);
            setProjectId(entry.project ? String(entry.project.id) : '');
            setItem(entry.item?.id ? { id: entry.item.id, key: entry.item.key, title: entry.item.title } : null);
            setTime(formatHoursMinutes(entry.minutes));
            setActivityId(entry.activity ? String(entry.activity.id) : '');
            setBillable(entry.billable);
            setNote(entry.note ?? '');
        } else {
            setSpentOn(day);
            setProjectId(projects.length === 1 ? String(projects[0]!.id) : '');
            setItem(null);
            setTime('');
            const preset = activities.find((a) => a.id === defaultActivityId) ?? null;
            setActivityId(preset ? String(preset.id) : '');
            setBillable(preset ? preset.billable : true);
            setNote('');
        }
    }, [open, entry?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const pickActivity = (value: string) => {
        setActivityId(value);
        const activity = pickable.find((a) => String(a.id) === value);
        setBillable(activity ? activity.billable : true); // follows the activity; you can still change it
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setErrors({});
        const data = {
            spent_on: spentOn,
            project_id: projectId ? Number(projectId) : null,
            item_id: item?.id ?? null,
            time: time.trim(),
            activity_id: activityId ? Number(activityId) : null,
            billable,
            note: note.trim() || null,
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                toast.success(entry ? 'Time entry updated.' : `Logged ${data.time}.`);
            },
            onError: (errs: Record<string, string>) => {
                setErrors(errs);
                if (errs.entry) toast.error(errs.entry);
            },
            onFinish: () => setBusy(false),
        };
        if (entry) {
            router.put(route('timesheet.entries.update', { workspace: workspaceSlug, timeEntry: entry.id }), data, options);
        } else {
            router.post(route('timesheet.entries.store', { workspace: workspaceSlug }), data, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{entry ? 'Edit time entry' : 'Log time'}</DialogTitle>
                    <DialogDescription>
                        {legacy ? 'Logged before the timesheet existed: its issue and time stay as they are.' : 'Durations like 45m, 1h 30m or 2h (1d = 8h).'}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="entry-date">Date</Label>
                            <Input id="entry-date" type="date" value={spentOn} max={localToday()} onChange={(e) => setSpentOn(e.target.value)} aria-invalid={!!errors.spent_on || undefined} required />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="entry-time">Time</Label>
                            <Input id="entry-time" value={time} onChange={(e) => setTime(e.target.value)} placeholder="1h 30m" disabled={legacy} aria-invalid={!!errors.time || undefined} />
                        </div>
                        {(errors.spent_on || errors.time) && <p className="col-span-2 text-xs text-destructive">{errors.time ?? errors.spent_on}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="entry-project">Project</Label>
                        <Select value={projectId} onValueChange={(v) => { setProjectId(v); setItem(null); }} disabled={legacy}>
                            <SelectTrigger id="entry-project" aria-invalid={!!errors.project_id || undefined}><SelectValue placeholder="Pick a project" /></SelectTrigger>
                            <SelectContent>
                                {entry?.project && !projects.some((p) => p.id === entry.project!.id) && (
                                    <SelectItem value={String(entry.project.id)}>{entry.project.name}</SelectItem>
                                )}
                                {projects.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        <span className="font-mono text-xs text-muted-foreground">{p.key}</span> {p.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.project_id && <p className="text-xs text-destructive">{errors.project_id}</p>}
                    </div>

                    <IssuePicker workspaceSlug={workspaceSlug} projectId={projectId} value={item} onChange={setItem} disabled={legacy || !projectId} error={errors.item_id} />

                    {(pickable.length > 0 || activityRequired) && (
                        <div className="space-y-1.5">
                            <Label htmlFor="entry-activity">Activity{activityRequired ? '' : <span className="font-normal text-muted-foreground"> (optional)</span>}</Label>
                            <LogActivityPicker id="entry-activity" activities={pickable} value={activityId} onChange={pickActivity} required={activityRequired} invalid={!!errors.activity_id} />
                            {errors.activity_id && <p className="text-xs text-destructive">{errors.activity_id}</p>}
                        </div>
                    )}

                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={billable} onChange={(e) => setBillable(e.target.checked)} className="size-4 rounded border-input accent-primary" />
                        Billable
                    </label>

                    <div className="space-y-1.5">
                        <Label htmlFor="entry-note">Note <span className="font-normal text-muted-foreground">(optional)</span></Label>
                        <Input id="entry-note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} placeholder="What did you do?" />
                        {errors.note && <p className="text-xs text-destructive">{errors.note}</p>}
                    </div>

                    {errors.entry && <p className="text-sm text-destructive">{errors.entry}</p>}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={busy || !time.trim() || !projectId || !spentOn || (activityRequired && !activityId)}>
                            {busy && <Loader2 className="mr-1.5 size-4 animate-spin" />}
                            {entry ? 'Save' : 'Log time'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Optional issue of the project, searched by key or title (`timesheet.items`); none = project time. */
function IssuePicker({ workspaceSlug, projectId, value, onChange, disabled, error }: {
    workspaceSlug: string;
    projectId: string;
    value: PickedItem | null;
    onChange: (item: PickedItem | null) => void;
    disabled: boolean;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const [focused, setFocused] = useState(false);
    const [results, setResults] = useState<PickedItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const listId = useId();
    const searching = !value && !disabled && (focused || query.trim() !== '');

    useEffect(() => { setQuery(''); setResults([]); }, [projectId]);
    useEffect(() => { setActive(0); }, [results]);

    const pick = (r: PickedItem) => { onChange(r); setQuery(''); setFocused(false); };
    // combobox keys: ↓ / ↑ move through the results, Enter picks
    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (!searching || results.length === 0) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : results.length - 1)) % results.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            pick(results[active] ?? results[0]!);
        }
    };

    useEffect(() => {
        if (!searching || !projectId) return;
        let cancelled = false;
        const id = window.setTimeout(() => {
            setLoading(true);
            window.axios
                .get<{ items: PickedItem[] }>(route('timesheet.items', { workspace: workspaceSlug, project_id: projectId, ...(query.trim() ? { q: query.trim() } : {}) }))
                .then(({ data }) => { if (!cancelled) setResults(data.items); })
                .catch(() => { if (!cancelled) setResults([]); })
                .finally(() => { if (!cancelled) setLoading(false); });
        }, 200);
        return () => { cancelled = true; window.clearTimeout(id); };
    }, [searching, projectId, query, workspaceSlug]);

    return (
        <div className="space-y-1.5">
            <Label htmlFor="entry-issue">Issue <span className="font-normal text-muted-foreground">(optional)</span></Label>
            {value ? (
                <div className="flex h-9 items-center gap-2 rounded-md border px-3 text-sm" data-testid="entry-issue-picked">
                    <span className="shrink-0 font-mono text-xs text-muted-foreground">{value.key}</span>
                    <span className="min-w-0 flex-1 truncate">{value.title}</span>
                    {!disabled && (
                        <button type="button" onClick={() => onChange(null)} className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground" aria-label="Remove the issue">
                            <X className="size-4" />
                        </button>
                    )}
                </div>
            ) : (
                <div className="relative">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        id="entry-issue"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onFocus={() => setFocused(true)}
                        onBlur={() => window.setTimeout(() => setFocused(false), 150)}
                        onKeyDown={onKeyDown}
                        role="combobox"
                        aria-expanded={searching}
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-activedescendant={searching && results[active] ? `${listId}-${results[active]!.id}` : undefined}
                        placeholder={disabled ? 'Pick a project first' : 'Search by key or title — or leave empty'}
                        disabled={disabled}
                        className="pl-8"
                        autoComplete="off"
                        aria-invalid={!!error || undefined}
                    />
                </div>
            )}
            {searching && (
                <div id={listId} role="listbox" aria-label="Issues" className="max-h-48 overflow-y-auto rounded-md border bg-popover p-1 shadow-sm">
                    {loading && results.length === 0 ? (
                        <p className="px-2 py-1.5 text-xs text-muted-foreground">Searching…</p>
                    ) : results.length === 0 ? (
                        <p className="px-2 py-1.5 text-xs text-muted-foreground">No issues found — the time is logged on the project.</p>
                    ) : results.map((r, i) => (
                        <button
                            key={r.id}
                            id={`${listId}-${r.id}`}
                            type="button"
                            role="option"
                            tabIndex={-1}
                            aria-selected={i === active}
                            onMouseDown={(e) => e.preventDefault()}
                            onMouseEnter={() => setActive(i)}
                            onClick={() => pick(r)}
                            className={cn('flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm', i === active && 'bg-accent text-accent-foreground')}
                        >
                            <span className="shrink-0 font-mono text-xs text-muted-foreground">{r.key}</span>
                            <span className="min-w-0 truncate">{r.title}</span>
                        </button>
                    ))}
                </div>
            )}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}
