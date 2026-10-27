import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, CalendarCheck, CalendarX, ChevronLeft, ChevronRight, Clock, Globe, Loader2, MapPin, Video } from 'lucide-react';
import { OperatorNotice } from '@/components/legal/OperatorNotice';
import { TimezonePicker } from '@/components/TimezonePicker';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    addMonths, allTimezones, browserTimezone, dayKey, durationLabel, lastDayOfMonth, longDate, monthGrid, monthLabel, timeLabel, weekdayInitials, whenLabel,
} from '@/lib/booking';
import { cn } from '@/lib/utils';
import type { PublicBookingData, PublicBookingPageData, PublicOperatorData } from '@/types';

interface Props {
    page: PublicBookingPageData;
    selected: string | null;
    operator: PublicOperatorData | null;
    embed: boolean;
    /** the guest's manage link: their booking, cancel / reschedule */
    manage: { token: string; booking: PublicBookingData; can_change: boolean } | null;
}

type Step = 'types' | 'time' | 'details' | 'done' | 'manage';

function firstError(errors: Record<string, string[] | string> | undefined): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, value] of Object.entries(errors ?? {})) out[key] = Array.isArray(value) ? value[0] : value;
    return out;
}

async function send(url: string, body: unknown): Promise<{ status: number; data: Record<string, unknown> }> {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
    });
    return { status: response.status, data: await response.json().catch(() => ({})) };
}

/**
 * The public booking page at /book/{slug} (BRAI-158), and the guest's manage page. No login, no app
 * chrome. Free times come from the server as UTC instants and are shown in the visitor's zone.
 */
