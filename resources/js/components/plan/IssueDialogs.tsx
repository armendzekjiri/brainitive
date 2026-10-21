import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Kanban, Loader2, Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { formatDate } from './dates';
import { CATEGORY_CLASSES, rowIssueDates } from './issues';
import { IssueSummary, PlanRow } from './types';

function DateSpan({ start, due }: { start: string | null; due: string | null }) {
    if (!start && !due) return <span className="text-muted-foreground">No dates</span>;
    if (!start) return <span>Due {formatDate(due!)}</span>;
    return <span>{formatDate(start)} – {formatDate(due ?? start)}</span>;
}

/**
 * Create one issue per selected row on a chosen board. Dates and a "Who" that matches a
 * member carry over; the rows become linked.
 */
export function CreateIssuesDialog({ open, onOpenChange, rows, boards, onCreate }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rows: PlanRow[];
    boards: { id: number; name: string }[];
    onCreate: (boardId: number, rows: PlanRow[]) => Promise<void>;
}) {
    const [boardId, setBoardId] = useState<number | null>(boards[0]?.id ?? null);
    const [picked, setPicked] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setPicked(new Set(rows.filter((r) => r.name.trim()).map((r) => r.id)));
        setError(null);
        setBoardId((id) => id ?? boards[0]?.id ?? null);
        // Reset only when the dialog opens, not on every parent render
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!boardId || picked.size === 0) return;
        setBusy(true);
        setError(null);
        try {
            await onCreate(boardId, rows.filter((r) => picked.has(r.id)));
            onOpenChange(false);
        } catch (e: unknown) {
            const message = (e as { response?: { data?: { message?: string } } }).response?.data?.message;
            setError(message ?? 'Could not create the issues.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2"><Kanban className="size-4" />Create issues from rows</DialogTitle>
                        <DialogDescription>Each row becomes an issue in the board’s first column (backlog on Scrum boards). Its dates become start and due date; a “Who” that matches a member becomes the assignee. Later changes on the board show up here.</DialogDescription>
                    </DialogHeader>
                    {boards.length === 0 ? (
                        <p className="mt-4 text-sm text-muted-foreground">There is no board in this project you can add issues to.</p>
                    ) : (
                        <div className="mt-4 space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="issue-board">Board</Label>
                                <select
                                    id="issue-board"
                                    value={boardId ?? ''}
                                    onChange={(e) => setBoardId(Number(e.target.value))}
                                    className="h-9 w-full rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                                >
                                    {boards.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                </select>
                            </div>
                            <ul className="max-h-72 divide-y overflow-y-auto rounded-md border">
                                {rows.map((row) => {
                                    const dates = rowIssueDates(row);
                                    const empty = !row.name.trim();
                                    return (
                                        <li key={row.id}>
                                            <label className={cn('flex cursor-pointer items-center gap-3 px-3 py-2 text-sm', empty && 'cursor-not-allowed opacity-50')}>
                                                <input
                                                    type="checkbox"
                                                    disabled={empty}
                                                    checked={picked.has(row.id)}
                                                    onChange={(e) => setPicked((p) => { const next = new Set(p); e.target.checked ? next.add(row.id) : next.delete(row.id); return next; })}
                                                    className="accent-primary"
                                                />
                                                <span className="min-w-0 flex-1 truncate">{row.name || 'Untitled row (needs a name)'}</span>
                                                <span className="shrink-0 text-xs text-muted-foreground"><DateSpan start={dates.start_date} due={dates.due_date} />{row.who ? ` · ${row.who}` : ''}</span>
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                            {error && <p className="text-sm text-destructive">{error}</p>}
                        </div>
                    )}
                    <DialogFooter className="mt-6">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={busy || !boardId || picked.size === 0}>
                            {busy && <Loader2 className="mr-1.5 size-4 animate-spin" />}
                            {picked.size > 1 ? `Create ${picked.size} issues` : 'Create issue'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Search the project's issues. `single` links one issue to a row; otherwise several are
 * picked and added as new rows.
 */
export function IssuePickerDialog({ open, onOpenChange, searchUrl, single, linkedIds, onPick }: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    searchUrl: string;
    single: boolean;
    linkedIds: Set<number>;
    onPick: (issues: IssueSummary[]) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<IssueSummary[] | null>(null);
    const [picked, setPicked] = useState<Map<number, IssueSummary>>(new Map());

    useEffect(() => {
        if (!open) return;
        setPicked(new Map());
        const id = setTimeout(() => {
            window.axios.get(searchUrl, { params: { q: query } })
                .then(({ data }) => setResults(data.items))
                .catch(() => setResults([]));
        }, 200);
        return () => clearTimeout(id);
    }, [open, query, searchUrl]);

    useEffect(() => {
        if (open) setQuery('');
    }, [open]);

    const choose = (issue: IssueSummary) => {
        if (single) {
            onPick([issue]);
            onOpenChange(false);
            return;
        }
        setPicked((p) => { const next = new Map(p); next.has(issue.id) ? next.delete(issue.id) : next.set(issue.id, issue); return next; });
    };

    const list = useMemo(() => results ?? [], [results]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{single ? 'Link to an issue' : 'Add issues as rows'}</DialogTitle>
                    <DialogDescription>
                        {single
                            ? 'The row takes the issue’s title, dates, status and assignee and stays in sync with it.'
                            : 'Each issue becomes a linked row with its dates, status and assignee.'}
                    </DialogDescription>
                </DialogHeader>
                <div className="relative mt-2">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        autoFocus
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search by key or title…"
                        aria-label="Search issues"
                        className="h-9 w-full rounded-md border bg-background pl-8 pr-3 text-sm outline-none focus:ring-2 focus:ring-ring/30"
                    />
                </div>
                <ul className="max-h-80 divide-y overflow-y-auto rounded-md border" aria-live="polite">
                    {results === null && <li className="flex items-center gap-2 px-3 py-3 text-sm text-muted-foreground"><Loader2 className="size-4 animate-spin" />Loading…</li>}
                    {results !== null && list.length === 0 && <li className="px-3 py-3 text-sm text-muted-foreground">No issues found.</li>}
                    {list.map((issue) => {
                        const inPlan = linkedIds.has(issue.id);
                        const isPicked = picked.has(issue.id);
                        return (
                            <li key={issue.id}>
                                <button
                                    type="button"
                                    disabled={inPlan}
                                    onClick={() => choose(issue)}
                                    className={cn('flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition-colors hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50', isPicked && 'bg-primary/5')}
                                >
                                    {!single && <input type="checkbox" readOnly checked={isPicked || inPlan} tabIndex={-1} className="pointer-events-none accent-primary" />}
                                    <span className="shrink-0 font-mono text-xs text-muted-foreground">{issue.key}</span>
                                    <span className="min-w-0 flex-1 truncate">{issue.title}</span>
                                    {inPlan ? (
                                        <span className="shrink-0 text-xs text-muted-foreground">In plan</span>
                                    ) : (
                                        <span className={cn('shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase', CATEGORY_CLASSES[issue.status_category])}>{issue.status ?? '—'}</span>
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
                {!single && (
                    <DialogFooter>
                        <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button disabled={picked.size === 0} onClick={() => { onPick([...picked.values()]); onOpenChange(false); }}>
                            {picked.size > 1 ? `Add ${picked.size} rows` : 'Add row'}
                        </Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
