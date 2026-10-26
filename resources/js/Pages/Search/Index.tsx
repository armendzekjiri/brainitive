import { KeyboardEvent as ReactKeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Check, ChevronDown, ExternalLink, FileSearch, Loader2, Search as SearchIcon, SearchX, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Highlighted, SearchTypeIcon } from '@/components/search/SearchBits';
import { cn } from '@/lib/utils';
import type { PageProps, SearchFilters, SearchGroup, SearchOptions, SearchResponse, SearchResult } from '@/types';

interface Props extends PageProps {
    filters: SearchFilters;
    search: SearchResponse | null;
    groups: { key: SearchGroup; label: string }[];
    options?: SearchOptions;
}

const DEBOUNCE_MS = 250;

const UPDATED: { value: NonNullable<SearchFilters['updated']>; label: string }[] = [
    { value: '7d', label: 'Past week' },
    { value: '30d', label: 'Past month' },
    { value: '90d', label: 'Past 3 months' },
    { value: '365d', label: 'Past year' },
];

type Params = Partial<Record<'q' | 'type' | 'status' | 'assignee' | 'updated' | 'page', string>> & { project?: number[] };

function toParams(filters: SearchFilters, changes: Partial<SearchFilters> = {}): Params {
    const next = { ...filters, page: 1, ...changes };
    const params: Params = {};
    if (next.q.trim()) params.q = next.q.trim();
    if (next.type) params.type = next.type;
    if (next.project.length) params.project = next.project;
    if (next.status) params.status = next.status;
    if (next.assignee) params.assignee = next.assignee;
    if (next.updated) params.updated = next.updated;
    if (next.page > 1) params.page = String(next.page);
    return params;
}

function relativeDay(iso: string | null): string | null {
    if (!iso) return null;
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);
    if (days <= 0) return 'today';
    if (days === 1) return 'yesterday';
    if (days < 30) return `${days} days ago`;
    return new Date(iso).toLocaleDateString();
}

