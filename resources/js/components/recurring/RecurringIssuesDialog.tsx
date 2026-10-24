import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { AlertTriangle, Loader2, Pause, Pencil, Play, Plus, Repeat, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter,
    AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { cn } from '@/lib/utils';
import type { Board, Project, RecurringIssueFields, RecurringIssueOptions, RecurringIssueTemplate, Workspace } from '@/types';
import { RecurringIssueForm } from './RecurringIssueForm';
import { RecurringIssueDraft, boardParams, emptyDraft, errorMessage, formatDate } from './recurrence';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspace: Workspace;
    project: Project;
    board: Board;
    /** "Make this recurring": open straight on a new template filled from an issue. */
    prefill?: Partial<RecurringIssueFields> | null;
}

type Mode = { view: 'list' } | { view: 'form'; template: RecurringIssueTemplate | null; draft: RecurringIssueDraft };

/** A saved template as form values (schedule fields of other frequencies get their defaults back). */
function draftOf(t: RecurringIssueTemplate): RecurringIssueDraft {
    const defaults = emptyDraft();
    const startDay = Number(t.start_date.slice(8, 10));
    return {
        ...defaults,
        title: t.title,
        description: t.description,
        item_type_id: t.item_type_id,
        priority_id: t.priority_id,
        assigned_to: t.assigned_to,
        label_ids: t.label_ids,
        column_id: t.column_id,
        due_in_days: t.due_in_days,
        frequency: t.frequency,
        interval: t.interval,
        weekdays: t.weekdays.length ? t.weekdays : defaults.weekdays,
        monthly_mode: t.monthly_mode ?? 'day',
        month_day: t.month_day ?? startDay,
        month_week: t.month_week ?? 1,
        month_weekday: t.month_weekday ?? defaults.month_weekday,
        start_date: t.start_date,
        end_date: t.end_date,
        max_occurrences: t.max_occurrences,
    };
}

/** Ids the form can't offer (a subtask's type, a member who left, another board's column) are dropped. */
function withinOptions(draft: RecurringIssueDraft, options: RecurringIssueOptions): RecurringIssueDraft {
    const keep = (id: number | null, items: { id: number }[]) => (id !== null && items.some((i) => i.id === id) ? id : null);
    return {
        ...draft,
        item_type_id: keep(draft.item_type_id, options.types),
        priority_id: keep(draft.priority_id, options.priorities),
        assigned_to: keep(draft.assigned_to, options.members),
        column_id: keep(draft.column_id, options.columns),
        label_ids: draft.label_ids.filter((id) => options.labels.some((l) => l.id === id)),
    };
}

/**
 * The board's recurring issues (BRAI-123): list with next date, pause / resume, edit, delete, and the
 * create / edit form. Opened from the board header's menu and an issue's "Make this recurring".
 */
