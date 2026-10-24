import { ActivityDot } from '@/components/billing/LogActivityPicker';
import TimeEntryDialog from '@/components/billing/TimeEntryDialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
    AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatHoursMinutes, utcDay, weekDays } from '@/lib/timesheet';
import { cn } from '@/lib/utils';
import type { TimeLogActivity, TimesheetEntry, TimesheetProject, TimesheetWeek } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Clock, Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    workspace: { id: number; name: string; slug: string };
    week: TimesheetWeek;
    entries: TimesheetEntry[];
    projects: TimesheetProject[];
    activities: TimeLogActivity[];
    activityRequired: boolean;
    defaultActivityId: number | null;
}

const dayLabel = (day: string) => utcDay(day).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short', timeZone: 'UTC' });
const shortDay = (day: string, withYear = false) => utcDay(day).toLocaleDateString(undefined, { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}), timeZone: 'UTC' });

/**
 * My timesheet (v1.38.0, BRAI-99): your own time in this workspace, one ISO week at a time,
 * with day and week totals. Add, edit and delete entries; billed ones are locked. No prices here.
 */
export default function TimesheetIndex({ workspace, week, entries, projects, activities, activityRequired, defaultActivityId }: Props) {
    const [dialog, setDialog] = useState<{ entry: TimesheetEntry | null; day: string } | null>(null);
    const [deleting, setDeleting] = useState<TimesheetEntry | null>(null);

    const days = useMemo(() => weekDays(week.start), [week.start]);
    const byDay = useMemo(() => {
        const map = new Map<string, TimesheetEntry[]>();
        for (const entry of entries) map.set(entry.spent_on, [...(map.get(entry.spent_on) ?? []), entry]);
        return map;
    }, [entries]);
    const weekTotal = entries.reduce((sum, e) => sum + e.minutes, 0);
    const billableTotal = entries.filter((e) => e.billable).reduce((sum, e) => sum + e.minutes, 0);
    const go = (start: string) => router.get(route('timesheet.index', { workspace: workspace.slug, week: start }), {}, { preserveScroll: true, preserveState: false });
    const newEntryDay = week.today >= week.start && week.today <= week.end ? week.today : week.start;

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('timesheet.entries.destroy', { workspace: workspace.slug, timeEntry: deleting.id }), {
            preserveScroll: true,
            onSuccess: () => toast.success('Time entry deleted.'),
            onError: (errors) => toast.error(errors.entry ?? 'Could not delete the entry.'),
            onFinish: () => setDeleting(null),
        });
    };

    return (
        <AppLayout>
            <Head title="Timesheet" />
            <div className="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6">
                <div className="mb-4 flex flex-wrap items-start gap-3">
                    <div className="min-w-0 flex-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold"><Clock className="size-5" /> My timesheet</h1>
                        <p className="text-sm text-muted-foreground">Your time in {workspace.name}. Only you and the workspace admins see these entries.</p>
                    </div>
                    <Button onClick={() => setDialog({ entry: null, day: newEntryDay })} disabled={projects.length === 0}>
                        <Plus className="mr-1.5 size-4" /> Log time
                    </Button>
                </div>

                {projects.length === 0 && (
                    <p className="mb-4 rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                        You can’t log time in this workspace yet: you need to be an editor in one of its projects.
                    </p>
                )}

                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" aria-label="Previous week" onClick={() => go(week.previous)}><ChevronLeft className="size-4" /></Button>
                        <Button variant="outline" size="sm" onClick={() => go(week.current)} disabled={week.start === week.current}>This week</Button>
                        <Button variant="outline" size="icon" aria-label="Next week" onClick={() => go(week.next)}><ChevronRight className="size-4" /></Button>
                    </div>
                    <span className="text-sm font-medium" data-testid="timesheet-week">
                        Week {week.number}
                        <span className="ml-1.5 font-normal text-muted-foreground">{shortDay(week.start)} – {shortDay(week.end, true)}</span>
                    </span>
                    <div className="ml-auto text-right text-sm tabular-nums" data-testid="timesheet-week-total">
                        <span className="font-semibold">{formatHoursMinutes(weekTotal)}</span>
                        <span className="text-muted-foreground"> this week</span>
                        {billableTotal !== weekTotal && <span className="block text-xs text-muted-foreground">{formatHoursMinutes(billableTotal)} billable</span>}
                    </div>
                </div>

                <div className="divide-y overflow-hidden rounded-lg border bg-card">
                    {days.map((day) => {
                        const list = byDay.get(day) ?? [];
                        const total = list.reduce((sum, e) => sum + e.minutes, 0);
                        const isToday = day === week.today;
                        return (
                            <section key={day} aria-label={dayLabel(day)} data-testid={`timesheet-day-${day}`}>
                                <div className={cn('flex items-center gap-2 px-3 py-2', list.length > 0 ? 'bg-muted/40' : '', isToday && 'bg-sky-50 dark:bg-sky-950/30')}>
                                    <h2 className={cn('text-sm font-medium', list.length === 0 && 'text-muted-foreground')}>{dayLabel(day)}</h2>
                                    {isToday && <Badge className="h-4 px-1 text-[10px]">Today</Badge>}
                                    <span className="ml-auto text-sm tabular-nums text-muted-foreground" data-testid="timesheet-day-total">{total > 0 ? formatHoursMinutes(total) : ''}</span>
                                    {projects.length > 0 && (
                                        <Button variant="ghost" size="icon" className="size-7" aria-label={`Log time on ${dayLabel(day)}`} onClick={() => setDialog({ entry: null, day })}>
                                            <Plus className="size-4" />
                                        </Button>
                                    )}
                                </div>
                                {list.length > 0 && (
                                    <ul className="divide-y">
                                        {list.map((entry) => <EntryRow key={entry.id} entry={entry} onEdit={() => setDialog({ entry, day })} onDelete={() => setDeleting(entry)} />)}
                                    </ul>
                                )}
                            </section>
                        );
                    })}
                </div>
            </div>

            <TimeEntryDialog
                open={dialog !== null}
                onOpenChange={(open) => { if (!open) setDialog(null); }}
                workspaceSlug={workspace.slug}
                entry={dialog?.entry ?? null}
                day={dialog?.day ?? newEntryDay}
                projects={projects}
                activities={activities}
                activityRequired={activityRequired}
                defaultActivityId={defaultActivityId}
            />

            <AlertDialog open={deleting !== null} onOpenChange={(open) => { if (!open) setDeleting(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete {deleting ? formatHoursMinutes(deleting.minutes) : ''} of logged time?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deleting?.item?.key ? `It is also taken off ${deleting.item.key}'s logged time. ` : ''}This can’t be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep it</AlertDialogCancel>
                        <AlertDialogAction className="bg-destructive hover:bg-destructive/90" onClick={destroy}>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}

function EntryRow({ entry, onEdit, onDelete }: { entry: TimesheetEntry; onEdit: () => void; onDelete: () => void }) {
    const title = entry.item ? (
        <>
            <span className="mr-1.5 font-mono text-xs text-muted-foreground">{entry.item.key}</span>
            {entry.item.url ? (
                <Link href={entry.item.url} className="font-medium hover:text-primary hover:underline">{entry.item.title}</Link>
            ) : (
                <span className="font-medium">{entry.item.title}</span>
            )}
        </>
    ) : (
        <span className="font-medium">{entry.project?.name ?? 'Project'} <span className="font-normal text-muted-foreground">· no issue</span></span>
    );

    return (
        <li className="flex items-start gap-3 px-3 py-2.5" data-testid="timesheet-entry">
            <ActivityDot color={entry.activity?.color} className="mt-1.5" />
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm">{title}</div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                    {entry.item && entry.project && <span>{entry.project.name}</span>}
                    <span className={cn(!entry.activity && 'italic')}>{entry.activity?.name ?? 'No activity'}</span>
                    {!entry.billable && <span>not billable</span>}
                    {entry.source === 'timer' && <span>timer</span>}
                    {entry.source === 'legacy' && <span title="Logged before the timesheet existed">earlier log</span>}
                    {entry.note && <span className="w-full truncate text-foreground/80 sm:w-auto">{entry.note}</span>}
                </div>
            </div>
            <span className="shrink-0 pt-0.5 text-sm font-medium tabular-nums">{formatHoursMinutes(entry.minutes)}</span>
            <div className="flex shrink-0 items-center">
                {entry.locked ? (
                    <span className="flex size-8 items-center justify-center text-muted-foreground" title="Billed on a finalized report — locked" aria-label="Locked: billed on a finalized report">
                        <Lock className="size-4" />
                    </span>
                ) : (
                    <>
                        <Button variant="ghost" size="icon" className="size-8" aria-label="Edit time entry" onClick={onEdit}><Pencil className="size-4" /></Button>
                        {entry.source !== 'legacy' && (
                            <Button variant="ghost" size="icon" className="size-8 text-muted-foreground hover:text-destructive" aria-label="Delete time entry" onClick={onDelete}><Trash2 className="size-4" /></Button>
                        )}
                    </>
                )}
            </div>
        </li>
    );
}
