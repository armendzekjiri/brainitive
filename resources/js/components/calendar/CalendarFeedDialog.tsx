import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import type { CalendarFeedOptions, CalendarFeedPayload } from '@/types';
import axios from 'axios';
import { formatDistanceToNow } from 'date-fns';
import { Check, Copy, ExternalLink, Loader2, Power, RefreshCw, Rss } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type BooleanOption = Exclude<keyof CalendarFeedOptions, 'project_ids'>;

const CONTENT_OPTIONS: { key: BooleanOption; label: string; hint: string }[] = [
    { key: 'assigned', label: 'Issues assigned to me', hint: 'Start and due dates' },
    { key: 'created', label: 'Issues I created', hint: 'Also when someone else works on them' },
    { key: 'tasks', label: 'List tasks', hint: 'Assigned to me, or my own unassigned tasks; repeating tasks repeat' },
    { key: 'my_day', label: 'My Day', hint: 'What I planned, on the day I planned it' },
    { key: 'focus', label: 'Focus sessions', hint: 'Time logged with the focus timer' },
];

const DETAIL_OPTIONS: { key: BooleanOption; label: string; hint: string }[] = [
    { key: 'completed', label: 'Include completed work', hint: 'Shown with a ✓' },
    { key: 'descriptions', label: 'Include descriptions', hint: 'Off: only titles, keys and a link back to Brainitive' },
];

function sameOptions(a: CalendarFeedOptions, b: CalendarFeedOptions): boolean {
    const norm = (o: CalendarFeedOptions) => JSON.stringify({ ...o, project_ids: [...o.project_ids].sort((x, y) => x - y) });
    return norm(a) === norm(b);
}

/**
 * "Subscribe in other apps" (BRAI-142): a private ICS link with the user's Brainitive dates for
 * Apple Calendar, Google Calendar or Outlook. Toolbar button + dialog; everything goes through the
 * `calendar-feed.*` JSON endpoints.
 */
