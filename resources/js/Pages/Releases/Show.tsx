import AppLayout from '@/Layouts/AppLayout';
import { ReleaseFormDialog, ReleaseProgressBar, ReleaseStatusBadge, ReleaseSummary, dueText, formatDay } from '@/components/releases/ReleaseBits';
import { RichLexicalEditor } from '@/components/editor/RichLexicalEditorFull';
import {
    AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription,
    AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { safeHtmlProp } from '@/lib/sanitize';
import type { Project, Workspace } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { format } from 'date-fns';
import { ArrowLeft, Check, Copy, ExternalLink, Loader2, MoreHorizontal, Plus, Rocket, Search, Sparkles, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

interface ReleaseDetail extends ReleaseSummary {
    notes: string | null;
    published_notes: string | null;
    public_url: string | null;
    notes_changed: boolean;
}

interface ReleaseItem {
    id: number;
    key: string;
    title: string;
    state: 'done' | 'in_progress' | 'todo';
    status: string | null;
    type: { name: string; slug: string; color?: string | null } | null;
    assignee: { id: number; name: string } | null;
    is_subtask: boolean;
    url: string | null;
}

interface Candidate {
    id: number;
    key: string;
    title: string;
    done: boolean;
    release: { name: string; status: string } | null;
}

interface Props {
    workspace: Workspace;
    project: Project;
    release: ReleaseDetail;
    items: ReleaseItem[];
    otherReleases: { id: number; name: string }[];
    canManage: boolean;
}

const STATE_GROUPS: { state: ReleaseItem['state']; label: string; dot: string }[] = [
    { state: 'todo', label: 'To do', dot: 'bg-slate-400' },
    { state: 'in_progress', label: 'In progress', dot: 'bg-blue-500' },
    { state: 'done', label: 'Done', dot: 'bg-green-500' },
];

function AddIssuesDialog({ open, onOpenChange, candidatesUrl, addUrl }: { open: boolean; onOpenChange: (o: boolean) => void; candidatesUrl: string; addUrl: string }) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Candidate[]>([]);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState<Map<number, Candidate>>(new Map());

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setLoading(true);
        const t = setTimeout(() => {
            axios.get(candidatesUrl, { params: { q }, signal: controller.signal })
                .then(({ data }) => setResults(data))
                .catch(() => {})
                .finally(() => setLoading(false));
        }, 200);
        return () => { clearTimeout(t); controller.abort(); };
    }, [q, open, candidatesUrl]);

    useEffect(() => { if (!open) { setQ(''); setSelected(new Map()); } }, [open]);

    const toggle = (c: Candidate) => setSelected((prev) => {
        const next = new Map(prev);
        if (next.has(c.id)) next.delete(c.id); else next.set(c.id, c);
        return next;
    });
    const shipped = [...selected.values()].filter((c) => c.release?.status === 'released');

    const add = () => router.post(addUrl, { item_ids: [...selected.keys()] }, { preserveScroll: true, onSuccess: () => onOpenChange(false) });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add issues</DialogTitle>
                    <DialogDescription>Issues of this project. One fix version per issue — picking an issue from another release moves it here.</DialogDescription>
                </DialogHeader>
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search by key or title" className="pl-9" aria-label="Search issues" autoFocus />
                </div>
                <div className="max-h-[50vh] overflow-y-auto rounded-md border">
                    {loading && results.length === 0 && <p className="p-4 text-center text-sm text-muted-foreground">Loading…</p>}
                    {!loading && results.length === 0 && <p className="p-4 text-center text-sm text-muted-foreground">No issues found.</p>}
                    <ul className="divide-y">
                        {results.map((c) => (
                            <li key={c.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-muted/50">
                                    <input type="checkbox" className="size-4 shrink-0 rounded border-input accent-primary" checked={selected.has(c.id)} onChange={() => toggle(c)} aria-label={`Select ${c.key}`} />
                                    <span className="shrink-0 font-mono text-xs text-muted-foreground">{c.key}</span>
                                    <span className={`min-w-0 flex-1 truncate text-sm ${c.done ? 'text-muted-foreground line-through' : ''}`}>{c.title}</span>
                                    {c.release && (
                                        <span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium ${c.release.status === 'released' ? 'bg-amber-500/15 text-amber-700 dark:text-amber-400' : 'bg-muted text-muted-foreground'}`}>
                                            {c.release.status === 'released' ? `shipped in ${c.release.name}` : c.release.name}
                                        </span>
                                    )}
                                </label>
                            </li>
                        ))}
                    </ul>
                </div>
                {shipped.length > 0 && (
                    <p className="rounded-md border border-amber-500/30 bg-amber-500/10 p-2 text-sm text-amber-800 dark:text-amber-300" role="alert">
                        {shipped.map((c) => c.key).join(', ')} {shipped.length === 1 ? 'was' : 'were'} already released in {[...new Set(shipped.map((c) => c.release!.name))].join(', ')}. Adding moves {shipped.length === 1 ? 'it' : 'them'} out of that release.
                    </p>
                )}
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button onClick={add} disabled={selected.size === 0}>Add {selected.size || ''} issue{selected.size === 1 ? '' : 's'}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ReleaseDialog({ open, onOpenChange, url, unfinished, otherReleases }: { open: boolean; onOpenChange: (o: boolean) => void; url: string; unfinished: number; otherReleases: { id: number; name: string }[] }) {
    const [date, setDate] = useState(format(new Date(), 'yyyy-MM-dd'));
    const [target, setTarget] = useState<string>(otherReleases[0] ? String(otherReleases[0].id) : 'none');
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        router.post(url, { release_date: date, move_unfinished_to: target === 'none' ? null : Number(target) }, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Release this version</DialogTitle>
                    <DialogDescription>Marks it as shipped. You can still edit and publish its release notes afterwards.</DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="release-on">Release date</Label>
                        <Input id="release-on" type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
                    </div>
                    {unfinished > 0 && (
                        <div className="space-y-1.5">
                            <Label>{unfinished} unfinished issue{unfinished === 1 ? '' : 's'} — what should happen?</Label>
                            <Select value={target} onValueChange={setTarget}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {otherReleases.map((r) => <SelectItem key={r.id} value={String(r.id)}>Move to {r.name}</SelectItem>)}
                                    <SelectItem value="none">Remove the fix version</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button onClick={submit} disabled={processing || !date}><Rocket className="size-4" /> Release</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function NotesTab({ release, canManage, urls }: { release: ReleaseDetail; canManage: boolean; urls: { draft: string; save: string; publish: string } }) {
    const [notes, setNotes] = useState(release.notes ?? '');
    const [editorKey, setEditorKey] = useState(0);
    const [generating, setGenerating] = useState(false);
    const [confirmReplace, setConfirmReplace] = useState(false);
    const [copied, setCopied] = useState(false);
    const dirty = notes !== (release.notes ?? '');

    useEffect(() => { setNotes(release.notes ?? ''); setEditorKey((k) => k + 1); }, [release.notes]);

    const generate = async () => {
        setConfirmReplace(false);
        setGenerating(true);
        try {
            const { data } = await axios.get(urls.draft);
            setNotes(data.html);
            setEditorKey((k) => k + 1);
            toast.success('Draft generated from the finished issues — review it, then save.');
        } catch {
            toast.error('Could not generate the draft.');
        } finally {
            setGenerating(false);
        }
    };

    const save = (then?: () => void) => router.put(urls.save, { notes }, { preserveScroll: true, preserveState: true, onSuccess: () => then?.() });
    const publish = () => (dirty ? save(() => router.post(urls.publish, {}, { preserveScroll: true, preserveState: true })) : router.post(urls.publish, {}, { preserveScroll: true, preserveState: true }));
    const unpublish = () => router.delete(urls.publish, { preserveScroll: true, preserveState: true });
    const copy = async () => {
        if (!release.public_url) return;
        await navigator.clipboard.writeText(release.public_url).catch(() => {});
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    if (!canManage) {
        const html = release.published_notes ?? release.notes;
        return html
            ? <article className="rich-html prose prose-sm dark:prose-invert max-w-none rounded-lg border bg-card p-6" dangerouslySetInnerHTML={safeHtmlProp(html)} />
            : <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">No release notes yet.</p>;
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <Button variant="outline" onClick={() => (notes.trim() ? setConfirmReplace(true) : generate())} disabled={generating}>
                    {generating ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />} Generate from done issues
                </Button>
                <span className="text-xs text-muted-foreground">Titles of finished issues, grouped into Features, Improvements and Fixes. No keys or internal links.</span>
            </div>

            <div className="rounded-lg border bg-card">
                <RichLexicalEditor key={editorKey} content={notes} onChange={setNotes} editable showToolbar minHeight="240px" placeholder="What’s new in this release?" contentKey={`release-notes-${release.id}-${editorKey}`} />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button variant="outline" onClick={() => save()} disabled={!dirty}>Save draft</Button>
                <Button onClick={publish} disabled={!notes.trim()}>
                    {release.published ? (release.notes_changed || dirty ? 'Publish update' : 'Published') : 'Publish'}
                </Button>
                {release.published && <Button variant="ghost" className="text-destructive hover:text-destructive" onClick={unpublish}>Unpublish</Button>}
                {dirty && <span className="text-xs text-amber-700 dark:text-amber-400">Unsaved changes</span>}
                {!dirty && release.notes_changed && <span className="text-xs text-amber-700 dark:text-amber-400">The public page still shows the previous version.</span>}
            </div>

            {release.public_url && (
                <div className="flex flex-wrap items-center gap-2 rounded-md border bg-muted/40 p-3 text-sm">
                    <span className="text-muted-foreground">Public page:</span>
                    <a href={release.public_url} target="_blank" rel="noopener noreferrer" className="min-w-0 truncate font-medium text-primary hover:underline">{release.public_url}</a>
                    <Button variant="ghost" size="icon" className="size-7" onClick={copy} aria-label="Copy public link">{copied ? <Check className="size-3.5 text-green-600" /> : <Copy className="size-3.5" />}</Button>
                </div>
            )}

            <AlertDialog open={confirmReplace} onOpenChange={setConfirmReplace}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Replace the current notes?</AlertDialogTitle>
                        <AlertDialogDescription>The editor gets a fresh draft from the finished issues. Nothing is saved until you click “Save draft”.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={generate}>Replace</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

export default function ReleasesShow({ workspace, project, release, items, otherReleases, canManage }: Props) {
    const params = [workspace.slug, project.slug, release.id];
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);
    const [releasing, setReleasing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const byState = useMemo(() => Object.fromEntries(STATE_GROUPS.map((g) => [g.state, items.filter((i) => i.state === g.state)])) as Record<ReleaseItem['state'], ReleaseItem[]>, [items]);
    const unfinished = items.filter((i) => i.state !== 'done').length;
    const setStatus = (status: string) => router.post(route('releases.status', params), { status }, { preserveScroll: true });
    const due = dueText(release);

    return (
        <AppLayout>
            <Head title={`${release.name} · Releases`} />
            <div className="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6">
                <Link href={route('releases.index', [workspace.slug, project.slug])} className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> Releases
                </Link>

                <div className="mb-6 flex flex-wrap items-start gap-4">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold">{release.name}</h1>
                            <ReleaseStatusBadge release={release} />
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {[release.start_date && `Start ${formatDay(release.start_date)}`, release.release_date && `${release.status === 'unreleased' ? 'Release' : 'Released'} ${formatDay(release.release_date)}`, due].filter(Boolean).join(' · ') || 'No dates yet'}
                        </p>
                        {release.description && <p className="mt-2 max-w-2xl whitespace-pre-line text-sm">{release.description}</p>}
                    </div>
                    {canManage && (
                        <div className="flex items-center gap-2">
                            {release.status === 'unreleased' && <Button onClick={() => setReleasing(true)}><Rocket className="size-4" /> Release</Button>}
                            <Button variant="outline" onClick={() => setEditing(true)}>Edit</Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="icon" aria-label="More actions"><MoreHorizontal className="size-4" /></Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {release.status === 'released' && <DropdownMenuItem onClick={() => setStatus('unreleased')}>Mark as unreleased</DropdownMenuItem>}
                                    {release.status !== 'archived' && <DropdownMenuItem onClick={() => setStatus('archived')}>Archive</DropdownMenuItem>}
                                    {release.status === 'archived' && <DropdownMenuItem onClick={() => setStatus(release.released_at ? 'released' : 'unreleased')}>Restore</DropdownMenuItem>}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem className="text-destructive focus:text-destructive" onClick={() => setDeleting(true)}>Delete…</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    )}
                </div>

                <ReleaseProgressBar progress={release.progress} className="mb-6" />

                <Tabs defaultValue="issues">
                    <TabsList>
                        <TabsTrigger value="issues">Issues ({items.length})</TabsTrigger>
                        <TabsTrigger value="notes">Release notes{release.published ? ' · public' : ''}</TabsTrigger>
                    </TabsList>

                    <TabsContent value="issues" className="mt-4 space-y-6">
                        {canManage && release.status !== 'archived' && (
                            <Button variant="outline" onClick={() => setAdding(true)}><Plus className="size-4" /> Add issues</Button>
                        )}
                        {items.length === 0 && (
                            <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                No issues yet. Set “Fix version” on an issue{canManage ? ' or add issues here' : ''}.
                            </p>
                        )}
                        {STATE_GROUPS.filter((g) => byState[g.state].length > 0).map((g) => (
                            <section key={g.state} aria-label={g.label}>
                                <h2 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    <span className={`size-2 rounded-full ${g.dot}`} /> {g.label} · {byState[g.state].length}
                                </h2>
                                <ul className="divide-y rounded-lg border bg-card">
                                    {byState[g.state].map((i) => (
                                        <li key={i.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                                            <span className="w-20 shrink-0 font-mono text-xs text-muted-foreground">{i.key}</span>
                                            {i.url
                                                ? <Link href={i.url} className={`min-w-0 flex-1 truncate hover:underline ${i.state === 'done' ? 'text-muted-foreground' : ''}`}>{i.title}</Link>
                                                : <span className="min-w-0 flex-1 truncate">{i.title}</span>}
                                            {i.type && <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">{i.type.name}</span>}
                                            {i.status && <span className="hidden shrink-0 rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground md:inline">{i.status}</span>}
                                            {i.assignee && (
                                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold" title={i.assignee.name}>
                                                    {i.assignee.name.slice(0, 1).toUpperCase()}
                                                </span>
                                            )}
                                            {canManage && (
                                                <Button variant="ghost" size="icon" className="size-7 shrink-0 text-muted-foreground" aria-label={`Remove ${i.key} from this release`}
                                                    onClick={() => router.delete(route('releases.items.remove', [...params, i.id]), { preserveScroll: true })}>
                                                    <X className="size-3.5" />
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </TabsContent>

                    <TabsContent value="notes" className="mt-4">
                        <NotesTab release={release} canManage={canManage} urls={{
                            draft: route('releases.notes.draft', params),
                            save: route('releases.notes.save', params),
                            publish: route('releases.publish', params),
                        }} />
                        {release.public_url && !canManage && (
                            <a href={release.public_url} target="_blank" rel="noopener noreferrer" className="mt-3 inline-flex items-center gap-1 text-sm text-primary hover:underline">
                                <ExternalLink className="size-3.5" /> Public page
                            </a>
                        )}
                    </TabsContent>
                </Tabs>
            </div>

            {canManage && (
                <>
                    <ReleaseFormDialog open={editing} onOpenChange={setEditing} url={route('releases.update', params)} method="put" release={release} />
                    <AddIssuesDialog open={adding} onOpenChange={setAdding} candidatesUrl={route('releases.candidates', params)} addUrl={route('releases.items.add', params)} />
                    <ReleaseDialog open={releasing} onOpenChange={setReleasing} url={route('releases.release', params)} unfinished={unfinished} otherReleases={otherReleases} />
                    <AlertDialog open={deleting} onOpenChange={setDeleting}>
                        <AlertDialogContent>
                            <AlertDialogHeader>
                                <AlertDialogTitle>Delete {release.name}?</AlertDialogTitle>
                                <AlertDialogDescription>The {items.length} issue{items.length === 1 ? '' : 's'} stay — they just lose this fix version. Published notes go offline.</AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <AlertDialogAction className="bg-destructive text-destructive-foreground hover:bg-destructive/90" onClick={() => router.delete(route('releases.destroy', params))}>Delete</AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>
                </>
            )}
        </AppLayout>
    );
}
