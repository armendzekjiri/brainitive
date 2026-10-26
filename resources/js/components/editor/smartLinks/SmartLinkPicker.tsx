import { useEffect, useState } from 'react';
import { CheckSquare, FileText, GanttChart, LifeBuoy, Loader2, PencilRuler, Search } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { SmartLinkSearchResponse, SmartLinkSearchResult } from '@/types';

/**
 * The "Link to issue, doc, ticket or plan" picker (BRAI-143): searches one project the user can open
 * (the editor's project first) with a switch to the workspace's other projects.
 */
export default function SmartLinkPicker({ open, onOpenChange, workspaceSlug, projectSlug, onPick }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    workspaceSlug: string;
    projectSlug: string;
    onPick: (result: SmartLinkSearchResult) => void;
}) {
    const [query, setQuery] = useState('');
    const [project, setProject] = useState(projectSlug);
    const [data, setData] = useState<SmartLinkSearchResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [active, setActive] = useState(0);

    useEffect(() => {
        if (open) {
            setQuery('');
            setProject(projectSlug);
            setData(null);
            setActive(0);
        }
    }, [open, projectSlug]);

    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setLoading(true);
        const timer = setTimeout(() => {
            window.axios
                .get<SmartLinkSearchResponse>(route('smart-links.search', workspaceSlug), {
                    params: { project, q: query },
                    signal: controller.signal,
                })
                .then(({ data: response }) => {
                    setData(response);
                    setFailed(false);
                    setActive(0);
                })
                .catch(() => {
                    if (!controller.signal.aborted) setFailed(true);
                })
                .finally(() => {
                    if (!controller.signal.aborted) setLoading(false);
                });
        }, 200);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, project, workspaceSlug]);

    const results = data?.results ?? [];
    const pick = (result: SmartLinkSearchResult) => {
        onPick(result);
        onOpenChange(false);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg" onCloseAutoFocus={(event) => event.preventDefault()}>
                <DialogHeader>
                    <DialogTitle>Link to issue, doc, ticket or plan</DialogTitle>
                    <DialogDescription>Shows as a live smart link: title, status, due date and more.</DialogDescription>
                </DialogHeader>
                <div className="mt-1 flex flex-col gap-2 sm:flex-row">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            autoFocus
                            type="search"
                            value={query}
                            maxLength={100}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'ArrowDown') {
                                    e.preventDefault();
                                    setActive((i) => Math.min(i + 1, Math.max(results.length - 1, 0)));
                                } else if (e.key === 'ArrowUp') {
                                    e.preventDefault();
                                    setActive((i) => Math.max(i - 1, 0));
                                } else if (e.key === 'Enter' && results[active]) {
                                    e.preventDefault();
                                    pick(results[active]);
                                }
                            }}
                            placeholder="Search by title or key…"
                            aria-label="Search content to link"
                            className="h-9 w-full rounded-md border bg-background pl-8 pr-3 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                        />
                    </div>
                    <select
                        value={project}
                        onChange={(e) => setProject(e.target.value)}
                        aria-label="Project"
                        className="h-9 rounded-md border bg-background px-2 text-sm sm:max-w-[12rem]"
                    >
                        {(data?.projects ?? [{ slug: projectSlug, name: 'This project' }]).map((p) => (
                            <option key={p.slug} value={p.slug}>{p.name}</option>
                        ))}
                    </select>
                </div>
                <ul className="max-h-80 divide-y overflow-y-auto rounded-md border" aria-label="Content to link" aria-live="polite">
                    {data === null && !failed && (
                        <li className="flex items-center gap-2 px-3 py-3 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" />Loading…</li>
                    )}
                    {failed && <li className="px-3 py-3 text-sm text-muted-foreground">Search failed. Try again.</li>}
                    {data !== null && !failed && results.length === 0 && (
                        <li className="px-3 py-3 text-sm text-muted-foreground">{loading ? 'Searching…' : 'Nothing found.'}</li>
                    )}
                    {!failed && results.map((result, index) => (
                        <li key={result.ref}>
                            <button
                                type="button"
                                onClick={() => pick(result)}
                                onMouseEnter={() => setActive(index)}
                                className={`flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition-colors ${index === active ? 'bg-accent' : 'hover:bg-accent'}`}
                            >
                                <ResultIcon result={result} />
                                {result.subtitle && <span className="shrink-0 font-mono text-xs text-muted-foreground">{result.subtitle}</span>}
                                <span className="min-w-0 flex-1 truncate">{result.title}</span>
                                <span className="shrink-0 text-xs text-muted-foreground">{result.label}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            </DialogContent>
        </Dialog>
    );
}

function ResultIcon({ result }: { result: SmartLinkSearchResult }) {
    const Icon = result.type === 'item' ? CheckSquare
        : result.type === 'ticket' ? LifeBuoy
            : result.type === 'plan' ? GanttChart
                : result.label === 'Whiteboard' ? PencilRuler : FileText;

    return <Icon className="size-4 shrink-0 text-muted-foreground" aria-label={result.label} />;
}
