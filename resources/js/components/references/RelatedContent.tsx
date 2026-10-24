import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CheckSquare, FileText, GanttChart, LifeBuoy, Link2, Loader2, Network, PencilRuler, Plus, Search, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type {
    ContentReferenceCandidate, ContentReferenceEntry, ContentReferenceList, ContentReferenceSummary, ContentReferenceType,
} from '@/types';

/**
 * Linked content and backlinks (BRAI-71): what this issue / doc / whiteboard / ticket / plan
 * links to ("Related content") and what links to it ("Referenced by"). Plan rows and
 * whiteboard cards linked to an issue appear as read-only derived entries. The server only
 * returns what the viewer may see.
 */
export default function RelatedContent({ workspaceSlug, projectSlug, type, id, refreshKey, className }: {
    workspaceSlug: string;
    projectSlug: string;
    type: ContentReferenceType;
    id: number;
    /** Reload when this changes (e.g. after the resource itself was saved). */
    refreshKey?: string | number;
    className?: string;
}) {
    const [data, setData] = useState<ContentReferenceList | null>(null);
    const [failed, setFailed] = useState(false);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [removing, setRemoving] = useState<number | null>(null);
    const requestRef = useRef(0);
    const loadedRef = useRef(false);

    const load = useCallback(() => {
        const request = ++requestRef.current;
        window.axios
            .get<ContentReferenceList>(route('references.index', [workspaceSlug, projectSlug]), { params: { type, id } })
            .then(({ data: list }) => {
                if (request !== requestRef.current) return;
                loadedRef.current = true;
                setData(list);
                setFailed(false);
            })
            .catch(() => {
                if (request !== requestRef.current) return;
                // a failed refresh keeps showing the last good lists
                if (loadedRef.current) toast.error('Related content couldn’t be refreshed.');
                else setFailed(true);
            });
    }, [workspaceSlug, projectSlug, type, id]);

    // Another resource: start empty. A refresh of the same one keeps the lists while reloading.
    useEffect(() => {
        loadedRef.current = false;
        setData(null);
        setFailed(false);
    }, [workspaceSlug, projectSlug, type, id]);

    useEffect(() => {
        load();
    }, [load, refreshKey]);

    const remove = (entry: ContentReferenceEntry) => {
        // one removal at a time
        if (!entry.reference_id || removing !== null) return;
        setRemoving(entry.reference_id);
        window.axios
            .delete(route('references.destroy', [workspaceSlug, projectSlug, entry.reference_id]))
            .then(() => {
                toast.success(`Link to “${entry.title}” removed`);
                load();
            })
            .catch((error) => {
                // already gone (removed in another tab): just show the current state
                if ((error as { response?: { status?: number } })?.response?.status === 404) {
                    load();
                    return;
                }
                toast.error(errorMessage(error, 'The link couldn’t be removed.'));
            })
            .finally(() => setRemoving(null));
    };

    const related = data?.related ?? [];
    const referencedBy = data?.referenced_by ?? [];

    return (
        <section aria-label="Related content" className={cn('py-5 border-t border-border/60', className)}>
            <div className="mb-3 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Network className="size-4 text-muted-foreground" />
                    Related content
                    {related.length > 0 && <span className="text-xs font-normal text-muted-foreground">({related.length})</span>}
                </h2>
                {data?.can_link && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                        onClick={() => setPickerOpen(true)}
                        aria-label="Link related content"
                    >
                        <Plus className="size-3.5" />Link
                    </Button>
                )}
            </div>

            {data === null && !failed && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="size-3.5 animate-spin" />Loading…</p>
            )}
            {failed && (
                <p className="text-sm text-muted-foreground">
                    Related content couldn’t be loaded. <button type="button" className="text-primary hover:underline" onClick={load}>Try again</button>
                </p>
            )}
            {data && (
                <>
                    <EntryList entries={related} empty="Nothing linked yet." removing={removing} onRemove={remove} label="Related content" />
                    <h3 className="mb-2 mt-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        <Link2 className="size-3.5" />
                        Referenced by
                        {referencedBy.length > 0 && <span className="font-normal normal-case">({referencedBy.length})</span>}
                    </h3>
                    <EntryList entries={referencedBy} empty="Nothing links here yet." removing={removing} onRemove={remove} label="Referenced by" />
                </>
            )}

            {data?.can_link && (
                <LinkContentDialog
                    open={pickerOpen}
                    onOpenChange={setPickerOpen}
                    workspaceSlug={workspaceSlug}
                    projectSlug={projectSlug}
                    type={type}
                    id={id}
                    onLinked={load}
                />
            )}
        </section>
    );
}