export function RecurringIssuesDialog({ open, onOpenChange, workspace, project, board, prefill = null }: Props) {
    const params = boardParams(workspace, project, board);
    const [templates, setTemplates] = useState<RecurringIssueTemplate[] | null>(null);
    const [options, setOptions] = useState<RecurringIssueOptions | null>(null);
    const [timezone, setTimezone] = useState('');
    const [mode, setMode] = useState<Mode>({ view: 'list' });
    const [saving, setSaving] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<RecurringIssueTemplate | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    const load = useCallback(() => {
        setLoadError(null);
        axios.get(route('recurring-issues.index', params))
            .then(({ data }) => {
                setTemplates(data.templates);
                setOptions(data.options);
                setTimezone(data.timezone);
            })
            .catch((error) => setLoadError(errorMessage(error, 'Could not load the recurring issues.')));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [params.join('/')]);

    useEffect(() => {
        if (!open) return;
        load();
        setMode(prefill ? { view: 'form', template: null, draft: emptyDraft(prefill) } : { view: 'list' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const replace = (template: RecurringIssueTemplate) =>
        setTemplates((list) => {
            const rest = (list ?? []).filter((t) => t.id !== template.id);
            return [...rest, template].sort((a, b) => a.title.localeCompare(b.title) || a.id - b.id);
        });

    const save = (draft: RecurringIssueDraft) => {
        if (mode.view !== 'form') return;
        setSaving(true);
        const request = mode.template
            ? axios.put(route('recurring-issues.update', [...params, mode.template.id]), draft)
            : axios.post(route('recurring-issues.store', params), draft);
        request
            .then(({ data }) => {
                replace(data.template);
                toast.success(mode.template ? 'Recurring issue saved.' : 'Recurring issue created.');
                setMode({ view: 'list' });
            })
            .catch((error) => toast.error(errorMessage(error, 'Could not save the recurring issue.')))
            .finally(() => setSaving(false));
    };

    const toggle = (template: RecurringIssueTemplate) => {
        setBusyId(template.id);
        axios.post(route(template.paused ? 'recurring-issues.resume' : 'recurring-issues.pause', [...params, template.id]))
            .then(({ data }) => {
                replace(data.template);
                toast.success(template.paused ? 'Recurring issue resumed.' : 'Recurring issue paused.');
            })
            .catch((error) => toast.error(errorMessage(error, 'Could not change the recurring issue.')))
            .finally(() => setBusyId(null));
    };

    const destroy = (template: RecurringIssueTemplate) => {
        setBusyId(template.id);
        axios.delete(route('recurring-issues.destroy', [...params, template.id]))
            .then(() => {
                setTemplates((list) => (list ?? []).filter((t) => t.id !== template.id));
                toast.success('Recurring issue deleted.');
            })
            .catch((error) => toast.error(errorMessage(error, 'Could not delete the recurring issue.')))
            .finally(() => { setBusyId(null); setDeleting(null); });
    };

    const closeForm = () => (prefill && mode.view === 'form' && !mode.template ? onOpenChange(false) : setMode({ view: 'list' }));

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Repeat className="size-4 text-muted-foreground" />
                            {mode.view === 'form' ? (mode.template ? 'Edit recurring issue' : 'New recurring issue') : 'Recurring issues'}
                        </DialogTitle>
                        <DialogDescription>
                            {mode.view === 'form'
                                ? `Creates an issue on ${board.name} on every date of the schedule.`
                                : `Issues created automatically on ${board.name}, on a schedule.`}
                        </DialogDescription>
                    </DialogHeader>

                    {loadError ? (
                        <p className="text-sm text-destructive" role="alert">{loadError}</p>
                    ) : !options || templates === null ? (
                        <div className="flex justify-center py-10"><Loader2 className="size-5 animate-spin text-muted-foreground" /></div>
                    ) : mode.view === 'form' ? (
                        <RecurringIssueForm
                            key={mode.template?.id ?? 'new'}
                            params={params}
                            options={options}
                            timezone={mode.template?.timezone ?? timezone}
                            initial={withinOptions(mode.draft, options)}
                            templateId={mode.template?.id}
                            saving={saving}
                            onSubmit={save}
                            onCancel={closeForm}
                        />
                    ) : (
                        <div className="flex flex-col gap-3">
                            {templates.length === 0 ? (
                                <div className="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                                    No recurring issues yet. Create one for standups, reports or checks that come back on a schedule.
                                </div>
                            ) : (
                                <ul className="flex flex-col divide-y rounded-lg border" aria-label="Recurring issues">
                                    {templates.map((t) => (
                                        <li key={t.id} className={cn('flex items-start gap-3 px-3 py-2.5', t.paused && 'bg-muted/40')}>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="truncate text-sm font-medium">{t.title}</span>
                                                    {t.paused && <span className="rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-400">Paused</span>}
                                                    {t.finished && <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">Ended</span>}
                                                </div>
                                                <p className="text-[12.5px] text-muted-foreground">
                                                    {t.summary}
                                                    {t.next_occurrence_date && <> · Next: {formatDate(t.next_occurrence_date)}</>}
                                                    {t.occurrences_count > 0 && <> · {t.occurrences_count} created</>}
                                                </p>
                                                {t.last_error && (
                                                    <p className="mt-0.5 flex items-center gap-1 text-[12px] text-amber-700 dark:text-amber-400">
                                                        <AlertTriangle className="size-3.5 shrink-0" />{t.last_error}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex shrink-0 items-center gap-0.5">
                                                <Button variant="ghost" size="icon" className="size-8" aria-label={`Edit ${t.title}`} title="Edit"
                                                    onClick={() => setMode({ view: 'form', template: t, draft: draftOf(t) })}>
                                                    <Pencil className="size-3.5" />
                                                </Button>
                                                <Button variant="ghost" size="icon" className="size-8" disabled={busyId === t.id}
                                                    aria-label={`${t.paused ? 'Resume' : 'Pause'} ${t.title}`} title={t.paused ? 'Resume' : 'Pause'}
                                                    onClick={() => toggle(t)}>
                                                    {t.paused ? <Play className="size-3.5" /> : <Pause className="size-3.5" />}
                                                </Button>
                                                <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" disabled={busyId === t.id}
                                                    aria-label={`Delete ${t.title}`} title="Delete" onClick={() => setDeleting(t)}>
                                                    <Trash2 className="size-3.5" />
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="flex justify-end">
                                <Button onClick={() => setMode({ view: 'form', template: null, draft: emptyDraft() })}>
                                    <Plus className="mr-1.5 size-4" />New recurring issue
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <AlertDialog open={deleting !== null} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete “{deleting?.title}”?</AlertDialogTitle>
                        <AlertDialogDescription>
                            No more issues will be created. The issues it already created stay on the board.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={() => deleting && destroy(deleting)}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
