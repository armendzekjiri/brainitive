import { useLiveChannel } from '@/lib/live';
import type { AppNotification, NotificationFeed, NotificationFilter, PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Client side of the inbox (BRAI-102): the feed (filters, cursor pages, optimistic actions) and the
 * unread count shared by the bell, the inbox page and the tab title.
 */

const UNREAD_EVENT = 'brainitive:notifications-unread';
/** A notification arrived through the live channel (BRAI-151): open feeds reload. */
const ARRIVED_EVENT = 'brainitive:notifications-arrived';
const POLL_MS = 30_000;

/** Tell every listener (the bell badge, the tab title) the new unread count. */
export function announceUnread(count: number) {
    window.dispatchEvent(new CustomEvent<number>(UNREAD_EVENT, { detail: count }));
}

const TITLE_BADGE = /^\(\d+\+?\) /;

/** "(3) Board - Brainitive": keeps the badge on the tab title across Inertia title changes. */
function useTitleBadge(count: number, enabled: boolean) {
    useEffect(() => {
        if (!enabled) return;
        const apply = () => {
            const base = document.title.replace(TITLE_BADGE, '');
            const wanted = count > 0 ? `(${count > 99 ? '99+' : count}) ${base}` : base;
            if (document.title !== wanted) document.title = wanted;
        };
        apply();
        // Inertia's <Head> rewrites the title on every visit: re-apply after it
        const observer = new MutationObserver(apply);
        observer.observe(document.head, { childList: true, subtree: true, characterData: true });
        return () => {
            observer.disconnect();
            document.title = document.title.replace(TITLE_BADGE, '');
        };
    }, [count, enabled]);
}

/**
 * The unread count: starts from the shared prop, refreshes at once when a notification is stored
 * (live channel `user.{id}`, BRAI-151), polls every 30 s as the fallback, refreshes when the window
 * gets focus, and follows actions taken anywhere in the app. Set `manageTitle` in exactly one place.
 */
export function useUnreadCount({ manageTitle = false, poll = true }: { manageTitle?: boolean; poll?: boolean } = {}) {
    const { unreadNotificationsCount, auth } = usePage<PageProps>().props;
    const initial = unreadNotificationsCount ?? 0;
    const [count, setCount] = useState(initial);

    useEffect(() => setCount(initial), [initial]);

    const refresh = useCallback(async () => {
        try {
            const res = await axios.get<{ count: number }>(route('notifications.unread-count'));
            setCount(res.data.count);
        } catch {
            // offline or signed out: keep the last count
        }
    }, []);

    // the polling owner (the bell) also listens live: only the id arrives, the feed is refetched
    useLiveChannel(poll && auth?.user ? `user.${auth.user.id}` : null, {
        onEvent: (e) => {
            // read / archived in another tab (BRAI-151): the badge and the tab title follow at once
            if (e.event === 'notifications.changed') {
                const unread = e.payload.unread_count;
                if (typeof unread === 'number' && unread >= 0) announceUnread(unread);
                else refresh();
                return;
            }
            if (e.event !== 'notification.created') return;
            refresh();
            window.dispatchEvent(new CustomEvent(ARRIVED_EVENT));
        },
        onResync: refresh,
    });

    useEffect(() => {
        const onAnnounce = (e: Event) => setCount((e as CustomEvent<number>).detail);
        window.addEventListener(UNREAD_EVENT, onAnnounce);
        if (!poll) return () => window.removeEventListener(UNREAD_EVENT, onAnnounce);

        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') refresh();
        }, POLL_MS);
        const onFocus = () => refresh();
        const onVisible = () => document.visibilityState === 'visible' && refresh();
        window.addEventListener('focus', onFocus);
        document.addEventListener('visibilitychange', onVisible);
        return () => {
            window.removeEventListener(UNREAD_EVENT, onAnnounce);
            window.clearInterval(interval);
            window.removeEventListener('focus', onFocus);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [refresh, poll]);

    // only one owner may rewrite the title (two would undo each other forever)
    useTitleBadge(count, manageTitle);

    return { count, refresh };
}

export type NotificationAction = 'read' | 'unread' | 'archive' | 'unarchive';

interface FeedOptions {
    filter: NotificationFilter;
    archived: boolean;
    /** First page from the server (inbox page props); otherwise fetched on `reload()`. */
    initial?: NotificationFeed;
    /** Fetch as soon as the options change (default true). */
    enabled?: boolean;
}

export function useNotificationFeed({ filter, archived, initial, enabled = true }: FeedOptions) {
    const [items, setItems] = useState<AppNotification[]>(initial?.data ?? []);
    const [cursor, setCursor] = useState<string | null>(initial?.next_cursor ?? null);
    const [loading, setLoading] = useState(!initial && enabled);
    const [loadingMore, setLoadingMore] = useState(false);
    const [error, setError] = useState(false);
    const request = useRef(0);
    const loadingRef = useRef(false);
    const itemsRef = useRef(items);
    itemsRef.current = items;
    /** read / archive actions: started so far, and still on their way (a live merge waits for them) */
    const actsRef = useRef(0);
    const actingRef = useRef(0);
    const skipFirst = useRef(!!initial);

    const fetchPage = useCallback(
        async (next: string | null) => {
            const res = await axios.get<NotificationFeed>(route('notifications.index'), {
                params: { filter, archived: archived ? 1 : 0, ...(next ? { cursor: next } : {}) },
            });
            announceUnread(res.data.unread_count);
            return res.data;
        },
        [filter, archived],
    );

    useEffect(() => {
        loadingRef.current = loading;
    }, [loading]);

    const reload = useCallback(async (fresh = false) => {
        const id = ++request.current;
        // another view: never show (and act on) the previous view's rows while the new ones load
        if (fresh) {
            setItems([]);
            setCursor(null);
        }
        setLoading(true);
        setError(false);
        try {
            const feed = await fetchPage(null);
            if (id !== request.current) return;
            setItems(feed.data);
            setCursor(feed.next_cursor);
        } catch {
            if (id === request.current) setError(true);
        } finally {
            if (id === request.current) setLoading(false);
        }
    }, [fetchPage]);

    /** Rows that arrived live (BRAI-151): they slide in; cleared after a few seconds. */
    const [freshIds, setFreshIds] = useState<Set<string>>(() => new Set());
    useEffect(() => {
        if (freshIds.size === 0) return;
        const timer = window.setTimeout(() => setFreshIds(new Set()), 4_000);
        return () => window.clearTimeout(timer);
    }, [freshIds]);

    /**
     * A notification arrived live: the first page is fetched and merged on top — rows loaded with
     * "Load more" stay, the cursor still points behind them, nothing jumps under the pointer.
     */
    const mergeLatest = useCallback(async () => {
        if (loadingRef.current) return reload();
        const id = request.current;
        const acts = actsRef.current;
        try {
            const feed = await fetchPage(null);
            if (id !== request.current) return;
            // a read / archive happened meanwhile: this page may predate it — ask again in a moment
            if (acts !== actsRef.current || actingRef.current > 0) {
                window.setTimeout(() => void mergeLatestRef.current(), 500);
                return;
            }
            const before = itemsRef.current;
            const known = new Set(before.map((n) => n.id));
            const arrived = feed.data.filter((n) => !known.has(n.id)).map((n) => n.id);
            if (arrived.length > 0 && before.length > 0) setFreshIds(new Set(arrived));
            const latest = new Set(feed.data.map((n) => n.id));
            setItems((prev) => [...feed.data, ...prev.filter((n) => !latest.has(n.id))]);
            // an empty list starts paging from this page; otherwise the cursor still points behind the loaded rows
            if (before.length === 0) setCursor(feed.next_cursor);
            setError(false);
        } catch {
            /* the next poll / event tries again */
        }
    }, [fetchPage, reload]);
    const mergeLatestRef = useRef(mergeLatest);
    mergeLatestRef.current = mergeLatest;

    // a notification arrived live: the inbox (not the archive) shows it without a reload
    useEffect(() => {
        if (!enabled || archived) return;
        const onArrived = () => void mergeLatest();
        window.addEventListener(ARRIVED_EVENT, onArrived);
        return () => window.removeEventListener(ARRIVED_EVENT, onArrived);
    }, [enabled, archived, mergeLatest]);

    useEffect(() => {
        if (!enabled) return;
        if (skipFirst.current) {
            skipFirst.current = false;
            return;
        }
        reload(true);
    }, [enabled, reload]);

    const loadMore = useCallback(async () => {
        if (!cursor || loadingMore) return;
        const id = request.current;
        setLoadingMore(true);
        try {
            const feed = await fetchPage(cursor);
            if (id !== request.current) return;
            setItems((prev) => {
                const seen = new Set(prev.map((n) => n.id));
                return [...prev, ...feed.data.filter((n) => !seen.has(n.id))];
            });
            setCursor(feed.next_cursor);
        } catch {
            setError(true);
        } finally {
            setLoadingMore(false);
        }
    }, [cursor, loadingMore, fetchPage]);

    /**
     * Optimistic: rows change at once and roll back if the server refuses. Archived rows leave the
     * inbox (and un-archived ones leave the archive); read rows stay in "Unread" until the next load,
     * so nothing jumps away under the pointer.
     */
    const act = useCallback(
        async (ids: string[], action: NotificationAction) => {
            if (ids.length === 0) return;
            const now = new Date().toISOString();
            const before = items;
            const leaves = (action === 'archive' && !archived) || (action === 'unarchive' && archived) || (action === 'unread' && archived);
            setItems((prev) =>
                leaves
                    ? prev.filter((n) => !ids.includes(n.id))
                    : prev.map((n) =>
                          !ids.includes(n.id)
                              ? n
                              : action === 'read'
                                ? { ...n, read_at: n.read_at ?? now }
                                : action === 'unread'
                                  ? { ...n, read_at: null, archived_at: null }
                                  : n,
                      ),
            );
            actsRef.current++;
            actingRef.current++;
            try {
                const res =
                    ids.length === 1
                        ? await axios.post<{ unread_count: number }>(route(`notifications.${action}`, ids[0]))
                        : await axios.post<{ unread_count: number }>(route('notifications.bulk'), { ids, action });
                announceUnread(res.data.unread_count);
                // a page fetched before this change would bring the old state back: fetch again
                if (loadingRef.current) reload();
            } catch {
                setItems(before);
                throw new Error('notification action failed');
            } finally {
                actingRef.current--;
            }
        },
        [items, archived, reload],
    );

    const markAllRead = useCallback(async () => {
        const before = items;
        const now = new Date().toISOString();
        setItems((prev) => prev.map((n) => ({ ...n, read_at: n.read_at ?? now })));
        actsRef.current++;
        actingRef.current++;
        try {
            await axios.post(route('notifications.read-all'));
            announceUnread(0);
        } catch {
            setItems(before);
            throw new Error('mark all read failed');
        } finally {
            actingRef.current--;
        }
    }, [items]);

    return { items, loading, loadingMore, error, hasMore: !!cursor, reload, loadMore, act, markAllRead, freshIds };
}
