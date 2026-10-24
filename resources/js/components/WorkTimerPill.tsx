import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LogActivityPicker, useLogActivities } from '@/components/billing/LogActivityPicker';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { formatDuration } from '@/lib/duration';
import { localToday } from '@/lib/timesheet';
import type { PageProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Square, Timer } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { toast } from 'sonner';

function clock(ms: number): string {
    const total = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/**
 * The running focus timer (BRAI-74) in the top bar, on every page. Time is derived from the
 * server's `started_at`, so it is the same in every tab and survives reloads. Stopping opens
 * a review before anything is logged.
 */
export default function WorkTimerPill() {
    const timer = usePage<PageProps>().props.activeTimer;
    const [now, setNow] = useState(() => Date.now());
    const [open, setOpen] = useState(false);
    const [review, setReview] = useState(false);
    const [confirmDiscard, setConfirmDiscard] = useState(false);
    const [time, setTime] = useState('');
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [activityId, setActivityId] = useState('');
    const [spentOn, setSpentOn] = useState(localToday);
    const [fieldErrors, setFieldErrors] = useState<{ activity_id?: string; spent_on?: string }>({});
    const [busy, setBusy] = useState(false);
    // v1.38.0: what you worked on (activity) and the day, asked when you stop
    const { options, loading: activitiesLoading } = useLogActivities(timer?.item?.workspace, timer?.item?.id, review);
    const activities = options?.activities ?? [];
    const activityRequired = options?.required ?? false;

    useEffect(() => {
        if (review && options?.default_activity_id && !activityId) setActivityId(String(options.default_activity_id));
    }, [review, options]); // eslint-disable-line react-hooks/exhaustive-deps
    // Count from the server's elapsed time, so a wrong device clock can't skew what gets logged.
    const [base, setBase] = useState(0);

    useEffect(() => {
        if (!timer) return;
        setBase(Date.now() - timer.elapsed_seconds * 1000);
        setNow(Date.now());
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, [timer?.id, timer?.elapsed_seconds]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!timer) return null;
    const elapsed = base ? now - base : timer.elapsed_seconds * 1000;
    const minutes = Math.max(1, Math.round(elapsed / 60000));

    const openReview = () => {
        setTime(formatDuration(minutes));
        setNote('');
        setError(null);
        setFieldErrors({});
        setActivityId('');
        setSpentOn(localToday());
        setOpen(false);
        setReview(true);
    };

    const stop = (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        router.post(route('work-timer.stop'), { timer_id: timer.id, time, note, activity_id: activityId || null, spent_on: spentOn || null }, {
            preserveScroll: true,
            onSuccess: (page) => {
                setReview(false);
                const message = (page.props as PageProps).flash?.success;
                toast.success(message ?? 'Work logged.');
            },
            onError: (errors) => {
                setError(errors.time ?? null);
                setFieldErrors({ activity_id: errors.activity_id, spent_on: errors.spent_on });
                if (errors.timer) {
                    setReview(false);
                    toast.error(errors.timer);
                }
            },
            onFinish: () => setBusy(false),
        });
    };

    const discard = () => {
        router.delete(route('work-timer.discard'), {
            data: { timer_id: timer.id },
            preserveScroll: true,
            onSuccess: () => {
                setConfirmDiscard(false);
                setReview(false);
                toast('Timer discarded — nothing was logged.');
            },
            onError: () => toast.error('Could not discard the timer.'),
        });
    };

    return (
        <>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        aria-label={`Timer running on ${timer.item?.key ?? 'an issue'}: ${clock(elapsed)}`}
                        className="inline-flex h-8 items-center gap-1.5 rounded-full border border-brand-green/40 bg-brand-green/10 px-2.5 text-xs font-semibold text-brand-green transition-colors hover:bg-brand-green/15"
                    >
                        <span className="relative flex size-2">
                            <span className="absolute inline-flex size-full animate-ping rounded-full bg-brand-green opacity-60 motion-reduce:hidden" />
                            <span className="relative inline-flex size-2 rounded-full bg-brand-green" />
                        </span>
                        <span className="font-mono tabular-nums">{clock(elapsed)}</span>
                        {timer.item && <span className="hidden max-w-[7rem] truncate font-mono text-[11px] opacity-80 sm:inline">{timer.item.key}</span>}
                    </button>
                </PopoverTrigger>
                <PopoverContent align="end" className="w-72 p-3">
                    <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><Timer className="size-3.5" /> Focus timer</p>
                    {timer.item ? (
                        <p className="mt-1.5 text-sm">
                            <span className="font-mono text-xs text-muted-foreground">{timer.item.key}</span>{' '}
                            {timer.item.url ? <Link href={timer.item.url} className="font-medium hover:text-primary hover:underline">{timer.item.title}</Link> : timer.item.title}
                        </p>
                    ) : (
                        <p className="mt-1.5 text-sm text-muted-foreground">The issue was deleted.</p>
                    )}
                    <p className="mt-2 font-mono text-2xl font-semibold tabular-nums">{clock(elapsed)}</p>
                    <Button size="sm" className="mt-3 h-8 w-full" onClick={openReview}>
                        <Square className="mr-1.5 size-3.5 fill-current" /> Stop{timer.item ? ' & log' : ''}
                    </Button>
                </PopoverContent>
            </Popover>

            <Dialog open={review} onOpenChange={setReview}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Log your time</DialogTitle>
                        <DialogDescription>
                            {timer.item ? <>On <span className="font-mono">{timer.item.key}</span> · {timer.item.title}</> : 'Check the time before it is logged.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={stop} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="timer-time">Time worked</Label>
                            <Input id="timer-time" value={time} onChange={(e) => setTime(e.target.value)} autoFocus aria-invalid={!!error} aria-describedby="timer-time-hint" />
                            <p id="timer-time-hint" className={error ? 'text-xs text-destructive' : 'text-xs text-muted-foreground'}>
                                {error ?? `The timer ran ${formatDuration(minutes)}. Change it if you took a break — e.g. 45m, 1h 30m.`}
                            </p>
                        </div>
                        {activities.length === 0 && fieldErrors.activity_id && <p className="text-xs text-destructive">{fieldErrors.activity_id}</p>}
                        {activities.length > 0 && (
                            <div className="space-y-1.5">
                                <Label htmlFor="timer-activity">Activity{activityRequired ? '' : <span className="font-normal text-muted-foreground"> (optional)</span>}</Label>
                                <LogActivityPicker id="timer-activity" activities={activities} value={activityId} onChange={setActivityId} required={activityRequired} invalid={!!fieldErrors.activity_id} />
                                {fieldErrors.activity_id && <p className="text-xs text-destructive">{fieldErrors.activity_id}</p>}
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label htmlFor="timer-date">Date</Label>
                            <Input id="timer-date" type="date" value={spentOn} onChange={(e) => setSpentOn(e.target.value)} max={localToday()} aria-invalid={!!fieldErrors.spent_on || undefined} />
                            {fieldErrors.spent_on && <p className="text-xs text-destructive">{fieldErrors.spent_on}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="timer-note">Note <span className="font-normal text-muted-foreground">(optional)</span></Label>
                            <Input id="timer-note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} placeholder="What did you do?" />
                        </div>
                        <DialogFooter className="gap-2 sm:justify-between">
                            <Button type="button" variant="ghost" className="text-destructive hover:text-destructive" onClick={() => setConfirmDiscard(true)}>Discard</Button>
                            <Button type="submit" disabled={busy || activitiesLoading || !time.trim() || !timer.item || (activityRequired && !activityId)}>Log time</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={confirmDiscard} onOpenChange={setConfirmDiscard}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Discard {formatDuration(minutes)} of tracked time?</AlertDialogTitle>
                        <AlertDialogDescription>The timer stops and nothing is logged. This can’t be undone.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep timer</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive hover:bg-destructive/90" onClick={discard}>Discard</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