export default function Public({ page, selected, operator, embed, manage }: Props) {
    const [zone, setZone] = useState(browserTimezone);
    const zones = useMemo(() => allTimezones(zone), [zone]);
    const [typeSlug, setTypeSlug] = useState<string | null>(selected);
    const type = page.types.find((t) => t.slug === typeSlug) ?? null;
    const [step, setStep] = useState<Step>(manage ? 'manage' : type ? 'time' : 'types');
    const [rescheduling, setRescheduling] = useState(false);

    const [month, setMonth] = useState(() => dayKey(new Date().toISOString(), browserTimezone()).slice(0, 7));
    const [slots, setSlots] = useState<string[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [day, setDay] = useState<string | null>(null);
    const [slot, setSlot] = useState<string | null>(null);

    const [form, setForm] = useState({ name: '', email: '', consent: false, answers: {} as Record<string, string> });
    const honeypot = useRef<HTMLInputElement>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [booking, setBooking] = useState<PublicBookingData | null>(manage?.booking ?? null);
    const [manageUrl, setManageUrl] = useState<string | null>(manage ? `/book/manage/${manage.token}` : null);
    const [cancelReason, setCancelReason] = useState('');
    const [canChange, setCanChange] = useState(manage?.can_change ?? false);

    const pickingTime = step === 'time' || (step === 'manage' && rescheduling);
    const slotsUrl = manage ? `/book/manage/${manage.token}/slots` : type ? `/book/${page.slug}/${type.slug}/slots` : null;

    const loadSlots = useCallback(async () => {
        if (!slotsUrl) return;
        setSlots(null);
        setLoadError(null);
        const query = new URLSearchParams({ from: `${month}-01`, to: lastDayOfMonth(month), timezone: zone });
        try {
            const response = await fetch(`${slotsUrl}?${query}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error();
            setSlots(((await response.json()) as { slots: string[] }).slots);
        } catch {
            setLoadError('Couldn’t load the free times. Please try again.');
            setSlots([]);
        }
    }, [slotsUrl, month, zone]);

    useEffect(() => {
        if (pickingTime) void loadSlots();
    }, [pickingTime, loadSlots]);

    const byDay = useMemo(() => {
        const map = new Map<string, string[]>();
        for (const s of slots ?? []) {
            const key = dayKey(s, zone);
            if (key.slice(0, 7) !== month) continue;
            map.set(key, [...(map.get(key) ?? []), s]);
        }
        return map;
    }, [slots, zone, month]);

    // jump to the first free day of the month once slots arrive
    useEffect(() => {
        if (slots && (!day || !byDay.has(day))) setDay(byDay.keys().next().value ?? null);
    }, [slots, byDay, day]);

    const today = dayKey(new Date().toISOString(), zone);
    const thisMonth = today.slice(0, 7);

    const chooseType = (slug: string) => {
        setTypeSlug(slug);
        setSlot(null);
        setDay(null);
        setStep('time');
    };

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!type || !slot) return;
        setBusy(true);
        setErrors({});
        const { status, data } = await send(`/book/${page.slug}/${type.slug}`, {
            start: slot,
            name: form.name,
            email: form.email,
            timezone: zone,
            answers: form.answers,
            consent: form.consent,
            website: honeypot.current?.value ?? '',
        });
        setBusy(false);
        if (status === 200) {
            setBooking((data.booking as PublicBookingData) ?? null);
            setManageUrl((data.manage_url as string) ?? null);
            setStep('done');
        } else if (status === 409) {
            setErrors({ start: String(data.message ?? 'This time was just taken.') });
            setSlot(null);
            setStep('time');
            void loadSlots();
        } else {
            setErrors(firstError(data.errors as Record<string, string[]>));
        }
    }

    async function reschedule() {
        if (!manage || !slot) return;
        setBusy(true);
        const { status, data } = await send(`/book/manage/${manage.token}/reschedule`, { start: slot });
        setBusy(false);
        if (status === 200) {
            setBooking(data.booking as PublicBookingData);
            setManageUrl((data.manage_url as string) ?? null);
            setRescheduling(false);
            setCanChange(false); // this link belongs to the old booking now: the new one came by email
            setStep('done');
        } else {
            setErrors({ start: String(data.message ?? 'This time was just taken.') });
            setSlot(null);
            void loadSlots();
        }
    }

    async function cancel() {
        if (!manage) return;
        setBusy(true);
        const { status, data } = await send(`/book/manage/${manage.token}/cancel`, { reason: cancelReason || null });
        setBusy(false);
        if (status === 200) {
            setBooking(data.booking as PublicBookingData);
            setCanChange(false);
        } else {
            setErrors({ cancel: 'This booking can’t be cancelled any more.' });
        }
    }

    const host = (
        <div className="flex items-center gap-3">
            <Avatar className="size-11">
                {page.host.avatar && <AvatarImage src={page.host.avatar} alt="" />}
                <AvatarFallback>{page.host.name.charAt(0)}</AvatarFallback>
            </Avatar>
            <div className="min-w-0">
                <p className="text-sm text-muted-foreground">{page.host.name}</p>
                <h1 className="text-xl font-semibold leading-tight" data-testid="booking-title">{page.title}</h1>
            </div>
        </div>
    );

    const typeSummary = type && (
        <div className="space-y-2 text-sm text-muted-foreground">
            <p className="text-base font-medium text-foreground">{type.name}</p>
            <p className="flex items-center gap-2"><Clock className="h-4 w-4" />{durationLabel(type.duration_minutes)}</p>
            {(type.location_kind === 'google_meet' || type.location_kind === 'teams') && (
                <p className="flex items-center gap-2"><Video className="h-4 w-4" />{type.location_kind === 'teams' ? 'Microsoft Teams' : 'Google Meet'}</p>
            )}
            {type.location && <p className="flex items-center gap-2"><MapPin className="h-4 w-4" />{type.location}</p>}
            {slot && <p className="flex items-center gap-2 font-medium text-foreground"><CalendarCheck className="h-4 w-4" />{whenLabel(slot, new Date(new Date(slot).getTime() + type.duration_minutes * 60000).toISOString(), zone)}</p>}
            {type.description && <p className="whitespace-pre-line pt-1">{type.description}</p>}
        </div>
    );

    const picker = (
        <div className="grid gap-6 md:grid-cols-[1fr_12rem]">
            <div>
                <div className="mb-3 flex items-center justify-between">
                    <Button type="button" variant="ghost" size="icon" aria-label="Previous month" disabled={month <= thisMonth} onClick={() => { setMonth(addMonths(month, -1)); setDay(null); }}>
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <p className="font-medium" aria-live="polite">{monthLabel(month)}</p>
                    <Button type="button" variant="ghost" size="icon" aria-label="Next month" onClick={() => { setMonth(addMonths(month, 1)); setDay(null); }}>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>
                <div className="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground">
                    {weekdayInitials().map((d) => <div key={d} className="py-1">{d}</div>)}
                </div>
                <div className="grid grid-cols-7 gap-1" role="grid" aria-label="Days with free times">
                    {monthGrid(month).map((date, i) => {
                        if (!date) return <div key={`pad-${i}`} />;
                        const free = byDay.has(date);
                        return (
                            <button
                                key={date}
                                type="button"
                                disabled={!free}
                                onClick={() => { setDay(date); setSlot(null); }}
                                aria-pressed={day === date}
                                aria-label={`${date}${free ? ', free times' : ''}`}
                                data-testid={free ? 'free-day' : undefined}
                                className={cn(
                                    'aspect-square rounded-lg text-sm transition-colors',
                                    free ? 'bg-primary/10 font-semibold text-primary hover:bg-primary/20' : 'text-muted-foreground/50',
                                    day === date && 'bg-primary text-primary-foreground hover:bg-primary',
                                    date === today && day !== date && 'ring-1 ring-primary/40',
                                )}
                            >
                                {Number(date.slice(8))}
                            </button>
                        );
                    })}
                </div>
                {slots === null && <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="h-4 w-4 animate-spin" />Looking for free times…</p>}
                {slots && byDay.size === 0 && !loadError && <p className="mt-4 text-sm text-muted-foreground">No free times this month. Try the next one.</p>}
                {loadError && <p className="mt-4 text-sm text-destructive">{loadError}</p>}
                <div className="mt-4 space-y-1">
                    <Label htmlFor="booking-timezone" className="flex items-center gap-1 text-xs text-muted-foreground"><Globe className="h-3.5 w-3.5" />Your time zone</Label>
                    <TimezonePicker id="booking-timezone" value={zone} timezones={zones} onChange={setZone} />
                </div>
            </div>
            <div>
                {day && <p className="mb-2 text-sm font-medium">{longDate(`${day}T12:00:00Z`, 'UTC')}</p>}
                <div className="flex max-h-80 flex-col gap-2 overflow-y-auto pr-1" data-testid="slot-list">
                    {(day ? byDay.get(day) ?? [] : []).map((s) => (
                        <Button key={s} type="button" variant={slot === s ? 'default' : 'outline'} onClick={() => setSlot(s)} className="justify-center">
                            {timeLabel(s, zone)}
                        </Button>
                    ))}
                </div>
                {errors.start && <p className="mt-2 text-sm text-destructive" role="alert">{errors.start}</p>}
            </div>
        </div>
    );

    return (
        <div className={cn('min-h-screen bg-background text-foreground', !embed && 'px-4 py-8 sm:py-12')}>
            <Head title={page.title} />
            <main className={cn('mx-auto w-full max-w-4xl rounded-2xl border bg-card p-5 shadow-sm sm:p-8', embed && 'border-0 shadow-none')}>
                {host}
                {page.intro && step !== 'manage' && step !== 'done' && <p className="mt-4 whitespace-pre-line text-sm text-muted-foreground">{page.intro}</p>}

                {step === 'types' && (
                    <ul className="mt-6 grid gap-3 sm:grid-cols-2" aria-label="Meeting types">
                        {page.types.map((t) => (
                            <li key={t.slug}>
                                <button type="button" onClick={() => chooseType(t.slug)} className="w-full rounded-xl border p-4 text-left transition-colors hover:border-primary hover:bg-primary/5">
                                    <p className="font-medium">{t.name}</p>
                                    <p className="mt-1 flex items-center gap-1 text-sm text-muted-foreground"><Clock className="h-3.5 w-3.5" />{durationLabel(t.duration_minutes)}</p>
                                    {t.description && <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">{t.description}</p>}
                                </button>
                            </li>
                        ))}
                        {page.types.length === 0 && <li className="text-sm text-muted-foreground">There’s nothing to book here right now.</li>}
                    </ul>
                )}

                {step === 'time' && type && (
                    <div className="mt-6 grid gap-8 md:grid-cols-[14rem_1fr]">
                        <div className="space-y-4">
                            {page.types.length > 1 && (
                                <Button type="button" variant="ghost" size="sm" className="-ml-2" onClick={() => setStep('types')}><ArrowLeft className="mr-1 h-4 w-4" />All meeting types</Button>
                            )}
                            {typeSummary}
                        </div>
                        <div>
                            {picker}
                            <div className="mt-6 flex justify-end">
                                <Button type="button" disabled={!slot} onClick={() => setStep('details')}>Next</Button>
                            </div>
                        </div>
                    </div>
                )}

                {step === 'details' && type && slot && (
                    <div className="mt-6 grid gap-8 md:grid-cols-[14rem_1fr]">
                        <div className="space-y-4">
                            <Button type="button" variant="ghost" size="sm" className="-ml-2" onClick={() => setStep('time')}><ArrowLeft className="mr-1 h-4 w-4" />Change the time</Button>
                            {typeSummary}
                        </div>
                        <form onSubmit={submit} className="space-y-4" noValidate>
                            <div className="space-y-1.5">
                                <Label htmlFor="guest-name">Your name</Label>
                                <Input id="guest-name" autoComplete="name" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                                {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="guest-email">Email</Label>
                                <Input id="guest-email" type="email" autoComplete="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                                {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                            </div>
                            {type.questions.map((q) => (
                                <div key={q.id} className="space-y-1.5">
                                    <Label htmlFor={`q-${q.id}`}>{q.label}{q.required ? '' : ' (optional)'}</Label>
                                    {q.type === 'textarea' ? (
                                        <Textarea id={`q-${q.id}`} rows={3} value={form.answers[q.id] ?? ''} onChange={(e) => setForm({ ...form, answers: { ...form.answers, [q.id]: e.target.value } })} />
                                    ) : q.type === 'choice' ? (
                                        <select id={`q-${q.id}`} className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm" value={form.answers[q.id] ?? ''} onChange={(e) => setForm({ ...form, answers: { ...form.answers, [q.id]: e.target.value } })}>
                                            <option value="">Choose…</option>
                                            {q.options.map((o) => <option key={o} value={o}>{o}</option>)}
                                        </select>
                                    ) : (
                                        <Input id={`q-${q.id}`} type={q.type === 'phone' ? 'tel' : 'text'} autoComplete={q.type === 'phone' ? 'tel' : undefined} value={form.answers[q.id] ?? ''} onChange={(e) => setForm({ ...form, answers: { ...form.answers, [q.id]: e.target.value } })} />
                                    )}
                                    {errors[`answers.${q.id}`] && <p className="text-sm text-destructive">{errors[`answers.${q.id}`]}</p>}
                                </div>
                            ))}
                            {/* honeypot: hidden from people, bots fill it in */}
                            <input ref={honeypot} type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 opacity-0" />
                            {page.consent !== 'off' && page.consent_text && (
                                <label className="flex items-start gap-2 text-sm">
                                    <input type="checkbox" className="mt-1" checked={form.consent} onChange={(e) => setForm({ ...form, consent: e.target.checked })} />
                                    <span>{page.consent_text}{page.consent === 'optional' ? ' (optional)' : ''}</span>
                                </label>
                            )}
                            {errors.consent && <p className="text-sm text-destructive">{errors.consent}</p>}
                            <Button type="submit" disabled={busy} className="w-full sm:w-auto">
                                {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Book {timeLabel(slot, zone)}
                            </Button>
                        </form>
                    </div>
                )}

                {step === 'done' && booking && (
                    <div className="mx-auto mt-8 max-w-md space-y-4 text-center" data-testid="booking-done">
                        <CalendarCheck className="mx-auto h-10 w-10 text-brand-green" />
                        <h2 className="text-lg font-semibold">You’re booked</h2>
                        <p className="text-sm">{booking.type} with {page.host.name}</p>
                        <p className="font-medium">{whenLabel(booking.starts_at, booking.ends_at, zone)}</p>
                        {booking.conference_url && <p className="text-sm"><a className="underline" href={booking.conference_url} target="_blank" rel="noopener noreferrer">Join link</a></p>}
                        {booking.location && <p className="text-sm text-muted-foreground">{booking.location}</p>}
                        <p className="text-sm text-muted-foreground">We sent the details and a calendar invite to {booking.guest_email}.</p>
                        {manageUrl && <p className="text-sm"><a className="underline" href={manageUrl}>Reschedule or cancel</a></p>}
                    </div>
                )}

                {step === 'manage' && booking && (
                    <div className="mt-6 space-y-6">
                        <div className="rounded-xl border p-4" data-testid="manage-booking">
                            <p className="font-medium">{booking.type}</p>
                            <p className="mt-1 text-sm">{whenLabel(booking.starts_at, booking.ends_at, zone)}</p>
                            {booking.conference_url && booking.status === 'confirmed' && <p className="mt-1 text-sm"><a className="underline" href={booking.conference_url} target="_blank" rel="noopener noreferrer">Join link</a></p>}
                            {booking.location && <p className="mt-1 text-sm text-muted-foreground">{booking.location}</p>}
                            {booking.status === 'cancelled' && <p className="mt-2 flex items-center gap-2 text-sm text-destructive"><CalendarX className="h-4 w-4" />Cancelled</p>}
                            {booking.status === 'confirmed' && !canChange && <p className="mt-2 text-sm text-muted-foreground">This booking can’t be changed any more.</p>}
                        </div>

                        {canChange && !rescheduling && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <p className="font-medium">Need another time?</p>
                                    <Button type="button" variant="outline" onClick={() => { setRescheduling(true); setSlot(null); }}>Reschedule</Button>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="cancel-reason">Cancel (reason, optional)</Label>
                                    <Textarea id="cancel-reason" rows={2} value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
                                    <Button type="button" variant="destructive" disabled={busy} onClick={cancel}>Cancel booking</Button>
                                    {errors.cancel && <p className="text-sm text-destructive">{errors.cancel}</p>}
                                </div>
                            </div>
                        )}

                        {rescheduling && (
                            <div className="space-y-4">
                                {picker}
                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="ghost" onClick={() => setRescheduling(false)}>Back</Button>
                                    <Button type="button" disabled={!slot || busy} onClick={reschedule}>{slot ? `Move to ${timeLabel(slot, zone)}` : 'Pick a time'}</Button>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <OperatorNotice operator={operator} className="mt-10 border-t pt-4 text-center" />
            </main>
        </div>
    );
}
