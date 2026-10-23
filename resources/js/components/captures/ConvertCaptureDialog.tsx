import { FormEvent, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { CheckCircle2, ExternalLink, Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { CAPTURE_TYPES, destinationOptions, type DestinationOption } from '@/lib/captures';
import type { Capture, CaptureConversionResult, CaptureDestinationWorkspace, CaptureType } from '@/types';

type Fields = Record<string, string | null>;

interface Props {
    capture: Capture | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Called after a successful (or replayed) conversion, to refresh the list. */
    onConverted: (result: CaptureConversionResult) => void;
}

/** Field editors per destination type: [field key, label, multiline]. */
const FIELD_LAYOUT: Record<CaptureType, [string, string, boolean][]> = {
    issue: [['title', 'Title', false], ['description', 'Description (Markdown)', true]],
    task: [['title', 'Title', false], ['notes', 'Notes (Markdown)', true]],
    page: [['title', 'Title', false], ['markdown', 'Content (Markdown)', true]],
    bookmark: [['title', 'Title', false], ['url', 'URL', false], ['content', 'Note', true]],
    snippet: [['title', 'Title', false], ['language', 'Language', false], ['content', 'Code', true], ['description', 'Description', false]],
};

/**
 * Organize a capture (BRAI-70): pick what it becomes and where, check the prefilled fields
 * (preview from `captures.preview`), create. The server converts atomically and only once; a
 * retry returns the same content. On success the dialog shows the canonical link.
 */
export function ConvertCaptureDialog({ capture, open, onOpenChange, onConverted }: Props) {
    const [type, setType] = useState<CaptureType>('issue');
    const [workspaces, setWorkspaces] = useState<CaptureDestinationWorkspace[] | null>(null);
    const [destinationId, setDestinationId] = useState('');
    const [columnId, setColumnId] = useState('default');
    const [fields, setFields] = useState<Fields | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [result, setResult] = useState<CaptureConversionResult | null>(null);

    // Fresh state every time the dialog opens for a capture.
    useEffect(() => {
        if (!open || !capture) return;
        setType(capture.intended_type ?? 'issue');
        setResult(null);
        setErrors({});
        setMessage(null);
        setDestinationId('');
        setColumnId('default');
        const controller = new AbortController();
        axios.get<{ workspaces: CaptureDestinationWorkspace[] }>(route('captures.destinations'), { signal: controller.signal })
            .then((r) => setWorkspaces(r.data.workspaces))
            .catch((e) => { if (!axios.isCancel(e)) setMessage('Could not load your destinations.'); });
        return () => controller.abort();
    }, [open, capture]);

    // Preview fields for the chosen type.
    useEffect(() => {
        if (!open || !capture) return;
        setFields(null);
        const controller = new AbortController();
        axios.get<{ fields: Fields }>(route('captures.preview', capture.id), { params: { type }, signal: controller.signal })
            .then((r) => setFields(r.data.fields))
            .catch((e) => { if (!axios.isCancel(e)) setMessage('Could not prepare the preview.'); });
        return () => controller.abort();
    }, [open, capture, type]);

    const options = useMemo(() => (workspaces ? destinationOptions(workspaces, type) : []), [workspaces, type]);
    const groups = useMemo(() => {
        const map = new Map<string, DestinationOption[]>();
        options.forEach((o) => map.set(o.group, [...(map.get(o.group) ?? []), o]));
        return [...map.entries()];
    }, [options]);
    const selected = options.find((o) => String(o.id) === destinationId);
    const noun = CAPTURE_TYPES.find((t) => t.value === type)?.noun ?? 'item';

    const chooseType = (next: CaptureType) => {
        setType(next);
        setDestinationId('');
        setColumnId('default');
        setErrors({});
        setMessage(null);
    };

    const setField = (key: string, value: string) => setFields((f) => ({ ...(f ?? {}), [key]: value }));

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        if (!capture || !fields || !selected || submitting) return;
        setSubmitting(true);
        setErrors({});
        setMessage(null);
        const payload: Fields = { ...fields };
        if (type === 'issue' && columnId !== 'default') payload.column_id = columnId;
        if (type === 'bookmark' && payload.type === 'note') payload.url = null;
        try {
            const { data } = await axios.post<CaptureConversionResult>(route('captures.convert', capture.id), {
                type,
                destination_id: selected.id,
                fields: payload,
            });
            setResult(data);
            onConverted(data);
            toast.success(data.replayed ? 'Already organized' : `Created ${data.location.title}`);
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 422) {
                const raw = (error.response.data?.errors ?? {}) as Record<string, string[]>;
                const flat: Record<string, string> = {};
                Object.entries(raw).forEach(([k, v]) => { flat[k.replace(/^fields\./, '')] = v[0]; });
                setErrors(flat);
                setMessage(flat.capture ?? flat.destination_id ?? flat.type ?? 'Please check the highlighted fields.');
            } else if (axios.isAxiosError(error) && error.response?.status === 403) {
                setMessage(`You can't add a ${noun} there. Your capture stays in the inbox.`);
            } else if (axios.isAxiosError(error) && error.response?.status === 404) {
                setMessage('This capture no longer exists.');
            } else {
                setMessage('Something went wrong. Your capture stays in the inbox — try again.');
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Organize capture</DialogTitle>
                    <DialogDescription>
                        Choose what it becomes and where. You need the usual rights there; your capture stays in the inbox until the
                        content is created.
                    </DialogDescription>
                </DialogHeader>

                {result ? (
                    <div className="space-y-4" role="status">
                        <div className="flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 p-4 text-green-800 dark:border-green-900/60 dark:bg-green-950/30 dark:text-green-200">
                            <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" />
                            <div className="min-w-0">
                                <p className="font-medium">{result.replayed ? 'Already organized' : 'Created'}: {result.location.title}</p>
                                <p className="truncate text-sm opacity-80">{result.location.breadcrumb}</p>
                            </div>
                        </div>
                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Close</Button>
                            {result.location.url ? (
                                <Button asChild>
                                    <a href={result.location.url}>
                                        <ExternalLink className="h-4 w-4" />
                                        Open {result.location.type}
                                    </a>
                                </Button>
                            ) : (
                                <p className="self-center text-sm text-muted-foreground">It is no longer available to you.</p>
                            )}
                        </DialogFooter>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label id="convert-type-label">Turn into</Label>
                            <div className="flex flex-wrap gap-2" role="group" aria-labelledby="convert-type-label">
                                {CAPTURE_TYPES.map((t) => (
                                    <Button
                                        key={t.value}
                                        type="button"
                                        size="sm"
                                        variant={type === t.value ? 'default' : 'outline'}
                                        aria-pressed={type === t.value}
                                        onClick={() => chooseType(t.value)}
                                    >
                                        {t.label}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className={cn('space-y-1.5', type !== 'issue' && 'sm:col-span-2')}>
                                <Label htmlFor="convert-destination">Destination</Label>
                                <Select value={destinationId} onValueChange={(v) => { setDestinationId(v); setColumnId('default'); }} disabled={!workspaces}>
                                    <SelectTrigger id="convert-destination" aria-label="Destination">
                                        <SelectValue placeholder={workspaces ? (options.length ? 'Choose…' : 'Nowhere you can add this') : 'Loading…'} />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {groups.map(([group, items]) => (
                                            <SelectGroup key={group}>
                                                <SelectLabel>{group}</SelectLabel>
                                                {items.map((o) => (
                                                    <SelectItem key={o.id} value={String(o.id)}>{o.label}</SelectItem>
                                                ))}
                                            </SelectGroup>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.destination_id && <p className="text-sm text-destructive">{errors.destination_id}</p>}
                            </div>
                            {type === 'issue' && (
                                <div className="space-y-1.5">
                                    <Label htmlFor="convert-column">Column</Label>
                                    <Select value={columnId} onValueChange={setColumnId} disabled={!selected}>
                                        <SelectTrigger id="convert-column" aria-label="Column">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="default">First column</SelectItem>
                                            {selected?.columns?.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.column_id && <p className="text-sm text-destructive">{errors.column_id}</p>}
                                </div>
                            )}
                        </div>

                        <div className="space-y-3 rounded-xl border bg-muted/30 p-3">
                            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Preview — edit before creating</p>
                            {!fields ? (
                                <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                                    <Loader2 className="h-4 w-4 animate-spin" /> Preparing…
                                </div>
                            ) : (
                                <>
                                    {type === 'bookmark' && (
                                        <div className="flex gap-2" role="group" aria-label="Bookmark kind">
                                            {(['link', 'note'] as const).map((kind) => (
                                                <Button
                                                    key={kind}
                                                    type="button"
                                                    size="sm"
                                                    variant={fields.type === kind ? 'secondary' : 'ghost'}
                                                    aria-pressed={fields.type === kind}
                                                    onClick={() => setField('type', kind)}
                                                >
                                                    {kind === 'link' ? 'Link' : 'Note'}
                                                </Button>
                                            ))}
                                        </div>
                                    )}
                                    {FIELD_LAYOUT[type]
                                        .filter(([key]) => !(type === 'bookmark' && key === 'url' && fields.type === 'note'))
                                        .map(([key, label, multiline]) => (
                                            <div key={key} className="space-y-1.5">
                                                <Label htmlFor={`convert-field-${key}`}>{label}</Label>
                                                {multiline ? (
                                                    <Textarea
                                                        id={`convert-field-${key}`}
                                                        rows={type === 'snippet' ? 8 : 5}
                                                        className={cn(type === 'snippet' && 'font-mono text-xs')}
                                                        value={fields[key] ?? ''}
                                                        onChange={(e) => setField(key, e.target.value)}
                                                    />
                                                ) : (
                                                    <Input id={`convert-field-${key}`} value={fields[key] ?? ''} onChange={(e) => setField(key, e.target.value)} />
                                                )}
                                                {errors[key] && <p className="text-sm text-destructive">{errors[key]}</p>}
                                            </div>
                                        ))}
                                </>
                            )}
                        </div>

                        {message && <p role="alert" className="text-sm text-destructive">{message}</p>}

                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                            <Button type="submit" disabled={!fields || !selected || submitting}>
                                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                                Create {noun}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
