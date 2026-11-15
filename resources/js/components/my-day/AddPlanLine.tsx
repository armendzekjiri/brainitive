import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import type { PlanLinkResult } from '@/types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Link2, Plus } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { toast } from 'sonner';

/**
 * The empty line at the end of a day (My Week, v1.53.0): type and press Enter for a free-form line,
 * or start with `#` (or `/`) to search an issue / list task and link it instead. The results open in
 * a portaled popover, so the card around the planner never clips them.
 */
export default function AddPlanLine({ date, label, className }: { date: string; label: string; className?: string }) {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [focused, setFocused] = useState(false);
    // results always belong to the query they were fetched for: never show or link stale hits
    const [found, setFound] = useState<{ query: string; results: PlanLinkResult[] }>({ query: '', results: [] });
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const listId = useId();

    const linking = /^[#/]/.test(text);
    const query = linking ? text.slice(1).trim() : '';
    const results = found.query === query ? found.results : [];
    const searching = linking && query.length >= 2 && found.query !== query;
    const open = focused && linking;

    useEffect(() => {
        if (!linking || query.length < 2) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            axios
                .get<{ results: PlanLinkResult[] }>(route('my-day.search'), { params: { q: query }, signal: controller.signal })
                .then((response) => {
                    setFound({ query, results: response.data.results });
                    setActive(0);
                })
                .catch(() => {
                    if (!controller.signal.aborted) setFound({ query, results: [] });
                });
        }, 200);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [linking, query]);

    const post = (data: Record<string, unknown>) => {
        setBusy(true);
        router.post(route('my-day.entries.store'), { plan_date: date, ...data }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setText('');
                setFound({ query: '', results: [] });
                // keep typing the next line
                window.setTimeout(() => inputRef.current?.focus(), 0);
            },
            onError: (errors) => toast.error(errors.title ?? errors.plan_date ?? errors.work_id ?? 'Could not add it.'),
            onFinish: () => setBusy(false),
        });
    };

    const link = (hit: PlanLinkResult) => post({ work_type: hit.work_type, work_id: hit.work_id });

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Escape') {
            setText('');
            return;
        }
        if (linking && results.length > 0 && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : results.length - 1)) % results.length);
            return;
        }
        if (e.key !== 'Enter' || busy || e.nativeEvent.isComposing) return;
        e.preventDefault();
        if (linking) {
            if (results[active]) link(results[active]);
            return;
        }
        if (text.trim() !== '') post({ title: text.trim() });
    };

    const optionId = (i: number) => `${listId}-option-${i}`;

    return (
        <Popover open={open}>
            <PopoverAnchor asChild>
                <div className={cn('flex items-center gap-2 rounded-md px-2 py-1.5 text-muted-foreground focus-within:bg-card focus-within:ring-1 focus-within:ring-border', className)}>
                    {linking ? <Link2 className="size-3.5 shrink-0" /> : <Plus className="size-3.5 shrink-0" />}
                    <input
                        ref={inputRef}
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        onKeyDown={onKeyDown}
                        onFocus={() => setFocused(true)}
                        onBlur={() => setFocused(false)}
                        disabled={busy}
                        maxLength={500}
                        aria-label={label}
                        placeholder="Add a line…"
                        title="Type a line and press Enter. Start with # to link an issue or task."
                        role="combobox"
                        aria-expanded={open}
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-activedescendant={open && results[active] ? optionId(active) : undefined}
                        className="min-w-0 flex-1 border-0 bg-transparent p-0 text-[12.5px] text-foreground shadow-none outline-none ring-0 placeholder:text-muted-foreground/60 focus:border-0 focus:outline-none focus:ring-0"
                    />
                </div>
            </PopoverAnchor>
            <PopoverContent
                align="start"
                sideOffset={4}
                onOpenAutoFocus={(e) => e.preventDefault()}
                onCloseAutoFocus={(e) => e.preventDefault()}
                className="w-[var(--radix-popover-trigger-width)] min-w-[240px] overflow-hidden p-0"
            >
                <ul id={listId} role="listbox" aria-label="Tasks to link">
                    {query.length < 2 ? (
                        <li className="px-3 py-2 text-xs text-muted-foreground">Type to find an issue or task…</li>
                    ) : results.length === 0 ? (
                        <li className="px-3 py-2 text-xs text-muted-foreground">{searching ? 'Searching…' : 'Nothing found.'}</li>
                    ) : (
                        results.map((hit, i) => (
                            <li
                                key={`${hit.work_type}:${hit.work_id}`}
                                id={optionId(i)}
                                role="option"
                                aria-selected={i === active}
                                // mousedown, not click: the input must not blur (that would close the list first)
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    link(hit);
                                }}
                                onMouseEnter={() => setActive(i)}
                                className={cn('cursor-pointer px-3 py-1.5 text-[12px]', i === active && 'bg-accent text-accent-foreground')}
                            >
                                <span className={cn('block truncate font-medium', hit.done && 'text-muted-foreground line-through')}>
                                    {hit.key && <span className="mr-1.5 font-mono text-[10.5px] text-muted-foreground">{hit.key}</span>}
                                    {hit.title}
                                </span>
                                <span className="block truncate text-[10.5px] text-muted-foreground">
                                    {hit.work_type === 'board' ? 'Issue' : 'Task'}
                                    {hit.project && ` · ${hit.project}`}
                                    {hit.context && ` · ${hit.context}`}
                                </span>
                            </li>
                        ))
                    )}
                </ul>
            </PopoverContent>
        </Popover>
    );
}
