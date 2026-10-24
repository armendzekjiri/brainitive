import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, ArchiveRestore, ArrowRight, ExternalLink, Lightbulb, Lock, Pencil, Search, Sparkles, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import AppLayout from '@/Layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConvertCaptureDialog } from '@/components/captures/ConvertCaptureDialog';
import { openQuickCapture } from '@/components/captures/QuickCaptureDialog';
import { CAPTURE_TYPES, captureTypeLabel, capturedAgo } from '@/lib/captures';
import { cn } from '@/lib/utils';
import type { Capture, CaptureState, CaptureType, PageProps } from '@/types';

interface Props extends PageProps {
    captures: Capture[];
    state: CaptureState;
    search: string;
    counts: Record<CaptureState, number>;
    pagination: { next: string | null; prev: string | null };
}

const STATES: { key: CaptureState; label: string }[] = [
    { key: 'inbox', label: 'Inbox' },
    { key: 'processed', label: 'Processed' },
    { key: 'archived', label: 'Archived' },
];

const ACTION_OPTIONS = { preserveScroll: true, preserveState: true } as const;

/** Quick capture (BRAI-70): the private capture inbox. */
export default function CapturesIndex({ captures, state, search, counts, pagination }: Props) {
    const [query, setQuery] = useState(search);
    const [converting, setConverting] = useState<Capture | null>(null);
    const [editing, setEditing] = useState<Capture | null>(null);
    const [deleting, setDeleting] = useState<Capture | null>(null);
    const firstRender = useRef(true);

    // Debounced search, kept in the URL.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = window.setTimeout(() => {
            router.get(route('captures.index'), { state, ...(query.trim() ? { q: query.trim() } : {}) }, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
        return () => window.clearTimeout(timer);
        // only the typed query triggers a search; the state tab is a link
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const act = (name: 'captures.archive' | 'captures.restore', capture: Capture, done: string) =>
        router.post(route(name, capture.id), {}, {
            ...ACTION_OPTIONS,
            onSuccess: () => toast.success(done),
            onError: () => toast.error('Could not update the capture.'),
        });

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('captures.destroy', deleting.id), {
            ...ACTION_OPTIONS,
            onSuccess: () => toast.success('Capture deleted'),
            onError: () => toast.error('Could not delete the capture.'),
            onFinish: () => setDeleting(null),
        });
    };

    return (
        <AppLayout>
            <Head title="Captures" />
            <div className="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                            <Lightbulb className="h-6 w-6 text-amber-500" />
                            Captures
                        </h1>
                        <p className="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                            <Lock className="h-3.5 w-3.5" />
                            Your private inbox — only you can see it. Press
                            <kbd className="rounded border bg-muted px-1 font-mono text-[11px]">C</kbd>
                            anywhere to capture.
                        </p>
                    </div>
                    <Button onClick={openQuickCapture}>
                        <Lightbulb className="h-4 w-4" />
                        New capture
                    </Button>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <nav aria-label="Capture lists" className="flex gap-1 rounded-xl bg-muted p-1">
                        {STATES.map((s) => (
                            <Link
                                key={s.key}
                                href={route('captures.index', { state: s.key, ...(search ? { q: search } : {}) })}
                                preserveScroll
                                aria-current={state === s.key ? 'page' : undefined}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                    state === s.key ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {s.label}
                                <span className="rounded-full bg-muted-foreground/10 px-1.5 text-xs tabular-nums">{counts[s.key]}</span>
                            </Link>
                        ))}
                    </nav>
                    <div className="relative sm:w-72">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            aria-label="Search captures"
                            placeholder="Search captures…"
                            className="pl-9"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </div>
                </div>

                {captures.length === 0 ? (
                    <EmptyState state={state} searching={search !== ''} />
                ) : (
                    <ul className="space-y-3">
                        {captures.map((capture) => (
                            <CaptureRow
                                key={capture.id}
                                capture={capture}
                                onConvert={() => setConverting(capture)}
                                onEdit={() => setEditing(capture)}
                                onArchive={() => act('captures.archive', capture, 'Capture archived')}
                                onRestore={() => act('captures.restore', capture, 'Capture restored')}
                                onDelete={() => setDeleting(capture)}
                            />
                        ))}
                    </ul>
                )}

                {(pagination.prev || pagination.next) && (
                    <div className="flex justify-between">
                        {pagination.prev ? <Link href={pagination.prev} className="text-sm text-primary hover:underline">← Newer</Link> : <span />}
                        {pagination.next ? <Link href={pagination.next} className="text-sm text-primary hover:underline">Older →</Link> : <span />}
                    </div>
                )}
            </div>

            <ConvertCaptureDialog
                capture={converting}
                open={converting !== null}
                onOpenChange={(open) => { if (!open) setConverting(null); }}
                onConverted={() => router.reload({ only: ['captures', 'counts', 'captureInboxCount'] })}
            />
            <EditCaptureDialog capture={editing} onClose={() => setEditing(null)} />
            <AlertDialog open={deleting !== null} onOpenChange={(open) => { if (!open) setDeleting(null); }}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this capture?</AlertDialogTitle>
                        <AlertDialogDescription>It is removed for good. Content you already created from it stays.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={destroy} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}

