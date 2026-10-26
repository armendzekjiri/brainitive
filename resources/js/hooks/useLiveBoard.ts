/**
 * Live boards (BRAI-152): keep an open board current while others change it.
 *
 * `useLiveBoard` listens to `board.{id}` and patches the page's columns in place:
 * - card events (`item.*`, `items.changed`) → the changed cards are refetched through the
 *   permission-checked `boards.cards` endpoint (≤ 100 per request) and merged into their columns at
 *   their position — a card the endpoint no longer returns (deleted, moved away, left the sprint)
 *   is dropped. Filters, swimlanes and views all read the patched columns, so a card that stops
 *   matching the active filters disappears and a newly matching one appears.
 * - everything else (columns, sprints, board settings, an import's `board.refreshed`) and every
 *   reconnect (`onResync`) → one partial Inertia reload of the board props.
 * - bursts are debounced (250 ms); nothing is applied while the user drags a card or one of the
 *   user's own requests is in flight (applied right after); this tab's own echoes are ignored
 *   (`client_id`, lib/liveClientId) — its own Inertia reload already shows them.
 * - moved cards slide to their new place and changed cards flash briefly (cards carry
 *   `data-live-card`; skipped with prefers-reduced-motion).
 *
 * `useLiveBoardReload` is the simple variant for pages without card-level merging (backlog): any
 * foreign event → one debounced partial reload.
 */
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useLiveChannel } from '@/lib/live';
import { installLiveClientId, isOwnEcho, useMyUserIdRef } from '@/lib/liveClientId';
import type { BoardColumn, Item, LiveEvent } from '@/types';

type LiveColumn = BoardColumn & { items: Item[] };

interface LiveBoardSource {
    id: number;
    columns: LiveColumn[];
}

interface LiveBoardOptions {
    /** route('boards.cards', …) */
    cardsUrl: string;
    /** Page props a full resync reloads. */
    reloadProps: string[];
    /** Cards that left the board (deleted, moved away) — e.g. to tell the user an open card is gone. */
    onGone?: (ids: number[]) => void;
    /** The board itself was deleted. */
    onBoardDeleted?: () => void;
}

const DEBOUNCE_MS = 250;
const CHUNK = 100;
/** Card events that are refetched (the rest reload the board). */
const CARD_EVENTS = new Set(['item.created', 'item.updated', 'item.moved', 'item.restored', 'item.moved_in', 'item.deleted', 'item.moved_out']);
const GONE_EVENTS = new Set(['item.deleted', 'item.moved_out']);

const numbers = (value: unknown): number[] => (Array.isArray(value) ? value.filter((v): v is number => typeof v === 'number') : typeof value === 'number' ? [value] : []);

/**
 * Replace / insert the fetched cards, drop the requested ones that didn't come back. A card that
 * stays in its column at the same position keeps its place; others go before the first card with a
 * higher position (the server orders by position too).
 */
export function mergeCards(columns: LiveColumn[], requested: number[], cards: Item[]): LiveColumn[] {
    const wanted = new Set(requested);
    const incoming = new Map(cards.map((card) => [card.id, card]));
    return columns.map((column) => {
        const current = column.items ?? [];
        let changed = false;
        const kept: Item[] = [];
        for (const item of current) {
            if (!wanted.has(item.id)) {
                kept.push(item);
                continue;
            }
            const fresh = incoming.get(item.id);
            if (fresh && fresh.column_id === column.id && fresh.position === item.position) {
                kept.push(fresh); // in place
                incoming.delete(item.id);
            }
            changed = true;
        }
        for (const card of cards) {
            if (card.column_id !== column.id || !incoming.has(card.id)) continue;
            const at = kept.findIndex((item) => (item.position ?? 0) > (card.position ?? 0));
            kept.splice(at < 0 ? kept.length : at, 0, card);
            changed = true;
        }
        return changed ? { ...column, items: kept } : column;
    });
}

const cardElement = (id: number) => (typeof document === 'undefined' ? null : document.querySelector<HTMLElement>(`[data-live-card="${id}"]`));

const reducedMotion = () => typeof window !== 'undefined' && (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false);