export default function CalendarFeedButton() {
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<CalendarFeedPayload | null>(null);
    const [draft, setDraft] = useState<CalendarFeedOptions | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const feed = data?.feed ?? null;

    const apply = useCallback((payload: CalendarFeedPayload) => {
        setData(payload);
        setDraft(payload.feed?.options ?? payload.defaults);
    }, []);

    useEffect(() => {
        if (!open) return;
        setError(null);
        axios
            .get<CalendarFeedPayload>(route('calendar-feed.show'))
            .then(({ data: payload }) => apply(payload))
            .catch(() => setError('Couldn’t load your calendar feed.'));
    }, [open, apply]);

    const run = async (name: string, request: () => Promise<{ data: CalendarFeedPayload }>) => {
        setBusy(name);
        setError(null);
        try {
            apply((await request()).data);
        } catch (e: any) {
            setError(e.response?.data?.message ?? 'Something went wrong. Please try again.');
        } finally {
            setBusy(null);
        }
    };

    const create = () => run('create', () => axios.post(route('calendar-feed.store'), { options: draft }));
    const save = () => run('save', () => axios.patch(route('calendar-feed.update'), { options: draft }));
    const reset = () => {
        if (!window.confirm('Reset the link? Calendars subscribed with the current link stop updating — you’ll need to subscribe again with the new one.')) return;
        run('reset', () => axios.post(route('calendar-feed.reset')));
    };
    const turnOff = () => {
        if (!window.confirm('Turn the calendar feed off? The link stops working and subscribed calendars stop updating.')) return;
        run('off', () => axios.delete(route('calendar-feed.destroy')));
    };
    const copy = async () => {
        if (!feed) return;
        await navigator.clipboard?.writeText(feed.url).catch(() => undefined);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const dirty = !!feed && !!draft && !sameOptions(draft, feed.options);
    const toggle = (key: BooleanOption) => setDraft((d) => (d ? { ...d, [key]: !d[key] } : d));
    const toggleProject = (id: number) =>
        setDraft((d) => (d ? { ...d, project_ids: d.project_ids.includes(id) ? d.project_ids.filter((p) => p !== id) : [...d.project_ids, id] } : d));
    const nothingChosen = !!draft && !CONTENT_OPTIONS.some((o) => draft[o.key]);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Subscribe in other apps"
                title="Subscribe in other apps"
                className="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-xs font-medium border border-border bg-card text-muted-foreground hover:bg-secondary transition-colors"
            >
                <Rss className="size-3" />
                <span className="hidden sm:inline">Subscribe</span>
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Subscribe in other apps</DialogTitle>
                        <DialogDescription>
                            A private link with your Brainitive dates for Apple Calendar, Google Calendar or Outlook — on your phone and
                            desktop. It only shows what you can open in Brainitive.
                        </DialogDescription>
                    </DialogHeader>

                    {!data || !draft ? (
                        error ? <p className="text-sm text-destructive">{error}</p> : <Loader2 className="size-4 animate-spin text-muted-foreground" />
                    ) : (
                        <div className="space-y-5 min-w-0">
                            {feed && (
                                <section className="space-y-3" aria-label="Your calendar link">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <Input readOnly value={feed.url} aria-label="Calendar feed URL" className="font-mono text-xs min-w-0" onFocus={(e) => e.currentTarget.select()} />
                                        <Button size="sm" variant="outline" onClick={copy} className="shrink-0">
                                            {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
                                            {copied ? 'Copied' : 'Copy'}
                                        </Button>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button size="sm" variant="secondary" asChild>
                                            <a href={feed.links.apple}>Open in Apple Calendar</a>
                                        </Button>
                                        <Button size="sm" variant="secondary" asChild>
                                            <a href={feed.links.google} target="_blank" rel="noopener noreferrer">
                                                Add to Google Calendar <ExternalLink className="size-3" />
                                            </a>
                                        </Button>
                                        <Button size="sm" variant="secondary" asChild>
                                            <a href={feed.links.outlook} target="_blank" rel="noopener noreferrer">
                                                Add to Outlook <ExternalLink className="size-3" />
                                            </a>
                                        </Button>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {feed.last_fetched_at
                                            ? `Last fetched by a calendar app ${formatDistanceToNow(new Date(feed.last_fetched_at), { addSuffix: true })}.`
                                            : 'Not fetched by a calendar app yet.'}{' '}
                                        Anyone with this link can see these titles — reset it if it leaks.
                                    </p>
                                </section>
                            )}

                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium mb-1">What’s included</legend>
                                {CONTENT_OPTIONS.map((o) => (
                                    <OptionRow key={o.key} label={o.label} hint={o.hint} checked={draft[o.key]} onChange={() => toggle(o.key)} />
                                ))}
                                <div className="border-t border-border/60 pt-2 space-y-2">
                                    {DETAIL_OPTIONS.map((o) => (
                                        <OptionRow key={o.key} label={o.label} hint={o.hint} checked={draft[o.key]} onChange={() => toggle(o.key)} />
                                    ))}
                                </div>
                            </fieldset>

                            {data.projects.length > 1 && (
                                <fieldset className="space-y-1">
                                    <legend className="text-sm font-medium">Projects</legend>
                                    <p className="text-xs text-muted-foreground">
                                        {draft.project_ids.length === 0 ? 'All projects you can open.' : `Only ${draft.project_ids.length} selected.`} Tick projects to limit
                                        the feed to them.
                                    </p>
                                    <div className="max-h-36 overflow-y-auto rounded-md border border-border p-2 space-y-1">
                                        {data.projects.map((p) => (
                                            <label key={p.id} className="flex items-center gap-2 text-sm cursor-pointer min-w-0">
                                                <input type="checkbox" className="size-4 accent-primary shrink-0" checked={draft.project_ids.includes(p.id)} onChange={() => toggleProject(p.id)} />
                                                <span className="truncate">{p.name}</span>
                                                {p.workspace && <span className="text-xs text-muted-foreground truncate">· {p.workspace}</span>}
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            )}

                            {nothingChosen && <p className="text-xs text-amber-700 dark:text-amber-400">Nothing is selected — the calendar will be empty.</p>}
                            {error && <p className="text-sm text-destructive">{error}</p>}

                            <div className="flex flex-wrap items-center gap-2">
                                {!feed ? (
                                    <Button onClick={create} disabled={busy !== null}>
                                        {busy === 'create' ? <Loader2 className="size-4 animate-spin" /> : <Rss className="size-4" />} Create calendar link
                                    </Button>
                                ) : (
                                    <>
                                        <Button onClick={save} disabled={!dirty || busy !== null}>
                                            {busy === 'save' && <Loader2 className="size-4 animate-spin" />} Save changes
                                        </Button>
                                        <div className="flex-1" />
                                        <Button variant="ghost" size="sm" onClick={reset} disabled={busy !== null}>
                                            <RefreshCw className="size-3.5" /> Reset link
                                        </Button>
                                        <Button variant="ghost" size="sm" className="text-destructive hover:text-destructive" onClick={turnOff} disabled={busy !== null}>
                                            <Power className="size-3.5" /> Turn off
                                        </Button>
                                    </>
                                )}
                            </div>

                            <p className="text-xs text-muted-foreground">
                                Calendar apps refresh subscriptions on their own schedule: Apple Calendar and Outlook every 15 minutes to a few hours,
                                Google Calendar can take several hours. Changes here and in Brainitive show up there after the next refresh. The
                                feed covers the last 60 days and the next year.
                            </p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function OptionRow({ label, hint, checked, onChange }: { label: string; hint: string; checked: boolean; onChange: () => void }) {
    return (
        <label className="flex items-start gap-2.5 cursor-pointer">
            <input type="checkbox" className="size-4 mt-0.5 accent-primary shrink-0" checked={checked} onChange={onChange} />
            <span className="min-w-0">
                <span className="block text-sm">{label}</span>
                <span className="block text-xs text-muted-foreground">{hint}</span>
            </span>
        </label>
    );
}