function EntryList({ entries, empty, removing, onRemove, label }: {
    entries: ContentReferenceEntry[];
    empty: string;
    removing: number | null;
    onRemove: (entry: ContentReferenceEntry) => void;
    label: string;
}) {
    if (entries.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <ul className="space-y-0.5" aria-label={label}>
            {entries.map((entry) => (
                <li key={entry.key} className="group -mx-2 flex items-center justify-between gap-2 rounded-md px-2 py-1.5 transition-colors hover:bg-muted/40">
                    <a href={entry.url} className="flex min-w-0 items-center gap-2 text-sm">
                        <TypeIcon entry={entry} />
                        {entry.subtitle && <span className="shrink-0 font-mono text-xs text-muted-foreground">{entry.subtitle}</span>}
                        <span className="truncate text-primary group-hover:underline">{entry.title}</span>
                    </a>
                    <span className="flex shrink-0 items-center gap-1.5">
                        {entry.status && (
                            <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium capitalize text-muted-foreground">{entry.status}</span>
                        )}
                        {entry.derived && (
                            <Badge
                                variant="secondary"
                                className="rounded-full px-2 py-0 text-[10px] font-medium"
                                title="Comes from the plan or whiteboard; change it there"
                            >
                                {entry.derived_label}
                            </Badge>
                        )}
                        {entry.can_remove && entry.reference_id !== null && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-6 opacity-60 hover:opacity-100 group-hover:opacity-100"
                                disabled={removing !== null}
                                onClick={() => onRemove(entry)}
                                aria-label={`Remove link to ${entry.title}`}
                                title="Remove link"
                            >
                                {removing === entry.reference_id ? <Loader2 className="size-3 animate-spin" /> : <X className="size-3" />}
                            </Button>
                        )}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function TypeIcon({ entry }: { entry: ContentReferenceSummary }) {
    const Icon = entry.type === 'item' ? CheckSquare
        : entry.type === 'ticket' ? LifeBuoy
            : entry.type === 'plan' ? GanttChart
                : entry.label === 'Whiteboard' ? PencilRuler : FileText;

    return (
        <span className="flex shrink-0 items-center" title={entry.label}>
            <Icon className="size-4 text-muted-foreground" aria-hidden />
            <span className="sr-only">{entry.label}:</span>
        </span>
    );
}

/** The "Link related content" picker: search the project's issues, docs, tickets and plans. */
function LinkContentDialog({ open, onOpenChange, workspaceSlug, projectSlug, type, id, onLinked }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    projectSlug: string;
    type: ContentReferenceType;
    id: number;
    onLinked: () => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ContentReferenceCandidate[] | null>(null);
    const [saving, setSaving] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            setQuery('');
            setResults(null);
        }
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            window.axios
                .get<{ results: ContentReferenceCandidate[] }>(route('references.candidates', [workspaceSlug, projectSlug]), {
                    params: { type, id, q: query },
                    signal: controller.signal,
                })
                .then(({ data }) => setResults(data.results))
                .catch((error) => {
                    if (!controller.signal.aborted) {
                        setResults([]);
                        toast.error(errorMessage(error, 'Search failed.'));
                    }
                });
        }, 200);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, workspaceSlug, projectSlug, type, id]);

    const link = (candidate: ContentReferenceCandidate) => {
        setSaving(candidate.key);
        window.axios
            .post<{ id: number; created: boolean }>(route('references.store', [workspaceSlug, projectSlug]), {
                source_type: type,
                source_id: id,
                target_type: candidate.type,
                target_id: candidate.id,
            })
            .then(({ data }) => {
                toast.success(data.created ? `Linked “${candidate.title}”` : `“${candidate.title}” is already linked`);
                setResults((list) => list?.map((r) => (r.key === candidate.key ? { ...r, linked: true } : r)) ?? list);
                onLinked();
            })
            .catch((error) => toast.error(errorMessage(error, 'The link couldn’t be added.')))
            .finally(() => setSaving(null));
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Link related content</DialogTitle>
                    <DialogDescription>Issues, docs, whiteboards, tickets and plans of this project.</DialogDescription>
                </DialogHeader>
                <div className="relative mt-2">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        autoFocus
                        type="search"
                        value={query}
                        maxLength={100}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search by title or key…"
                        aria-label="Search content"
                        className="h-9 w-full rounded-md border bg-background pl-8 pr-3 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                    />
                </div>
                <ul className="max-h-80 divide-y overflow-y-auto rounded-md border" aria-label="Search results" aria-live="polite">
                    {results === null && (
                        <li className="flex items-center gap-2 px-3 py-3 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" />Loading…</li>
                    )}
                    {results !== null && results.length === 0 && <li className="px-3 py-3 text-sm text-muted-foreground">Nothing found.</li>}
                    {results?.map((candidate) => (
                        <li key={candidate.key}>
                            <button
                                type="button"
                                disabled={candidate.linked || saving !== null}
                                onClick={() => link(candidate)}
                                className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition-colors hover:bg-accent disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <TypeIcon entry={candidate} />
                                {candidate.subtitle && <span className="shrink-0 font-mono text-xs text-muted-foreground">{candidate.subtitle}</span>}
                                <span className="min-w-0 flex-1 truncate">{candidate.title}</span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {saving === candidate.key ? <Loader2 className="size-3.5 animate-spin" /> : candidate.linked ? 'Linked' : candidate.label}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}

function errorMessage(error: unknown, fallback: string): string {
    const data = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;

    return first ?? data?.message ?? fallback;
}