/** Slide moved cards from where they were, flash the changed ones. */
function animateCards(ids: number[], before: Map<number, DOMRect>) {
    if (reducedMotion() || typeof Element === 'undefined' || !('animate' in Element.prototype)) return;
    const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim();
    const flash = primary ? `hsl(${primary} / 0.18)` : 'rgba(14, 131, 220, 0.18)';
    for (const id of ids) {
        const element = cardElement(id);
        if (!element) continue;
        const from = before.get(id);
        const to = element.getBoundingClientRect();
        if (from && (Math.abs(from.left - to.left) > 1 || Math.abs(from.top - to.top) > 1)) {
            element.animate(
                [{ transform: `translate(${from.left - to.left}px, ${from.top - to.top}px)` }, { transform: 'translate(0, 0)' }],
                { duration: 320, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)' },
            );
        } else if (!from) {
            element.animate([{ opacity: 0, transform: 'scale(0.97)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 220, easing: 'ease-out' });
        }
        element.animate({ backgroundColor: [flash, getComputedStyle(element).backgroundColor] }, { duration: 1400, easing: 'ease-out' });
    }
}

/** Count the user's Inertia visits in flight (live changes wait for them). */
function useVisitsInFlight(onIdle: () => void) {
    const visits = useRef(0);
    const idle = useRef(onIdle);
    idle.current = onIdle;
    useEffect(() => {
        const offStart = router.on('start', () => {
            visits.current++;
        });
        const offFinish = router.on('finish', () => {
            visits.current = Math.max(0, visits.current - 1);
            if (visits.current === 0) idle.current();
        });
        return () => {
            offStart();
            offFinish();
        };
    }, []);
    return visits;
}

export function useLiveBoard<B extends LiveBoardSource>(source: B, options: LiveBoardOptions) {
    useEffect(installLiveClientId, []);
    const me = useMyUserIdRef();

    const [overlay, setOverlay] = useState<{ source: B; columns: LiveColumn[] } | null>(null);
    const columns = overlay && overlay.source === source ? overlay.columns : source.columns;
    const board = useMemo<B>(() => (columns === source.columns ? source : { ...source, columns }), [source, columns]);

    const latest = useRef({ source, columns, options });
    latest.current = { source, columns, options };

    const pending = useRef(new Set<number>());
    const gone = useRef(new Set<number>());
    const full = useRef(false);
    const paused = useRef(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const flip = useRef<{ ids: number[]; rects: Map<number, DOMRect> } | null>(null);
    // newest request per card: an older answer arriving late never overwrites a newer one
    const sequence = useRef(0);
    const newest = useRef(new Map<number, number>());
    const mounted = useRef(true);

    const apply = useCallback((requested: number[], cards: Item[]) => {
        const present = new Set(latest.current.columns.flatMap((c) => (c.items ?? []).map((i) => i.id)));
        const returned = new Set(cards.map((c) => c.id));
        const left = requested.filter((id) => present.has(id) && !returned.has(id));
        flip.current = {
            ids: cards.map((c) => c.id),
            rects: new Map(cards.map((c) => [c.id, cardElement(c.id)?.getBoundingClientRect()] as const).filter((e): e is [number, DOMRect] => e[1] !== undefined)),
        };
        setOverlay((previous) => {
            const current = latest.current.source;
            const base = previous && previous.source === current ? previous.columns : current.columns;
            return { source: current, columns: mergeCards(base, requested, cards) };
        });
        if (left.length) latest.current.options.onGone?.(left);
    }, []);

    useLayoutEffect(() => {
        const pendingFlip = flip.current;
        if (!pendingFlip) return;
        flip.current = null;
        animateCards(pendingFlip.ids, pendingFlip.rects);
    }, [columns]);

    const flushRef = useRef<() => void>(() => {});
    const schedule = useCallback(() => {
        if (timer.current !== null) return;
        timer.current = setTimeout(() => {
            timer.current = null;
            flushRef.current();
        }, DEBOUNCE_MS);
    }, []);

    const visits = useVisitsInFlight(() => {
        if (pending.current.size || gone.current.size || full.current) schedule();
    });

    flushRef.current = () => {
        if (paused.current || visits.current > 0) return; // picked up again when the drag / visit ends
        const { options: current } = latest.current;
        if (full.current) {
            full.current = false;
            pending.current.clear();
            gone.current.clear();
            router.reload({ only: current.reloadProps });
            return;
        }
        const removed = [...gone.current].filter((id) => !pending.current.has(id));
        gone.current.clear();
        if (removed.length) {
            const seq = ++sequence.current;
            removed.forEach((id) => newest.current.set(id, seq));
            apply(removed, []);
        }

        const ids = [...pending.current];
        pending.current.clear();
        for (let i = 0; i < ids.length; i += CHUNK) {
            const chunk = ids.slice(i, i + CHUNK);
            const seq = ++sequence.current;
            chunk.forEach((id) => newest.current.set(id, seq));
            const startedFrom = latest.current.source;
            window.axios
                .get<{ items: Item[] }>(current.cardsUrl, { params: { ids: chunk } })
                .then(({ data }) => {
                    if (!mounted.current) return;
                    const fresh = chunk.filter((id) => newest.current.get(id) === seq);
                    if (!fresh.length) return; // a newer request answers for these cards
                    if (startedFrom !== latest.current.source || visits.current > 0 || paused.current) {
                        // newer props arrived meanwhile (or the user is busy): ask again
                        fresh.forEach((id) => pending.current.add(id));
                        schedule();
                        return;
                    }
                    fresh.forEach((id) => newest.current.delete(id));
                    const wanted = new Set(fresh);
                    apply(fresh, (data.items ?? []).filter((card) => wanted.has(card.id)));
                })
                .catch(() => {
                    // lost access / board gone / offline: the next resync sorts it out
                });
        }
    };

    const onEvent = useCallback((event: LiveEvent) => {
        const payload = event.payload ?? {};
        if (isOwnEcho(payload, me.current)) return;
        if (event.event === 'board.deleted') {
            latest.current.options.onBoardDeleted?.();
            return;
        }
        if (CARD_EVENTS.has(event.event)) {
            numbers(payload.id).forEach((id) => (GONE_EVENTS.has(event.event) ? gone : pending).current.add(id));
        } else if (event.event === 'items.changed') {
            numbers(payload.ids).forEach((id) => pending.current.add(id));
            numbers(payload.deleted_ids).forEach((id) => gone.current.add(id));
        } else {
            full.current = true; // columns, sprints, board settings, board.refreshed
        }
        schedule();
    }, [schedule, me]);

    const onResync = useCallback(() => {
        full.current = true;
        schedule();
    }, [schedule]);

    const { status } = useLiveChannel(`board.${source.id}`, { onEvent, onResync });

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            if (timer.current !== null) clearTimeout(timer.current);
            timer.current = null;
        };
    }, []);

    /** Hold live changes (e.g. while the user drags a card); they apply when released. */
    const setPaused = useCallback((value: boolean) => {
        paused.current = value;
        if (!value && (pending.current.size || gone.current.size || full.current)) schedule();
    }, [schedule]);

    return { board, status, setPaused };
}

/**
 * Pages that show a board's cards without merging them (the backlog): any change by someone else
 * → one debounced partial reload of `reloadProps`, held while `paused` (a drag) or a visit runs.
 */
export function useLiveBoardReload(boardId: number, reloadProps: string[], paused: boolean) {
    useEffect(installLiveClientId, []);
    const me = useMyUserIdRef();
    const dirty = useRef(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const pausedRef = useRef(paused);
    pausedRef.current = paused;
    const props = useRef(reloadProps);
    props.current = reloadProps;

    const flushRef = useRef<() => void>(() => {});
    const schedule = useCallback(() => {
        if (timer.current !== null) return;
        timer.current = setTimeout(() => {
            timer.current = null;
            flushRef.current();
        }, DEBOUNCE_MS);
    }, []);
    const visits = useVisitsInFlight(() => {
        if (dirty.current) schedule();
    });
    flushRef.current = () => {
        if (!dirty.current || pausedRef.current || visits.current > 0) return;
        dirty.current = false;
        router.reload({ only: props.current });
    };

    useEffect(() => {
        if (!paused && dirty.current) schedule();
    }, [paused, schedule]);
    useEffect(() => () => {
        if (timer.current !== null) clearTimeout(timer.current);
        timer.current = null;
    }, []);

    const mark = useCallback(() => {
        dirty.current = true;
        schedule();
    }, [schedule]);

    return useLiveChannel(`board.${boardId}`, {
        onEvent: (event) => {
            if (!isOwnEcho(event.payload ?? {}, me.current)) mark();
        },
        onResync: mark,
    });
}
