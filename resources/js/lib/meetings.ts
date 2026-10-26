import { toast } from 'sonner';
import type { ConferenceProvider, Meeting } from '@/types';

/** Online meetings (BRAI-147): small shared helpers for the dialog, the lists and quick create. */

export const CONFERENCE_LABELS: Record<ConferenceProvider, string> = {
    google_meet: 'Google Meet',
    teams: 'Microsoft Teams',
    other: 'Video call',
};

/** The toggle in quick create: "Add Google Meet" / "Add Teams meeting". */
export const CONFERENCE_ADD_LABELS: Record<Exclude<ConferenceProvider, 'other'>, string> = {
    google_meet: 'Add Google Meet',
    teams: 'Add Teams meeting',
};

export const DURATIONS = [15, 30, 45, 60, 90] as const;

/** Simple address check (the server validates for real). */
export function isEmail(value: string): boolean {
    return /^[^\s@,;<>]+@[^\s@,;<>]+\.[^\s@,;<>]+$/.test(value.trim());
}

/** Split typed text into addresses ("a@x.com, b@y.com; c@z.com"). */
export function splitEmails(value: string): string[] {
    return value.split(/[\s,;]+/).map((v) => v.trim()).filter(Boolean);
}

export function browserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

/** Every IANA zone the browser knows (for the timezone picker). */
export function allTimezones(): string[] {
    try {
        const intl = Intl as unknown as { supportedValuesOf?: (key: string) => string[] };
        return intl.supportedValuesOf?.('timeZone') ?? [];
    } catch {
        return [];
    }
}

/** The next half hour as wall-clock { date: Y-m-d, time: H:i } in `zone`. */
export function nextHalfHour(zone: string): { date: string; time: string } {
    const at = new Date(Date.now() + 30 * 60 * 1000);
    at.setMinutes(at.getMinutes() < 30 ? 30 : 60, 0, 0);
    try {
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
                .formatToParts(at)
                .map((p) => [p.type, p.value]),
        );
        // rounding happened in the browser's zone; snap again in `zone` (zones with :30 / :45 offsets)
        const minute = Number(parts.minute) < 30 ? '00' : '30';
        return { date: `${parts.year}-${parts.month}-${parts.day}`, time: `${parts.hour}:${minute}` };
    } catch {
        const pad = (n: number) => String(n).padStart(2, '0');
        return { date: `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`, time: `${pad(at.getHours())}:${pad(at.getMinutes())}` };
    }
}

/** "Tue, Nov 3 · 14:00–14:45" in the viewer's zone. */
export function formatMeetingTime(meeting: Pick<Meeting, 'starts_at' | 'ends_at'>): string {
    const start = new Date(meeting.starts_at);
    const end = new Date(meeting.ends_at);
    const day = start.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    const time = (d: Date) => d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    return `${day} · ${time(start)}–${time(end)}`;
}

export function isPast(meeting: Pick<Meeting, 'ends_at'>): boolean {
    return new Date(meeting.ends_at).getTime() < Date.now();
}

/** Only https links become hrefs (the server already checks). */
export function safeJoinUrl(url: string | null | undefined): string | null {
    if (!url) return null;
    try {
        return new URL(url).protocol === 'https:' ? url : null;
    } catch {
        return null;
    }
}

export async function copyMeetingLink(url: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(url);
        toast.success('Meeting link copied');
    } catch {
        toast.error('Couldn’t copy the link. Select and copy it from the meeting instead.');
    }
}

/** "Meeting scheduled" with Join + Copy link (when there is a link). */
export function toastScheduled(meeting: Meeting, warning?: string | null): void {
    const join = safeJoinUrl(meeting.join_url);
    const description = `${formatMeetingTime(meeting)}${meeting.conference_label ? ` · ${meeting.conference_label}` : ''}`;
    if (!join) {
        toast.success('Meeting scheduled', { description: warning ?? description });
        return;
    }
    toast.success('Meeting scheduled', {
        description,
        duration: 10000,
        action: { label: 'Join', onClick: () => window.open(join, '_blank', 'noopener,noreferrer') },
        cancel: { label: 'Copy link', onClick: () => { void copyMeetingLink(join); } },
    });
}

/** First message of an axios / Laravel error. */
export function errorMessage(error: unknown, fallback: string): string {
    const data = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return first || data?.message || fallback;
}
