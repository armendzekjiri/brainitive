import { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Command as CommandPrimitive } from 'cmdk';
import {
    Command,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Bookmark as BookmarkIcon, FileText, LayoutGrid, Loader2, Search as SearchIcon, X } from 'lucide-react';

export interface SearchResult {
    id: number;
    type: 'bookmark' | 'item' | 'page';
    title: string;
    subtitle: string | null;
    url: string;
    workspace?: string;
}

export interface SearchResponse {
    bookmarks: SearchResult[];
    items: SearchResult[];
    pages: SearchResult[];
}

interface SearchModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialQuery?: string;
}

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 2;

function ResultIcon({ type }: { type: SearchResult['type'] }) {
    const styles = {
        bookmark:
            'bg-gradient-to-br from-amber-400/20 to-orange-500/20 text-amber-600 dark:text-amber-400 ring-1 ring-amber-500/20',
        item:
            'bg-gradient-to-br from-violet-400/20 to-purple-500/20 text-violet-600 dark:text-violet-400 ring-1 ring-violet-500/20',
        page:
            'bg-gradient-to-br from-sky-400/20 to-blue-500/20 text-sky-600 dark:text-sky-400 ring-1 ring-sky-500/20',
    };
    const Icon = type === 'bookmark' ? BookmarkIcon : type === 'item' ? LayoutGrid : FileText;
    return (
        <div
            className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${styles[type]}`}
        >
            <Icon className="size-5" strokeWidth={2} />
        </div>
    );
}

function SectionHeading({ children }: { children: React.ReactNode }) {
    return (
        <div className="mb-2 mt-4 px-1 first:mt-0">
            <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {children}
            </span>
        </div>
    );
}

export function SearchModal({ open, onOpenChange, initialQuery = '' }: SearchModalProps) {
    const [query, setQuery] = useState(initialQuery);
    const [loading, setLoading] = useState(false);
    const [results, setResults] = useState<SearchResponse>({ bookmarks: [], items: [], pages: [] });

    const performSearch = useCallback(async (q: string) => {
        if (q.length < MIN_QUERY_LENGTH) {
            setResults({ bookmarks: [], items: [], pages: [] });
            return;
        }
        setLoading(true);
        try {
            const res = await fetch(route('search') + '?q=' + encodeURIComponent(q), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data: SearchResponse = await res.json();
            setResults(data);
        } catch {
            setResults({ bookmarks: [], items: [], pages: [] });
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (!open) return;
        const t = setTimeout(() => performSearch(query), DEBOUNCE_MS);
        return () => clearTimeout(t);
    }, [query, open, performSearch]);

    useEffect(() => {
        if (open && initialQuery) setQuery(initialQuery);
        if (!open) setQuery('');
    }, [open, initialQuery]);

    const handleSelect = (url: string) => {
        router.visit(url);
        onOpenChange(false);
    };

    const hasResults =
        results.bookmarks.length > 0 || results.items.length > 0 || results.pages.length > 0;
    const showEmpty = !loading && query.length >= MIN_QUERY_LENGTH && !hasResults;
    const showHint = query.length < MIN_QUERY_LENGTH && !loading;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="overflow-hidden border-0 p-0 gap-0 max-w-xl bg-background/95 backdrop-blur-xl shadow-2xl shadow-black/10 dark:shadow-black/40 rounded-2xl data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[state=closed]:slide-out-to-left-1/2 data-[state=closed]:slide-out-to-top-[48%] data-[state=open]:slide-in-from-left-1/2 data-[state=open]:slide-in-from-top-[48%] [&>button]:hidden"
                aria-describedby={undefined}
            >
                <Command
                    className="rounded-2xl"
                    shouldFilter={false}
                >
                    {/* Search input area */}
                    <div
                        className="flex items-center gap-3 border-b border-border/50 px-4 py-3"
                        cmdk-input-wrapper=""
                    >
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted/60 text-muted-foreground">
                            <SearchIcon className="size-4" strokeWidth={2} />
                        </div>
                        <CommandPrimitive.Input
                            placeholder="Search bookmarks, boards, documents..."
                            value={query}
                            onValueChange={setQuery}
                            className="h-11 flex-1 border-0 bg-transparent text-base placeholder:text-muted-foreground focus:outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <kbd className="hidden h-6 select-none items-center gap-0.5 rounded-md border bg-muted/80 px-2 font-mono text-[10px] font-medium text-muted-foreground sm:flex">
                            ESC
                        </kbd>
                        <button
                            type="button"
                            onClick={() => onOpenChange(false)}
                            className="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                            aria-label="Close"
                        >
                            <X className="size-4" />
                        </button>
                    </div>

                    <CommandList className="max-h-[min(70vh,420px)] overflow-y-auto overflow-x-hidden p-2">
                        {showHint && (
                            <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
                                <div className="flex size-14 items-center justify-center rounded-2xl bg-muted/50">
                                    <SearchIcon className="size-7 text-muted-foreground/70" strokeWidth={1.5} />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Type at least <span className="font-medium text-foreground/80">{MIN_QUERY_LENGTH} characters</span> to search across your workspace
                                </p>
                            </div>
                        )}

                        {loading && (
                            <div className="flex flex-col items-center justify-center gap-3 py-12 text-muted-foreground">
                                <Loader2 className="size-8 animate-spin" strokeWidth={2} />
                                <p className="text-sm">Searching...</p>
                            </div>
                        )}

                        {showEmpty && (
                            <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
                                <p className="text-sm text-muted-foreground">
                                    No results for <span className="font-medium text-foreground">&quot;{query}&quot;</span>
                                </p>
                                <p className="text-xs text-muted-foreground/80">Try a different search term</p>
                            </div>
                        )}

                        {!loading && hasResults && (
                            <>
                                {results.bookmarks.length > 0 && (
                                    <CommandGroup
                                        className="[&_[cmdk-group-heading]]:hidden"
                                        heading="Bookmarks"
                                    >
                                        <SectionHeading>Bookmarks</SectionHeading>
                                        {results.bookmarks.map((b) => (
                                            <CommandItem
                                                key={`bookmark-${b.id}`}
                                                value={`bookmark-${b.id}`}
                                                onSelect={() => handleSelect(b.url)}
                                                className="group flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 transition-colors data-[selected=true]:bg-accent"
                                            >
                                                <ResultIcon type="bookmark" />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">{b.title}</p>
                                                    {b.subtitle && (
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {b.subtitle}
                                                        </p>
                                                    )}
                                                </div>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                )}
                                {results.items.length > 0 && (
                                    <CommandGroup
                                        className="[&_[cmdk-group-heading]]:hidden"
                                        heading="Board items"
                                    >
                                        <SectionHeading>Board items</SectionHeading>
                                        {results.items.map((item) => (
                                            <CommandItem
                                                key={`item-${item.id}`}
                                                value={`item-${item.id}`}
                                                onSelect={() => handleSelect(item.url)}
                                                className="group flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 transition-colors data-[selected=true]:bg-accent"
                                            >
                                                <ResultIcon type="item" />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">{item.title}</p>
                                                    {item.subtitle && (
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {item.subtitle}
                                                        </p>
                                                    )}
                                                </div>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                )}
                                {results.pages.length > 0 && (
                                    <CommandGroup
                                        className="[&_[cmdk-group-heading]]:hidden"
                                        heading="Documents"
                                    >
                                        <SectionHeading>Documents</SectionHeading>
                                        {results.pages.map((p) => (
                                            <CommandItem
                                                key={`page-${p.id}`}
                                                value={`page-${p.id}`}
                                                onSelect={() => handleSelect(p.url)}
                                                className="group flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 transition-colors data-[selected=true]:bg-accent"
                                            >
                                                <ResultIcon type="page" />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-medium">{p.title}</p>
                                                    {p.subtitle && (
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {p.subtitle}
                                                        </p>
                                                    )}
                                                </div>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                )}
                            </>
                        )}
                    </CommandList>
                </Command>
            </DialogContent>
        </Dialog>
    );
}
