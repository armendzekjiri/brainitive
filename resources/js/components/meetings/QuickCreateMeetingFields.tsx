import { useEffect, useState } from 'react';
import axios from 'axios';
import { Video } from 'lucide-react';
import { Label } from '@/components/ui/label';
import EmailChipsInput from '@/components/meetings/EmailChipsInput';
import { CONFERENCE_ADD_LABELS } from '@/lib/meetings';
import { cn } from '@/lib/utils';
import type { MeetingCalendar } from '@/types';

export type QuickMeeting = { conference: 'google_meet' | 'teams' | null; attendees: string[] };

export const NO_MEETING: QuickMeeting = { conference: null, attendees: [] };

/**
 * Calendar quick create (BRAI-147): "Add Google Meet" / "Add Teams meeting" + guests, shown only
 * when the chosen calendar can add a video call. The calendars' kinds are loaded once per open.
 */
export default function QuickCreateMeetingFields({ open, connectionId, value, onChange, hasTime }: {
    open: boolean;
    connectionId: number | null;
    value: QuickMeeting;
    onChange: (value: QuickMeeting) => void;
    /** a video call needs a start time */
    hasTime: boolean;
}) {
    const [calendars, setCalendars] = useState<MeetingCalendar[] | null>(null);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        axios.get<{ calendars: MeetingCalendar[] }>(route('calendar.meetings.calendars'), { signal: controller.signal })
            .then(({ data }) => setCalendars(data.calendars))
            .catch(() => setCalendars([]));
        return () => controller.abort();
    }, [open]);

    const calendar = calendars?.find((c) => c.id === connectionId) ?? null;
    const kinds = calendar?.conference_providers ?? [];

    // A kind the newly picked calendar doesn't offer is dropped.
    useEffect(() => {
        if (value.conference && !kinds.includes(value.conference)) onChange({ ...value, conference: null });
    }, [connectionId, calendars]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!calendar) return null;
    if (kinds.length === 0) {
        return calendar.provider === 'outlook'
            ? <p className="text-xs text-muted-foreground">Teams isn’t available for this account.</p>
            : null;
    }

    return (
        <div className="space-y-3 rounded-md border border-border/70 p-3">
            <div className="flex flex-wrap gap-1.5">
                {kinds.map((k) => (
                    <button
                        key={k}
                        type="button"
                        role="switch"
                        aria-checked={value.conference === k}
                        onClick={() => onChange({ ...value, conference: value.conference === k ? null : k })}
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-md border px-3 text-xs font-medium transition-colors',
                            value.conference === k ? 'border-primary bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-muted',
                        )}
                    >
                        <Video className="size-3.5" />{CONFERENCE_ADD_LABELS[k]}
                    </button>
                ))}
            </div>
            {value.conference && !hasTime && <p className="text-xs text-destructive">Pick a start time for the video call.</p>}
            <div className="space-y-1.5">
                <Label htmlFor="qc-guests">Guests</Label>
                <EmailChipsInput id="qc-guests" value={value.attendees} onChange={(attendees) => onChange({ ...value, attendees })} max={50} />
            </div>
        </div>
    );
}
