import { LogActivityPicker, useLogActivities } from '@/components/billing/LogActivityPicker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { localToday } from '@/lib/timesheet';
import { router } from '@inertiajs/react';
import { Clock, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    workspaceSlug: string;
    projectSlug: string;
    boardSlug: string;
    itemId: number;
}

/**
 * "Log work" on an issue (Jira-style durations) with the activity and the work day (v1.38.0,
 * BRAI-99). The activity is required once the workspace has activities and is preselected from
 * the ticket type's default, then the one you used last. Creates one time entry on the server.
 */
export default function LogWorkPopover({ workspaceSlug, projectSlug, boardSlug, itemId }: Props) {
    const [open, setOpen] = useState(false);
    const [time, setTime] = useState('');
    const [note, setNote] = useState('');
    const [activityId, setActivityId] = useState('');
    const [spentOn, setSpentOn] = useState(localToday);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const { options, loading, failed } = useLogActivities(workspaceSlug, itemId, open);
    const activities = options?.activities ?? [];
    const required = options?.required ?? false;

    // preselect once the options are there (the user's own choice wins)
    useEffect(() => {
        if (open && options?.default_activity_id && !activityId) setActivityId(String(options.default_activity_id));
    }, [open, options]); // eslint-disable-line react-hooks/exhaustive-deps

    const reset = () => {
        setTime('');
        setNote('');
        setActivityId('');
        setSpentOn(localToday());
        setErrors({});
    };

    const submit = () => {
        if (!time.trim()) return;
        setBusy(true);
        setErrors({});
        router.post(route('boards.items.work-logs.store', [workspaceSlug, projectSlug, boardSlug, itemId]), {
            time: time.trim(),
            note: note.trim() || null,
            activity_id: activityId || null,
            spent_on: spentOn || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                reset();
            },
            onError: (errs) => setErrors(Object.keys(errs).length ? errs : { time: 'Could not log work.' }),
            onFinish: () => setBusy(false),
        });
    };

    const generalError = errors.time ?? errors.note ?? (activities.length === 0 ? errors.activity_id : undefined);

    return (
        <Popover open={open} onOpenChange={(next) => { setOpen(next); if (!next) setErrors({}); }}>
            <PopoverTrigger asChild>
                <button type="button" className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors">
                    <Clock className="size-3.5" /> Log work
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-3" align="start">
                <form className="space-y-2.5" onSubmit={(e) => { e.preventDefault(); submit(); }}>
                    <div className="space-y-1">
                        <Label htmlFor="log-work-time" className="text-xs">Time spent</Label>
                        <Input id="log-work-time" value={time} onChange={(e) => setTime(e.target.value)} placeholder="e.g. 45m, 1h 30m, 1d" autoFocus aria-invalid={!!errors.time || undefined} />
                        <p className="text-[11px] text-muted-foreground">1d = 8h · 1w = 5d</p>
                    </div>
                    {failed && <p className="text-xs text-muted-foreground">Could not load the activities.</p>}
                    {activities.length > 0 && (
                        <div className="space-y-1">
                            <Label htmlFor="log-work-activity" className="text-xs">Activity{required ? '' : ' (optional)'}</Label>
                            <LogActivityPicker id="log-work-activity" activities={activities} value={activityId} onChange={setActivityId} required={required} invalid={!!errors.activity_id} />
                            {errors.activity_id && <p className="text-xs text-destructive">{errors.activity_id}</p>}
                        </div>
                    )}
                    <div className="space-y-1">
                        <Label htmlFor="log-work-date" className="text-xs">Date</Label>
                        <Input id="log-work-date" type="date" value={spentOn} onChange={(e) => setSpentOn(e.target.value)} max={localToday()} aria-invalid={!!errors.spent_on || undefined} />
                        {errors.spent_on && <p className="text-xs text-destructive">{errors.spent_on}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="log-work-note" className="text-xs">What did you work on? (optional)</Label>
                        <Input id="log-work-note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
                    </div>
                    {generalError && <p className="text-xs text-destructive">{generalError}</p>}
                    <div className="flex justify-end gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" size="sm" disabled={busy || loading || !time.trim() || (required && !activityId)}>
                            {busy && <Loader2 className="size-3.5 mr-1.5 animate-spin" />}
                            Log
                        </Button>
                    </div>
                </form>
            </PopoverContent>
        </Popover>
    );
}