function CaptureRow({ capture, onConvert, onEdit, onArchive, onRestore, onDelete }: {
    capture: Capture;
    onConvert: () => void;
    onEdit: () => void;
    onArchive: () => void;
    onRestore: () => void;
    onDelete: () => void;
}) {
    const firstLine = capture.text.split('\n')[0].slice(0, 80);

    return (
        <li>
            <article aria-label={`Capture: ${firstLine}`} className="rounded-2xl border bg-card p-4 shadow-sm">
                <p className="line-clamp-6 whitespace-pre-wrap break-words text-sm text-foreground">{capture.text}</p>
                {capture.url && (
                    <a
                        href={capture.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-2 inline-flex max-w-full items-center gap-1 text-sm text-primary hover:underline"
                    >
                        <ExternalLink className="h-3.5 w-3.5 shrink-0" />
                        <span className="truncate">{capture.url}</span>
                    </a>
                )}

                {capture.target && (
                    <div className="mt-3 flex items-center gap-2 rounded-xl bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-950/30 dark:text-green-200">
                        <ArrowRight className="h-4 w-4 shrink-0" />
                        {capture.target.available && capture.target.url ? (
                            <a href={capture.target.url} className="min-w-0 truncate font-medium hover:underline">
                                {captureTypeLabel(capture.target.type)}: {capture.target.title}
                            </a>
                        ) : (
                            <span className="min-w-0 truncate">
                                {captureTypeLabel(capture.target.type)}: {capture.target.title || 'created content'} (no longer available)
                            </span>
                        )}
                        <span className="hidden min-w-0 truncate text-xs opacity-70 sm:inline">{capture.target.breadcrumb}</span>
                    </div>
                )}

                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <div className="mr-auto flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        {capture.intended_type && <Badge variant="secondary">{captureTypeLabel(capture.intended_type)}</Badge>}
                        {capture.source && <span className="max-w-[16rem] truncate">from {capture.source}</span>}
                        <time dateTime={capture.captured_at ?? undefined} title={capture.captured_at ? new Date(capture.captured_at).toLocaleString() : undefined}>
                            {capturedAgo(capture.captured_at)}
                        </time>
                    </div>
                    {capture.state === 'inbox' && (
                        <Button size="sm" onClick={onConvert}>
                            <Sparkles className="h-4 w-4" />
                            Organize
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" onClick={onEdit} aria-label="Edit capture">
                        <Pencil className="h-4 w-4" />
                        <span className="hidden sm:inline">Edit</span>
                    </Button>
                    {capture.state === 'archived' ? (
                        <>
                            <Button size="sm" variant="ghost" onClick={onRestore}>
                                <ArchiveRestore className="h-4 w-4" />
                                Restore
                            </Button>
                            <Button size="sm" variant="ghost" onClick={onDelete} aria-label="Delete capture" className="text-destructive hover:text-destructive">
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </>
                    ) : (
                        <Button size="sm" variant="ghost" onClick={onArchive}>
                            <Archive className="h-4 w-4" />
                            Archive
                        </Button>
                    )}
                </div>
            </article>
        </li>
    );
}

type EditForm = { text: string; url: string; intended_type: CaptureType | ''; source: string };

function EditCaptureDialog({ capture, onClose }: { capture: Capture | null; onClose: () => void }) {
    const form = useForm<EditForm>({ text: '', url: '', intended_type: '', source: '' });

    useEffect(() => {
        if (!capture) return;
        form.setData({ text: capture.text, url: capture.url ?? '', intended_type: capture.intended_type ?? '', source: capture.source ?? '' });
        form.clearErrors();
        // reset only when another capture is opened
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [capture]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!capture) return;
        form.patch(route('captures.update', capture.id), {
            ...ACTION_OPTIONS,
            onSuccess: () => { toast.success('Capture updated'); onClose(); },
            onError: () => toast.error('Could not save the capture.'),
        });
    };

    return (
        <Dialog open={capture !== null} onOpenChange={(open) => { if (!open) onClose(); }}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit capture</DialogTitle>
                    <DialogDescription>Changes only this capture, never content created from it.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-capture-text">Idea</Label>
                        <Textarea id="edit-capture-text" rows={6} value={form.data.text} onChange={(e) => form.setData('text', e.target.value)} />
                        {form.errors.text && <p className="text-sm text-destructive">{form.errors.text}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-capture-url">Link</Label>
                            <Input id="edit-capture-url" type="url" value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} />
                            {form.errors.url && <p className="text-sm text-destructive">{form.errors.url}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="edit-capture-type">Organize as</Label>
                            <Select value={form.data.intended_type || 'none'} onValueChange={(v) => form.setData('intended_type', v === 'none' ? '' : (v as CaptureType))}>
                                <SelectTrigger id="edit-capture-type" aria-label="Organize as"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not sure yet</SelectItem>
                                    {CAPTURE_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="edit-capture-source">Source</Label>
                        <Input id="edit-capture-source" value={form.data.source} onChange={(e) => form.setData('source', e.target.value)} />
                        {form.errors.source && <p className="text-sm text-destructive">{form.errors.source}</p>}
                    </div>
                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EmptyState({ state, searching }: { state: CaptureState; searching: boolean }) {
    const text = searching
        ? 'No captures match your search.'
        : state === 'inbox'
            ? 'Your inbox is empty. Capture an idea with the button above, the Capture button in the top bar, or press C.'
            : state === 'processed'
                ? 'Nothing organized yet. Captures you turn into content show up here with a link.'
                : 'No archived captures.';

    return (
        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed py-16 text-center">
            <Lightbulb className="h-8 w-8 text-muted-foreground" />
            <p className="max-w-sm text-sm text-muted-foreground">{text}</p>
        </div>
    );
}