/** "Find anything" (BRAI-55): everything the user can open, ranked, with filters and a tab per type. */
export default function SearchIndex({ filters, search, groups, options }: Props) {
    const [query, setQuery] = useState(filters.q);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLOListElement>(null);
    const lastSent = useRef(filters.q);

    const visit = (params: Params) => {
        router.get(route('search'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['filters', 'search'],
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };
    const apply = (changes: Partial<SearchFilters>) => visit(toParams({ ...filters, q: query }, changes));

    // Debounced typing, kept in the URL.
    useEffect(() => {
        if (query.trim() === lastSent.current.trim()) return;
        const timer = window.setTimeout(() => {
            lastSent.current = query;
            visit(toParams({ ...filters, q: query }));
        }, DEBOUNCE_MS);
        return () => window.clearTimeout(timer);
        // only the typed text triggers this; filters apply immediately
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    // "/" focuses the search field from anywhere on the page.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            if (e.key === '/' && !e.metaKey && !e.ctrlKey && target && !['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) && !target.isContentEditable) {
                e.preventDefault();
                inputRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const results = useMemo(() => search?.results ?? [], [search]);
    useEffect(() => setActive(0), [results]);
    useEffect(() => {
        listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const open = (result: SearchResult) => router.visit(result.url);

    const onListKeys = (e: ReactKeyboardEvent) => {
        if (results.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(results.length - 1, i + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(0, i - 1));
        } else if (e.key === 'Enter' && e.target === inputRef.current) {
            e.preventDefault();
            open(results[active]);
        }
    };

    const counts = search?.counts;
    const allCount = counts ? Object.values(counts).reduce((a, b) => a + b, 0) : 0;
    const hasFilters = filters.project.length > 0 || !!filters.status || !!filters.assignee || !!filters.updated;
    const searching = filters.q.trim() !== '';

    return (
        <AppLayout>
            <Head title={searching ? `Search: ${filters.q}` : 'Search'} />
            <div className="mx-auto max-w-4xl space-y-5 px-4 py-6 sm:px-6 sm:py-8">
                <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                    <FileSearch className="h-6 w-6 text-primary" />
                    Search
                </h1>

                <div className="relative" onKeyDown={onListKeys}>
                    {loading
                        ? <Loader2 className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 animate-spin text-muted-foreground" />
                        : <SearchIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-muted-foreground" />}
                    <Input
                        ref={inputRef}
                        type="search"
                        autoFocus
                        aria-label="Search everything"
                        aria-controls="search-results"
                        aria-activedescendant={results.length ? `search-result-${active}` : undefined}
                        placeholder="Search issues, docs, tickets, comments, snippets…"
                        className="h-12 pl-11 text-base"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                </div>

                <FilterBar filters={filters} options={options} onChange={apply} hasFilters={hasFilters} />

                {searching && search && (
                    <nav aria-label="Result types" className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                        <div className="flex w-max gap-1 rounded-xl bg-muted p-1">
                            <TypeTab label="All" count={allCount} selected={!filters.type} onClick={() => apply({ type: null })} />
                            {groups.filter((g) => (counts?.[g.key] ?? 0) > 0 || filters.type === g.key).map((g) => (
                                <TypeTab key={g.key} label={g.label} count={counts?.[g.key] ?? 0} selected={filters.type === g.key} onClick={() => apply({ type: g.key })} />
                            ))}
                        </div>
                    </nav>
                )}

                {!searching ? (
                    <SearchTips />
                ) : !search || results.length === 0 ? (
                    <NoResults q={filters.q} filters={filters} hasFilters={hasFilters} onClear={() => apply({ project: [], status: null, assignee: null, updated: null, type: null })} />
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground" role="status">
                            {search.fuzzy
                                ? <>No exact matches for “{filters.q}” — showing similar titles.</>
                                : <>{search.total} {search.total === 1 ? 'result' : 'results'}</>}
                        </p>
                        <ol id="search-results" ref={listRef} role="listbox" aria-label="Search results" className="divide-y rounded-2xl border bg-card" onKeyDown={onListKeys}>
                            {results.map((result, index) => (
                                <ResultRow key={result.id} result={result} index={index} active={index === active} onHover={() => setActive(index)} />
                            ))}
                        </ol>
                        {(search.page > 1 || search.has_more) && (
                            <div className="flex items-center justify-between">
                                {search.page > 1
                                    ? <Button variant="ghost" size="sm" onClick={() => apply({ page: search.page - 1 })}>← Previous</Button>
                                    : <span />}
                                <span className="text-xs text-muted-foreground">Page {search.page}</span>
                                {search.has_more
                                    ? <Button variant="ghost" size="sm" onClick={() => apply({ page: search.page + 1 })}>Next →</Button>
                                    : <span />}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function TypeTab({ label, count, selected, onClick }: { label: string; count: number; selected: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={cn(
                'flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                selected ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
            <span className="rounded-full bg-muted-foreground/10 px-1.5 text-xs tabular-nums">{count}</span>
        </button>
    );
}

function ResultRow({ result, index, active, onHover }: { result: SearchResult; index: number; active: boolean; onHover: () => void }) {
    const when = relativeDay(result.updated_at);
    return (
        <li id={`search-result-${index}`} data-index={index} role="option" aria-selected={active} onMouseEnter={onHover} className={cn('relative', active && 'bg-accent/60')}>
            <Link href={result.url} className="flex gap-3 px-4 py-3 outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <SearchTypeIcon result={result} className="mt-0.5" />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        {result.key && result.type !== 'comment' && <span className="font-mono text-xs text-muted-foreground">{result.key}</span>}
                        <Highlighted html={result.title_html} className="min-w-0 break-words font-medium" />
                        {result.is_draft && <span className="rounded bg-amber-100 px-1.5 text-[11px] font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">Draft</span>}
                        {result.status === 'done' && <span className="rounded bg-green-100 px-1.5 text-[11px] font-medium text-green-800 dark:bg-green-500/20 dark:text-green-200">Done</span>}
                    </div>
                    {result.snippet_html && <Highlighted html={result.snippet_html} className="mt-0.5 line-clamp-2 block break-words text-sm text-muted-foreground" />}
                    <div className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                        <span className="font-medium">{result.label}</span>
                        {result.context && <span className="truncate">· {result.context}</span>}
                        {when && <span>· {when}</span>}
                    </div>
                </div>
            </Link>
            {result.external_url && (
                <a
                    href={result.external_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={`Open ${result.title} in a new tab`}
                    className="absolute right-3 top-3 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <ExternalLink className="h-4 w-4" />
                </a>
            )}
        </li>
    );
}

function FilterBar({ filters, options, onChange, hasFilters }: {
    filters: SearchFilters;
    options?: SearchOptions;
    onChange: (changes: Partial<SearchFilters>) => void;
    hasFilters: boolean;
}) {
    const projects = options?.projects ?? [];
    const people = options?.people ?? [];
    const projectLabel = filters.project.length === 0
        ? 'Project'
        : filters.project.length === 1
            ? projects.find((p) => p.id === filters.project[0])?.name ?? '1 project'
            : `${filters.project.length} projects`;
    const assigneeLabel = !filters.assignee
        ? 'Assignee'
        : filters.assignee === 'me' ? 'Assigned to me' : people.find((p) => String(p.id) === filters.assignee)?.name ?? 'Assignee';

    return (
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Filters">
            <ChipMenu label={projectLabel} active={filters.project.length > 0} searchable={projects.length > 8} items={projects.map((p) => ({
                key: String(p.id),
                label: p.name,
                hint: p.workspace,
                selected: filters.project.includes(p.id),
                onSelect: () => onChange({ project: filters.project.includes(p.id) ? filters.project.filter((id) => id !== p.id) : [...filters.project, p.id] }),
            }))} keepOpen />
            <ChipMenu label={filters.status === 'open' ? 'Open' : filters.status === 'done' ? 'Done' : 'Status'} active={!!filters.status} items={[
                { key: 'open', label: 'Open', selected: filters.status === 'open', onSelect: () => onChange({ status: filters.status === 'open' ? null : 'open' }) },
                { key: 'done', label: 'Done', selected: filters.status === 'done', onSelect: () => onChange({ status: filters.status === 'done' ? null : 'done' }) },
            ]} />
            <ChipMenu label={assigneeLabel} active={!!filters.assignee} searchable={people.length > 8} items={[
                { key: 'me', label: 'Me', selected: filters.assignee === 'me', onSelect: () => onChange({ assignee: filters.assignee === 'me' ? null : 'me' }) },
                ...people.map((p) => ({
                    key: String(p.id),
                    label: p.name,
                    selected: filters.assignee === String(p.id),
                    onSelect: () => onChange({ assignee: filters.assignee === String(p.id) ? null : String(p.id) }),
                })),
            ]} />
            <ChipMenu label={UPDATED.find((u) => u.value === filters.updated)?.label ?? 'Updated'} active={!!filters.updated} items={UPDATED.map((u) => ({
                key: u.value,
                label: u.label,
                selected: filters.updated === u.value,
                onSelect: () => onChange({ updated: filters.updated === u.value ? null : u.value }),
            }))} />
            {hasFilters && (
                <Button variant="ghost" size="sm" onClick={() => onChange({ project: [], status: null, assignee: null, updated: null })}>
                    <X className="h-4 w-4" />
                    Clear filters
                </Button>
            )}
        </div>
    );
}

interface ChipItem { key: string; label: string; hint?: string; selected: boolean; onSelect: () => void }

function ChipMenu({ label, active, items, searchable = false, keepOpen = false }: { label: string; active: boolean; items: ChipItem[]; searchable?: boolean; keepOpen?: boolean }) {
    const [open, setOpen] = useState(false);
    const [filter, setFilter] = useState('');
    const shown = filter ? items.filter((i) => `${i.label} ${i.hint ?? ''}`.toLowerCase().includes(filter.toLowerCase())) : items;
    return (
        <Popover open={open} onOpenChange={(o) => { setOpen(o); if (!o) setFilter(''); }}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'flex max-w-[14rem] items-center gap-1 rounded-full border px-3 py-1 text-sm transition-colors',
                        active ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )}
                >
                    <span className="truncate">{label}</span>
                    <ChevronDown className="h-3.5 w-3.5 shrink-0" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-64 p-1">
                {searchable && (
                    <Input autoFocus aria-label={`Filter ${label}`} placeholder="Filter…" value={filter} onChange={(e) => setFilter(e.target.value)} className="mb-1 h-8" />
                )}
                <ul className="max-h-64 overflow-y-auto" role="menu" aria-label={label}>
                    {shown.length === 0 && <li className="px-2 py-1.5 text-sm text-muted-foreground">Nothing to choose</li>}
                    {shown.map((item) => (
                        <li key={item.key}>
                            <button
                                type="button"
                                role="menuitemcheckbox"
                                aria-checked={item.selected}
                                onClick={() => { item.onSelect(); if (!keepOpen) setOpen(false); }}
                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
                            >
                                <Check className={cn('h-4 w-4 shrink-0', item.selected ? 'opacity-100' : 'opacity-0')} />
                                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                                {item.hint && <span className="truncate text-xs text-muted-foreground">{item.hint}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}

function SearchTips() {
    return (
        <div className="rounded-2xl border border-dashed p-6 text-sm text-muted-foreground">
            <p className="font-medium text-foreground">Find anything you can open: issues, docs, comments, tickets, tasks, plans, snippets, bookmarks, your captures and file names.</p>
            <ul className="mt-3 list-disc space-y-1 pl-5">
                <li>Type an issue key like <kbd className="rounded border bg-muted px-1 font-mono text-xs">WEB-12</kbd> to jump to it.</li>
                <li><kbd className="rounded border bg-muted px-1 font-mono text-xs">"exact phrase"</kbd> matches words in that order; <kbd className="rounded border bg-muted px-1 font-mono text-xs">-word</kbd> leaves results with it out.</li>
                <li>Small typos are fine. Press <kbd className="rounded border bg-muted px-1 font-mono text-xs">/</kbd> to search, <kbd className="rounded border bg-muted px-1 font-mono text-xs">↑</kbd> <kbd className="rounded border bg-muted px-1 font-mono text-xs">↓</kbd> to move and <kbd className="rounded border bg-muted px-1 font-mono text-xs">↵</kbd> to open.</li>
            </ul>
        </div>
    );
}

function NoResults({ q, filters, hasFilters, onClear }: { q: string; filters: SearchFilters; hasFilters: boolean; onClear: () => void }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-2xl border border-dashed px-6 py-14 text-center" role="status">
            <SearchX className="h-8 w-8 text-muted-foreground" />
            <p className="font-medium">Nothing found for “{q}”</p>
            <ul className="space-y-1 text-sm text-muted-foreground">
                <li>Check the spelling, or try fewer or other words.</li>
                {q.includes('"') && <li>Remove the quotes to match the words in any order.</li>}
                {(hasFilters || filters.type) && <li>Filters may hide what you are looking for.</li>}
                <li>You only find what you can open — ask a project admin if something should be shared with you.</li>
            </ul>
            {(hasFilters || filters.type) && (
                <Button variant="outline" size="sm" onClick={onClear}>Search everything</Button>
            )}
        </div>
    );
}
