import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { CalendarClock, Copy, ExternalLink, FileText, Loader2, MoreHorizontal, Plus, Trash2, Video } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import ScheduleMeetingDialog from '@/components/meetings/ScheduleMeetingDialog';
import { copyMeetingLink, errorMessage, formatMeetingTime, isPast, safeJoinUrl } from '@/lib/meetings';
import { cn } from '@/lib/utils';
import type { Meeting, MeetingSubject } from '@/types';

interface Props {
    workspace: { slug: string };
    project: { slug: string };
    subject: MeetingSubject;
    /** 'section' = the issue's "Meetings" block; 'strip' = the small row under a doc's title. */
    variant: 'section' | 'strip';
    /** The "Schedule meeting" dialog (opened from the issue's / doc's own menu too). */
    scheduleOpen: boolean;
    onScheduleOpenChange: (open: boolean) => void;
}

/**
 * Meetings of one issue / doc (BRAI-147) with Join, Copy link, Open in calendar, meeting notes
 * and Remove (cancel the calendar event, or only unlink it), plus the schedule dialog.
 */
export default function SubjectMeetings({ workspace, project, subject, variant, scheduleOpen, onScheduleOpenChange }: Props) {
    const [meetings, setMeetings] = useState<Meeting[]>([]);
    const [canSchedule, setCanSchedule] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [removing, setRemoving] = useState<Meeting | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [showPast, setShowPast] = useState(false);

    useEffect(() => {
        setLoaded(false);
        setLoadError(null);
        setMeetings([]);
        const controller = new AbortController();
        axios.get<{ meetings: Meeting[]; can_schedule: boolean }>(route('meetings.index', [workspace.slug, project.slug]), {
            params: { subject_type: subject.type, subject_id: subject.id },
            signal: controller.signal,
        }).then(({ data }) => {
            setMeetings(data.meetings);
            setCanSchedule(data.can_schedule);
            setLoaded(true);
        }).catch((e) => {
            if (axios.isCancel(e)) return;
            setLoadError(errorMessage(e, 'Couldn’t load the meetings.'));
            setLoaded(true);
        });
        return () => controller.abort();
    }, [workspace.slug, project.slug, subject.type, subject.id]);

    const upcoming = meetings.filter((m) => !isPast(m));
    const past = meetings.filter((m) => isPast(m)).reverse();

    const openNotes = useCallback(async (meeting: Meeting) => {
        if (meeting.notes_page) {
            router.visit(meeting.notes_page.url);
            return;
        }
        setBusy(`notes-${meeting.id}`);
        try {
            const { data } = await axios.post<{ url: string; created: boolean }>(route('meetings.notes', [workspace.slug, project.slug, meeting.id]));
            if (data.created) toast.success('Meeting notes created');
            router.visit(data.url);
        } catch (e) {
            toast.error(errorMessage(e, 'Couldn’t create the meeting notes.'));
        } finally {
            setBusy(null);
        }
    }, [workspace.slug, project.slug]);

    const remove = async (meeting: Meeting, cancelEvent: boolean) => {
        setBusy(`remove-${meeting.id}`);
        try {
            await axios.delete(route('meetings.destroy', [workspace.slug, project.slug, meeting.id]), { data: { cancel_event: cancelEvent } });
            setMeetings((list) => list.filter((m) => m.id !== meeting.id));
            toast.success(cancelEvent ? 'Meeting cancelled — the attendees are notified' : 'Meeting removed from here (the event stays in the calendar)');
            setRemoving(null);
        } catch (e) {
            toast.error(errorMessage(e, 'Couldn’t remove the meeting.'));
        } finally {
            setBusy(null);
        }
    };

    const row = (meeting: Meeting, compact: boolean) => {
        const join = safeJoinUrl(meeting.join_url);
        const eventUrl = safeJoinUrl(meeting.event_url);
        const ended = isPast(meeting);
        return (
            <div
                key={meeting.id}
                className={cn('flex min-w-0 items-center gap-2', compact ? 'rounded-full border bg-card py-1 pl-3 pr-1 text-xs' : 'rounded-lg border border-border/60 px-3 py-2')}
                data-testid="meeting-row"
            >
                <Video className={cn('shrink-0', compact ? 'size-3.5' : 'size-4', ended ? 'text-muted-foreground' : 'text-primary')} />
                <div className="min-w-0 flex-1">
                    <p className={cn('truncate font-medium', compact ? 'text-xs' : 'text-sm', ended && 'text-muted-foreground')}>{meeting.title}</p>
                    {!compact && (
                        <p className="truncate text-xs text-muted-foreground">
                            {formatMeetingTime(meeting)}
                            {meeting.conference_label ? ` · ${meeting.conference_label}` : ''}
                            {meeting.organizer ? ` · by ${meeting.organizer.name}` : ''}
                            {meeting.attendees.length > 0 ? ` · ${meeting.attendees.length} invited` : ''}
                        </p>
                    )}
                </div>
                {compact && <span className="hidden shrink-0 text-muted-foreground sm:inline">{formatMeetingTime(meeting)}</span>}
                {join && !ended && (
                    <Button asChild size="sm" className={cn('shrink-0', compact ? 'h-6 rounded-full px-2.5 text-xs' : 'h-7')}>
                        <a href={join} target="_blank" rel="noopener noreferrer">Join</a>
                    </Button>
                )}
                {compact && (meeting.notes_page || meeting.can_create_notes) && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-6 shrink-0 rounded-full px-2.5 text-xs"
                        onClick={() => openNotes(meeting)}
                        disabled={busy === `notes-${meeting.id}`}
                    >
                        {busy === `notes-${meeting.id}` ? <Loader2 className="size-3 animate-spin" /> : <FileText className="mr-1 size-3" />}
                        {meeting.notes_page ? 'Notes' : 'Create meeting notes'}
                    </Button>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className={cn('shrink-0', compact ? 'size-6 rounded-full' : 'size-7')} aria-label={`Meeting actions: ${meeting.title}`}>
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-56">
                        {join && (
                            <DropdownMenuItem onClick={() => copyMeetingLink(join)}>
                                <Copy className="mr-2 size-4" />Copy link
                            </DropdownMenuItem>
                        )}
                        {eventUrl && (
                            <DropdownMenuItem asChild>
                                <a href={eventUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="mr-2 size-4" />Open in {meeting.provider_label}</a>
                            </DropdownMenuItem>
                        )}
                        {(meeting.notes_page || meeting.can_create_notes) && (
                            <DropdownMenuItem onClick={() => openNotes(meeting)}>
                                <FileText className="mr-2 size-4" />{meeting.notes_page ? 'Open meeting notes' : 'Create meeting notes'}
                            </DropdownMenuItem>
                        )}
                        {meeting.can_remove && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setRemoving(meeting)}>
                                    <Trash2 className="mr-2 size-4" />Remove…
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        );
    };

    const dialogs = (
        <>
            <ScheduleMeetingDialog
                open={scheduleOpen}
                onOpenChange={onScheduleOpenChange}
                workspace={workspace}
                project={project}
                subject={subject}
                onScheduled={(m) => setMeetings((list) => [...list, m].sort((a, b) => a.starts_at.localeCompare(b.starts_at)))}
            />
            <Dialog open={removing !== null} onOpenChange={(o) => { if (!o) setRemoving(null); }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Remove meeting</DialogTitle>
                        <DialogDescription>
                            {removing?.can_cancel
                                ? `“${removing?.title}” is in your calendar. Cancel it there (the attendees get a cancellation), or only remove it from this ${subject.type === 'item' ? 'issue' : 'doc'}?`
                                : `Remove “${removing?.title}” from this ${subject.type === 'item' ? 'issue' : 'doc'}? The event stays in the organiser’s calendar.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="flex-col gap-2 sm:flex-row sm:gap-0">
                        <Button variant="outline" onClick={() => setRemoving(null)}>Keep</Button>
                        <Button variant="secondary" onClick={() => removing && remove(removing, false)} disabled={busy !== null}>
                            Only unlink
                        </Button>
                        {removing?.can_cancel && (
                            <Button variant="destructive" onClick={() => remove(removing, true)} disabled={busy !== null}>
                                Cancel event
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );

    if (variant === 'strip') {
        if (upcoming.length === 0) return dialogs;
        return (
            <div className="mt-3 flex flex-wrap gap-2" aria-label="Meetings" data-testid="meeting-strip">
                {upcoming.map((m) => row(m, true))}
                {dialogs}
            </div>
        );
    }

    return (
        <section className="space-y-2" aria-label="Meetings" data-testid="meetings-section">
            <div className="flex items-center justify-between">
                <h3 className="flex items-center gap-1.5 text-[13px] font-semibold">
                    <CalendarClock className="size-4 text-muted-foreground" />Meetings
                    {meetings.length > 0 && <span className="text-xs font-normal text-muted-foreground">{meetings.length}</span>}
                </h3>
                {canSchedule && (
                    <Button variant="ghost" size="sm" className="h-7 gap-1 px-2 text-xs" onClick={() => onScheduleOpenChange(true)}>
                        <Plus className="size-3.5" />Schedule
                    </Button>
                )}
            </div>
            {!loaded && <p className="text-xs text-muted-foreground">Loading…</p>}
            {loadError && <p className="text-xs text-destructive">{loadError}</p>}
            {loaded && !loadError && meetings.length === 0 && <p className="text-xs text-muted-foreground">No meetings yet.</p>}
            {upcoming.map((m) => row(m, false))}
            {past.length > 0 && (
                <div className="space-y-2">
                    <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={() => setShowPast((v) => !v)} aria-expanded={showPast}>
                        {showPast ? 'Hide' : 'Show'} past meetings ({past.length})
                    </button>
                    {showPast && past.map((m) => row(m, false))}
                </div>
            )}
            {dialogs}
        </section>
    );
}
