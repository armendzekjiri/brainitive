import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { CalendarPlus, Check, Loader2, Users, Video } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TimezonePicker } from '@/components/TimezonePicker';
import EmailChipsInput from '@/components/meetings/EmailChipsInput';
import { AssigneeAvatar } from '@/components/board/ItemPrimitives';
import { allTimezones, CONFERENCE_LABELS, DURATIONS, errorMessage, nextHalfHour, toastScheduled } from '@/lib/meetings';
import { cn } from '@/lib/utils';
import type { Meeting, MeetingOptions, MeetingSubject } from '@/types';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspace: { slug: string };
    project: { slug: string };
    subject: MeetingSubject;
    onScheduled?: (meeting: Meeting) => void;
}

type Kind = 'google_meet' | 'teams' | 'none';

/**
 * "Schedule meeting" (BRAI-147), shared by issues and docs: a calendar event with a Google Meet /
 * Teams call in one of the user's own calendars, guests = project members (by id; the server
 * looks up their addresses) + typed addresses. The calendar provider sends the invitations.
 */
export default function ScheduleMeetingDialog({ open, onOpenChange, workspace, project, subject, onScheduled }: Props) {
    const [options, setOptions] = useState<MeetingOptions | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [title, setTitle] = useState('');
    const [date, setDate] = useState('');
    const [time, setTime] = useState('');
    const [duration, setDuration] = useState('30');
    const [customMinutes, setCustomMinutes] = useState('120');
    const [timezone, setTimezone] = useState('');
    const [connectionId, setConnectionId] = useState('');
    const [kind, setKind] = useState<Kind>('none');
    const [memberIds, setMemberIds] = useState<number[]>([]);
    const [emails, setEmails] = useState<string[]>([]);
    const [description, setDescription] = useState('');
    const [membersOpen, setMembersOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const timezones = useMemo(() => allTimezones(), []);

    // Fresh form each time the dialog opens.
    useEffect(() => {
        if (!open) return;
        setOptions(null);
        setLoadError(null);
        setError(null);
        setSaving(false);
        setTitle(subject.title);
        setDescription(`${subject.title}\n${subject.url}`);
        setDuration('30');
        setMemberIds([]);
        setEmails([]);
        const controller = new AbortController();
        axios.get<MeetingOptions>(route('meetings.options', [workspace.slug, project.slug]), { signal: controller.signal })
            .then(({ data }) => {
                setOptions(data);
                setTimezone(data.timezone);
                const start = nextHalfHour(data.timezone);
                setDate(start.date);
                setTime(start.time);
                const first = data.calendars.find((c) => c.conference_providers.length > 0) ?? data.calendars[0];
                setConnectionId(first ? String(first.id) : '');
                setKind(first?.conference_providers[0] ?? 'none');
            })
            .catch((e) => {
                if (axios.isCancel(e)) return;
                setLoadError(errorMessage(e, 'Couldn’t load your calendars.'));
            });
        return () => controller.abort();
    }, [open, workspace.slug, project.slug, subject.title, subject.url]);

    const calendar = options?.calendars.find((c) => String(c.id) === connectionId) ?? null;
    const kinds = calendar?.conference_providers ?? [];
    const members = options?.members ?? [];
    const max = options?.max_attendees ?? 50;
    const minutes = duration === 'custom' ? Number(customMinutes) : Number(duration);
    const durationValid = Number.isInteger(minutes) && minutes >= 5 && minutes <= 720;
    const canSubmit = !!calendar && title.trim() !== '' && date !== '' && time !== '' && durationValid && !saving;

    const pickCalendar = (id: string) => {
        setConnectionId(id);
        const next = options?.calendars.find((c) => String(c.id) === id);
        setKind(next?.conference_providers[0] ?? 'none');
    };

    const toggleMember = (id: number) => {
        setMemberIds((ids) => {
            if (ids.includes(id)) return ids.filter((x) => x !== id);
            if (ids.length + emails.length >= max) return ids;
            return [...ids, id];
        });
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!canSubmit || !calendar) return;
        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.post<{ meeting: Meeting; warning: string | null }>(route('meetings.store', [workspace.slug, project.slug]), {
                subject_type: subject.type,
                subject_id: subject.id,
                connection_id: calendar.id,
                title: title.trim(),
                description: description.trim() || null,
                date,
                start_time: time,
                duration: minutes,
                timezone,
                conference: kind,
                member_ids: memberIds,
                emails,
            });
            toastScheduled(data.meeting, data.warning);
            onScheduled?.(data.meeting);
            onOpenChange(false);
        } catch (err) {
            setError(errorMessage(err, 'Couldn’t schedule the meeting.'));
        } finally {
            setSaving(false);
        }
    };

    const unavailableHint = calendar && kinds.length === 0
        ? (calendar.provider === 'outlook' ? 'Teams isn’t available for this account.' : 'This calendar can’t add a video call.')
        : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Schedule meeting</DialogTitle>
                    <DialogDescription>
                        Creates the event in your calendar and invites the attendees — {subject.type === 'item' ? 'linked to this issue' : 'linked to this doc'}.
                    </DialogDescription>
                </DialogHeader>

                {loadError && <p className="text-sm text-destructive">{loadError}</p>}
                {!options && !loadError && (
                    <p className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                        <Loader2 className="size-4 animate-spin" /> Loading your calendars…
                    </p>
                )}

                {options && options.calendars.length === 0 && (
                    <div className="space-y-3 rounded-lg border border-dashed p-4 text-sm" data-testid="meeting-no-calendar">
                        <p className="font-medium">Connect a calendar first</p>
                        <p className="text-muted-foreground">
                            Meetings are created in your own Google Calendar (with Google Meet) or Outlook / Microsoft 365 calendar
                            (with Teams). Connect one under Calendar → Add calendar, then come back.
                        </p>
                        <Button asChild size="sm" variant="outline">
                            <a href={options.connect_url}><CalendarPlus className="mr-1.5 size-4" />Connect a calendar</a>
                        </Button>
                    </div>
                )}

                {options && options.calendars.length > 0 && (
                    <form onSubmit={submit} className="min-w-0 space-y-4" aria-label="Schedule meeting">
                        <div className="space-y-1.5">
                            <Label htmlFor="meeting-title">Title</Label>
                            <Input id="meeting-title" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} />
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="meeting-date">Date</Label>
                                <Input id="meeting-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="meeting-time">Start</Label>
                                <Input id="meeting-time" type="time" value={time} onChange={(e) => setTime(e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="meeting-duration">Duration</Label>
                                <Select value={duration} onValueChange={setDuration}>
                                    <SelectTrigger id="meeting-duration" aria-label="Duration"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {DURATIONS.map((m) => <SelectItem key={m} value={String(m)}>{m < 60 ? `${m} min` : `${m / 60} h`.replace('.5 h', ' h 30')}</SelectItem>)}
                                        <SelectItem value="custom">Custom…</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        {duration === 'custom' && (
                            <div className="space-y-1.5">
                                <Label htmlFor="meeting-minutes">Minutes</Label>
                                <Input id="meeting-minutes" type="number" min={5} max={720} step={5} value={customMinutes} onChange={(e) => setCustomMinutes(e.target.value)} />
                                {!durationValid && <p className="text-xs text-destructive">Between 5 minutes and 12 hours.</p>}
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="meeting-timezone">Time zone</Label>
                            <TimezonePicker id="meeting-timezone" value={timezone} timezones={timezones} onChange={setTimezone} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="meeting-calendar">Calendar</Label>
                            <Select value={connectionId} onValueChange={pickCalendar}>
                                <SelectTrigger id="meeting-calendar" aria-label="Calendar"><SelectValue placeholder="Pick a calendar" /></SelectTrigger>
                                <SelectContent>
                                    {options.calendars.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span className="size-2 shrink-0 rounded-full" style={{ background: c.color || '#4285F4' }} />
                                                <span className="truncate">{c.name}</span>
                                                <span className="truncate text-[11px] text-muted-foreground">· {c.account_email}</span>
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <fieldset className="space-y-1.5">
                            <legend className="mb-1.5 text-sm font-medium">Video call</legend>
                            <div className="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Video call">
                                {[...kinds, 'none' as const].map((k) => (
                                    <button
                                        key={k}
                                        type="button"
                                        role="radio"
                                        aria-checked={kind === k}
                                        onClick={() => setKind(k)}
                                        className={cn(
                                            'inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-xs font-medium transition-colors',
                                            kind === k ? 'border-primary bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-muted',
                                        )}
                                    >
                                        {k !== 'none' && <Video className="size-3.5" />}
                                        {k === 'none' ? 'No video call' : CONFERENCE_LABELS[k]}
                                    </button>
                                ))}
                            </div>
                            {unavailableHint && <p className="text-xs text-muted-foreground">{unavailableHint}</p>}
                        </fieldset>

                        <div className="space-y-1.5">
                            <Label>Attendees</Label>
                            <Popover open={membersOpen} onOpenChange={setMembersOpen}>
                                <PopoverTrigger asChild>
                                    <Button type="button" variant="outline" className="w-full justify-start gap-2 font-normal" aria-label="Add project members">
                                        <Users className="size-4 text-muted-foreground" />
                                        {memberIds.length === 0 ? <span className="text-muted-foreground">Add project members…</span> : `${memberIds.length} member${memberIds.length === 1 ? '' : 's'}`}
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-[--radix-popover-trigger-width] min-w-64 p-0" align="start">
                                    <Command>
                                        <CommandInput placeholder="Search members…" />
                                        <CommandList className="max-h-60">
                                            <CommandEmpty>No members found.</CommandEmpty>
                                            {members.map((m) => (
                                                <CommandItem key={m.id} value={`${m.name} ${m.id}`} onSelect={() => toggleMember(m.id)}>
                                                    <Check className={cn('size-4', memberIds.includes(m.id) ? 'opacity-100' : 'opacity-0')} />
                                                    <AssigneeAvatar user={{ name: m.name, avatar_display: m.avatar }} size={20} />
                                                    <span className="truncate">{m.name}</span>
                                                </CommandItem>
                                            ))}
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                            {memberIds.length > 0 && (
                                <div className="flex flex-wrap gap-1.5">
                                    {memberIds.map((id) => {
                                        const m = members.find((x) => x.id === id);
                                        return m ? (
                                            <button key={id} type="button" onClick={() => toggleMember(id)} className="rounded-full bg-muted px-2 py-0.5 text-xs hover:bg-muted/70" aria-label={`Remove ${m.name}`}>
                                                {m.name} ×
                                            </button>
                                        ) : null;
                                    })}
                                </div>
                            )}
                            <EmailChipsInput id="meeting-emails" value={emails} onChange={setEmails} max={max} taken={memberIds.length} placeholder="Other guests: name@example.com" />
                            <p className="text-[11px] text-muted-foreground">Your calendar sends the invitations (at most {max} people).</p>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="meeting-description">Description</Label>
                            <Textarea id="meeting-description" rows={3} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={5000} />
                        </div>

                        {error && <p className="text-sm text-destructive" role="alert">{error}</p>}

                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                            <Button type="submit" disabled={!canSubmit}>
                                {saving ? <><Loader2 className="mr-1.5 size-4 animate-spin" />Scheduling…</> : 'Schedule'}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
