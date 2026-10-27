import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, Copy, CopyPlus, ExternalLink, Pencil, Plus, Trash2, Video } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { toast } from 'sonner';
import AppLayout from '@/Layouts/AppLayout';
import { BookingTypeEditor } from '@/components/booking/BookingTypeEditor';
import { TimezonePicker } from '@/components/TimezonePicker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { LOCATION_LABELS, allTimezones, browserTimezone, durationLabel, whenLabel } from '@/lib/booking';
import type { BookingBoardData, BookingConnectionData, BookingPageData, BookingRow, BookingTemplateData, BookingTypeData } from '@/types';

interface Props {
    page: BookingPageData;
    types: BookingTypeData[];
    connections: BookingConnectionData[];
    boards: BookingBoardData[];
    bookings: BookingRow[];
    templates: BookingTemplateData[];
    tab: 'types' | 'settings' | 'bookings';
}

const select = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm';
const DURATIONS = [15, 30, 45, 60];

/** One booking page (BRAI-158): its meeting types, settings (busy sources, cards, consent) and bookings. */
export default function Show({ page, types, connections, boards, bookings, templates, tab }: Props) {
    const [editing, setEditing] = useState<BookingTypeData | null>(null);
    const [adding, setAdding] = useState(false);
    const [cancelling, setCancelling] = useState<BookingRow | null>(null);
    const [cancelReason, setCancelReason] = useState('');
    const zone = browserTimezone();

    const copy = async (text: string, what = 'Link') => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success(`${what} copied`);
        } catch {
            toast.error('Couldn’t copy');
        }
    };

    // ── add a type ────────────────────────────────────────────────────
    const add = useForm({ name: '', duration_minutes: 30, template: '' });
    const templateTypes = useMemo(() => templates.flatMap((t) => t.types.map((label, i) => ({ key: `${t.key}:${i}`, label: `${t.name} — ${label}` }))), [templates]);

    // ── settings ──────────────────────────────────────────────────────
    const settings = useForm({
        title: page.title,
        intro: page.intro ?? '',
        timezone: page.timezone,
        enabled: page.enabled,
        public_slug: page.public_slug,
        busy_sources: page.busy_sources,
        card_board_id: page.card_board_id,
        card_column_id: page.card_column_id,
        consent: page.consent,
        consent_text: page.consent_text ?? '',
    });
    const zones = useMemo(() => allTimezones(settings.data.timezone), [settings.data.timezone]);
    const allCalendars = settings.data.busy_sources.connections === 'all';
    const blocking = (id: number) => allCalendars || (settings.data.busy_sources.connections as number[]).includes(id);
    const toggleCalendar = (id: number, on: boolean) => {
        const current = allCalendars ? connections.map((c) => c.id) : (settings.data.busy_sources.connections as number[]);
        settings.setData('busy_sources', { ...settings.data.busy_sources, connections: on ? [...new Set([...current, id])] : current.filter((c) => c !== id) });
    };
    const cardBoard = boards.find((b) => b.id === settings.data.card_board_id);
    const broken = connections.filter((c) => c.sync_error && blocking(c.id));

    const move = (index: number, delta: number) => {
        const ids = types.map((t) => t.id);
        const [id] = ids.splice(index, 1);
        ids.splice(index + delta, 0, id);
        router.put(route('booking-types.reorder', page.uuid), { ids }, { preserveScroll: true });
    };

    const upcoming = bookings.filter((b) => b.status === 'confirmed' && new Date(b.ends_at) > new Date());
    const past = bookings.filter((b) => b.status === 'confirmed' && new Date(b.ends_at) <= new Date()).reverse();
    const cancelled = bookings.filter((b) => b.status === 'cancelled').reverse();

    const bookingCard = (b: BookingRow, canCancel: boolean) => (
        <li key={b.uuid}>
            <Card>
                <CardContent className="space-y-2 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p className="font-medium">{whenLabel(b.starts_at, b.ends_at, zone)} · {b.type}</p>
                            <p className="text-sm text-muted-foreground">{b.guest_name} · <a className="underline" href={`mailto:${b.guest_email}`}>{b.guest_email}</a>{b.guest_timezone ? ` · ${b.guest_timezone.replace(/_/g, ' ')}` : ''}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {b.conference_url && <Button variant="outline" size="sm" asChild><a href={b.conference_url} target="_blank" rel="noopener noreferrer"><Video className="mr-1 h-3.5 w-3.5" />Join</a></Button>}
                            {b.card && <Button variant="ghost" size="sm" asChild><a href={b.card.url}>{b.card.key}</a></Button>}
                            {canCancel && <Button variant="ghost" size="sm" className="text-destructive" onClick={() => { setCancelling(b); setCancelReason(''); }}>Cancel</Button>}
                        </div>
                    </div>
                    {b.unchecked.length > 0 && b.status === 'confirmed' && (
                        <p className="flex items-center gap-1 text-sm text-amber-600 dark:text-amber-400"><AlertTriangle className="h-4 w-4" />{b.unchecked.join(', ')} wasn’t checked when this was booked: look for an overlap.</p>
                    )}
                    {!b.in_calendar && b.status === 'confirmed' && <p className="text-xs text-muted-foreground">Not in a calendar (no target calendar, or it couldn’t be written).</p>}
                    {b.answers.length > 0 && (
                        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                            {b.answers.map(([q, a]) => <Fragment key={q}><dt className="text-muted-foreground">{q}</dt><dd className="whitespace-pre-line">{a}</dd></Fragment>)}
                        </dl>
                    )}
                    {b.status === 'cancelled' && <p className="text-sm text-muted-foreground">Cancelled by {b.cancelled_by === 'guest' ? 'the guest' : 'you'}{b.cancel_reason ? `: ${b.cancel_reason}` : ''}</p>}
                </CardContent>
            </Card>
        </li>
    );

    return (
        <AppLayout>
            <Head title={page.title} />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link href={route('booking-pages.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"><ArrowLeft className="h-4 w-4" />Booking pages</Link>
                        <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">{page.title}{!page.enabled && <Badge variant="secondary">Off</Badge>}</h1>
                        <p className="mt-1 text-sm text-muted-foreground">{page.workspace} · {page.timezone.replace(/_/g, ' ')}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => copy(page.public_url)}><Copy className="mr-1 h-4 w-4" />Copy link</Button>
                        <Button variant="outline" asChild><a href={page.public_url} target="_blank" rel="noopener noreferrer"><ExternalLink className="mr-1 h-4 w-4" />Open</a></Button>
                    </div>
                </div>

                {broken.length > 0 && (
                    <div className="flex items-start gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm" role="alert">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                        <p>Couldn’t check {broken.map((c) => `“${c.name}”`).join(', ')}. This page keeps offering times without it, so bookings may overlap its events. Reconnect it under Calendar → Connected calendars.</p>
                    </div>
                )}

                <Tabs defaultValue={tab}>
                    <TabsList>
                        <TabsTrigger value="types">Meeting types</TabsTrigger>
                        <TabsTrigger value="settings">Settings</TabsTrigger>
                        <TabsTrigger value="bookings">Bookings{upcoming.length ? ` (${upcoming.length})` : ''}</TabsTrigger>
                    </TabsList>

                    <TabsContent value="types" className="space-y-4 pt-4">
                        <ul className="space-y-3" aria-label="Meeting types">
                            {types.map((t, i) => (
                                <li key={t.id}>
                                    <Card>
                                        <CardContent className="flex flex-wrap items-center gap-3 p-4">
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium">{t.name} <span className="font-normal text-muted-foreground">· {durationLabel(t.duration_minutes)}</span></p>
                                                <p className="text-sm text-muted-foreground">
                                                    {LOCATION_LABELS[t.location.kind]} · {Object.values(t.availability.weekly).filter((r) => r && r.length).length} days a week
                                                    {t.target_connection_id ? '' : ' · not added to a calendar'}
                                                </p>
                                            </div>
                                            <label className="flex items-center gap-2 text-sm">
                                                <input type="checkbox" checked={t.enabled} onChange={(e) => router.put(route('booking-types.update', [page.uuid, t.id]), { ...t, enabled: e.target.checked } as never, { preserveScroll: true })} />
                                                Bookable
                                            </label>
                                            <Button variant="ghost" size="icon" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}><ArrowUp className="h-4 w-4" /></Button>
                                            <Button variant="ghost" size="icon" aria-label="Move down" disabled={i === types.length - 1} onClick={() => move(i, 1)}><ArrowDown className="h-4 w-4" /></Button>
                                            <Button variant="ghost" size="icon" aria-label={`Copy link to ${t.name}`} onClick={() => copy(`${page.public_url}/${t.slug}`)}><Copy className="h-4 w-4" /></Button>
                                            <Button variant="ghost" size="icon" aria-label={`Duplicate ${t.name}`} onClick={() => router.post(route('booking-types.duplicate', [page.uuid, t.id]), {}, { preserveScroll: true })}><CopyPlus className="h-4 w-4" /></Button>
                                            <Button variant="ghost" size="icon" aria-label={`Delete ${t.name}`} onClick={() => confirm(`Delete “${t.name}”?`) && router.delete(route('booking-types.destroy', [page.uuid, t.id]), { preserveScroll: true, onError: (e) => toast.error(Object.values(e)[0]) })}><Trash2 className="h-4 w-4" /></Button>
                                            <Button variant="outline" size="sm" onClick={() => setEditing(t)}><Pencil className="mr-1 h-3.5 w-3.5" />Edit</Button>
                                        </CardContent>
                                    </Card>
                                </li>
                            ))}
                        </ul>
                        <Button onClick={() => setAdding(true)}><Plus className="mr-1 h-4 w-4" />Add meeting type</Button>
                    </TabsContent>

                    <TabsContent value="settings" className="pt-4">
                        <form onSubmit={(e) => { e.preventDefault(); settings.put(route('booking-pages.update', page.uuid), { preserveScroll: true }); }} className="space-y-8">
                            <section className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="page-title">Title</Label>
                                    <Input id="page-title" value={settings.data.title} onChange={(e) => settings.setData('title', e.target.value)} />
                                    {settings.errors.title && <p className="text-sm text-destructive">{settings.errors.title}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="page-slug">Address</Label>
                                    <div className="flex items-center gap-1 text-sm text-muted-foreground"><span className="shrink-0">…/book/</span><Input id="page-slug" value={settings.data.public_slug} onChange={(e) => settings.setData('public_slug', e.target.value.toLowerCase())} /></div>
                                    {settings.errors.public_slug && <p className="text-sm text-destructive">{settings.errors.public_slug}</p>}
                                </div>
                                <div className="space-y-1.5 sm:col-span-2">
                                    <Label htmlFor="page-intro">Intro (optional)</Label>
                                    <Textarea id="page-intro" rows={2} value={settings.data.intro} onChange={(e) => settings.setData('intro', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="page-timezone">Time zone of your hours</Label>
                                    <TimezonePicker id="page-timezone" value={settings.data.timezone} timezones={zones} onChange={(z) => settings.setData('timezone', z)} />
                                </div>
                                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                    <input type="checkbox" checked={settings.data.enabled} onChange={(e) => settings.setData('enabled', e.target.checked)} />
                                    Page is on (people can book)
                                </label>
                            </section>

                            <section className="space-y-3">
                                <h3 className="font-medium">What blocks this page’s time</h3>
                                <p className="text-sm text-muted-foreground">Bookings on any of your booking pages always block. Choose the rest per page, e.g. leave out a personal calendar for an emergency page.</p>
                                <div className="divide-y rounded-xl border">
                                    <label className="flex items-center gap-2 p-3 text-sm font-medium">
                                        <input type="checkbox" checked={allCalendars} onChange={(e) => settings.setData('busy_sources', { ...settings.data.busy_sources, connections: e.target.checked ? 'all' : connections.map((c) => c.id) })} />
                                        All my calendars (also ones I connect later)
                                    </label>
                                    {connections.map((c) => (
                                        <label key={c.id} className="flex items-center gap-2 p-3 pl-8 text-sm">
                                            <input type="checkbox" disabled={allCalendars} checked={blocking(c.id)} onChange={(e) => toggleCalendar(c.id, e.target.checked)} />
                                            <span>{c.name} <span className="text-muted-foreground">· {c.provider}{c.account ? ` · ${c.account}` : ''}</span></span>
                                            {c.sync_error && <Badge variant="outline" className="border-amber-500/50 text-amber-600">can’t be read</Badge>}
                                        </label>
                                    ))}
                                    {connections.length === 0 && <p className="p-3 pl-8 text-sm text-muted-foreground">No calendars connected. <Link className="underline" href={route('calendar.index')}>Connect one</Link> so its events block your time.</p>}
                                    <label className="flex items-center gap-2 p-3 text-sm">
                                        <input type="checkbox" checked={settings.data.busy_sources.issues} onChange={(e) => settings.setData('busy_sources', { ...settings.data.busy_sources, issues: e.target.checked })} />
                                        Issues assigned to me with a due time
                                    </label>
                                    <label className="flex items-center gap-2 p-3 text-sm">
                                        <input type="checkbox" checked={settings.data.busy_sources.tasks} onChange={(e) => settings.setData('busy_sources', { ...settings.data.busy_sources, tasks: e.target.checked })} />
                                        My tasks with a time
                                    </label>
                                    <label className="flex items-center gap-2 p-3 text-sm">
                                        <input type="checkbox" checked={settings.data.busy_sources.all_day_blocks} onChange={(e) => settings.setData('busy_sources', { ...settings.data.busy_sources, all_day_blocks: e.target.checked })} />
                                        All-day events block the whole day
                                    </label>
                                </div>
                            </section>

                            <section className="space-y-3">
                                <h3 className="font-medium">Where bookings show up</h3>
                                <p className="text-sm text-muted-foreground">Always here under Bookings, in your Brainitive calendar, and in the calendar each meeting type adds them to.</p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="card-board">Also create a card</Label>
                                        <select id="card-board" className={select} value={settings.data.card_board_id ?? ''} onChange={(e) => {
                                            const id = e.target.value ? Number(e.target.value) : null;
                                            settings.setData((d) => ({ ...d, card_board_id: id, card_column_id: boards.find((b) => b.id === id)?.columns[0]?.id ?? null }));
                                        }}>
                                            <option value="">No card</option>
                                            {boards.map((b) => <option key={b.id} value={b.id}>{b.project ? `${b.project} · ` : ''}{b.name}</option>)}
                                        </select>
                                    </div>
                                    {cardBoard && (
                                        <div className="space-y-1.5">
                                            <Label htmlFor="card-column">Column</Label>
                                            <select id="card-column" className={select} value={settings.data.card_column_id ?? ''} onChange={(e) => settings.setData('card_column_id', Number(e.target.value))}>
                                                {cardBoard.columns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                            </select>
                                        </div>
                                    )}
                                </div>
                            </section>

                            <section className="space-y-3">
                                <h3 className="font-medium">Consent</h3>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="consent">Consent checkbox</Label>
                                        <select id="consent" className={select} value={settings.data.consent} onChange={(e) => settings.setData('consent', e.target.value as BookingPageData['consent'])}>
                                            <option value="off">Off</option>
                                            <option value="optional">Optional</option>
                                            <option value="required">Required</option>
                                        </select>
                                    </div>
                                    {settings.data.consent !== 'off' && (
                                        <div className="space-y-1.5">
                                            <Label htmlFor="consent-text">Text ({'{organization}'} = your workspace)</Label>
                                            <Textarea id="consent-text" rows={2} placeholder="I agree that {organization} stores my name, email and answers to arrange and hold this meeting." value={settings.data.consent_text} onChange={(e) => settings.setData('consent_text', e.target.value)} />
                                        </div>
                                    )}
                                </div>
                            </section>

                            <section className="space-y-2">
                                <h3 className="font-medium">Embed on your website</h3>
                                <div className="flex items-center gap-2">
                                    <Input readOnly aria-label="Embed code" value={`<iframe src="${page.embed_url}" style="width:100%;min-height:720px;border:0"></iframe>`} />
                                    <Button type="button" variant="outline" onClick={() => copy(`<iframe src="${page.embed_url}" style="width:100%;min-height:720px;border:0"></iframe>`, 'Embed code')}><Copy className="h-4 w-4" /></Button>
                                </div>
                            </section>

                            <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4">
                                <Button type="button" variant="ghost" className="text-destructive" onClick={() => confirm('Delete this booking page? Upcoming bookings are cancelled and the guests are told.') && router.delete(route('booking-pages.destroy', page.uuid))}>
                                    <Trash2 className="mr-1 h-4 w-4" />Delete page
                                </Button>
                                <Button type="submit" disabled={settings.processing}>Save settings</Button>
                            </div>
                        </form>
                    </TabsContent>

                    <TabsContent value="bookings" className="space-y-6 pt-4">
                        <section className="space-y-3">
                            <h3 className="font-medium">Upcoming</h3>
                            {upcoming.length ? <ul className="space-y-3" aria-label="Upcoming bookings">{upcoming.map((b) => bookingCard(b, true))}</ul> : <p className="text-sm text-muted-foreground">No upcoming bookings. Share your link: {page.public_url}</p>}
                        </section>
                        {past.length > 0 && <section className="space-y-3"><h3 className="font-medium">Past</h3><ul className="space-y-3">{past.map((b) => bookingCard(b, false))}</ul></section>}
                        {cancelled.length > 0 && <section className="space-y-3"><h3 className="font-medium">Cancelled</h3><ul className="space-y-3">{cancelled.map((b) => bookingCard(b, false))}</ul></section>}
                    </TabsContent>
                </Tabs>
            </div>

            <BookingTypeEditor pageUuid={page.uuid} timezone={page.timezone} type={editing} connections={connections} onClose={() => setEditing(null)} />

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add meeting type</DialogTitle>
                        <DialogDescription>Name it and pick a length, or start from a template. You can change everything afterwards.</DialogDescription>
                    </DialogHeader>
                    <form id="add-type" className="space-y-4" onSubmit={(e) => { e.preventDefault(); add.post(route('booking-types.store', page.uuid), { preserveScroll: true, onSuccess: () => { setAdding(false); add.reset(); } }); }}>
                        <div className="space-y-1.5">
                            <Label htmlFor="new-type-name">Name</Label>
                            <Input id="new-type-name" placeholder="e.g. Quick question" value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Length</Label>
                            <div className="flex flex-wrap items-center gap-2">
                                {DURATIONS.map((m) => <Button key={m} type="button" size="sm" variant={add.data.duration_minutes === m ? 'default' : 'outline'} onClick={() => add.setData('duration_minutes', m)}>{m} min</Button>)}
                                <Input type="number" min={5} max={720} aria-label="Custom length in minutes" className="w-24" value={add.data.duration_minutes} onChange={(e) => add.setData('duration_minutes', Number(e.target.value))} />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="new-type-template">Or start from a template</Label>
                            <select id="new-type-template" className={select} value={add.data.template} onChange={(e) => add.setData('template', e.target.value)}>
                                <option value="">No template</option>
                                {templateTypes.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
                            </select>
                            <p className="text-xs text-muted-foreground">A template brings hours, rules and questions; your name and length win when set.</p>
                        </div>
                        {Object.values(add.errors)[0] && <p className="text-sm text-destructive">{Object.values(add.errors)[0]}</p>}
                    </form>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setAdding(false)}>Cancel</Button>
                        <Button type="submit" form="add-type" disabled={add.processing}>Add</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={cancelling !== null} onOpenChange={(open) => !open && setCancelling(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel this booking?</DialogTitle>
                        <DialogDescription>{cancelling ? `${cancelling.guest_name} gets an email, and the event is removed from your calendar.` : ''}</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1.5">
                        <Label htmlFor="host-cancel-reason">Message to the guest (optional)</Label>
                        <Textarea id="host-cancel-reason" rows={3} value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setCancelling(null)}>Keep it</Button>
                        <Button variant="destructive" onClick={() => cancelling && router.post(route('bookings.cancel', cancelling.uuid), { reason: cancelReason || null }, { preserveScroll: true, onSuccess: () => setCancelling(null) })}>Cancel booking</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
