import { useEffect, useSyncExternalStore } from 'react';
import type { SmartLinkResult } from '@/types';

/**
 * Live data of smart links (BRAI-143): one cache per page load, one batched request per tick
 * (`smart-links.index`, up to 100 refs each), refreshed when the window gets focus again.
 */

export type SmartLinkEntry =
    | { state: 'loading' }
    | { state: 'error' }
    | SmartLinkResult;

const LOADING: SmartLinkEntry = { state: 'loading' };
const BATCH = 100;
/** Don't refetch on every focus flicker. */
const FOCUS_REFRESH_MS = 10_000;

const cache = new Map<string, SmartLinkEntry>();
const listeners = new Map<string, Set<() => void>>();
const queued = new Map<string, Set<string>>();
let flushScheduled = false;
let lastFocusRefresh = 0;

const keyOf = (workspaceSlug: string, ref: string) => `${workspaceSlug}\u0000${ref}`;

function notify(key: string) {
    listeners.get(key)?.forEach((listener) => listener());
}

function enqueue(workspaceSlug: string, ref: string) {
    let refs = queued.get(workspaceSlug);
    if (!refs) queued.set(workspaceSlug, (refs = new Set()));
    refs.add(ref);
    if (!flushScheduled) {
        flushScheduled = true;
        // every chip rendered in this tick goes into the same request
        setTimeout(flush, 0);
    }
}

function flush() {
    flushScheduled = false;
    const batches = [...queued.entries()];
    queued.clear();
    for (const [workspaceSlug, refSet] of batches) {
        const refs = [...refSet];
        for (let i = 0; i < refs.length; i += BATCH) {
            const chunk = refs.slice(i, i + BATCH);
            window.axios
                .get<{ links: Record<string, SmartLinkResult> }>(route('smart-links.index', workspaceSlug), { params: { refs: chunk } })
                .then(({ data }) => {
                    for (const ref of chunk) {
                        const key = keyOf(workspaceSlug, ref);
                        cache.set(key, data.links[ref] ?? { state: 'not_found' });
                        notify(key);
                    }
                })
                .catch(() => {
                    for (const ref of chunk) {
                        const key = keyOf(workspaceSlug, ref);
                        // a failed refresh keeps what we had
                        const current = cache.get(key);
                        if (!current || current.state === 'loading') {
                            cache.set(key, { state: 'error' });
                            notify(key);
                        }
                    }
                });
        }
    }
}

/** Refetch every smart link currently on screen (e.g. after the window gets focus again). */
export function refreshSmartLinks() {
    for (const [key, set] of listeners) {
        if (set.size === 0) continue;
        const [workspaceSlug, ref] = key.split('\u0000');
        enqueue(workspaceSlug, ref);
    }
}

if (typeof window !== 'undefined') {
    window.addEventListener('focus', () => {
        if (Date.now() - lastFocusRefresh < FOCUS_REFRESH_MS) return;
        lastFocusRefresh = Date.now();
        refreshSmartLinks();
    });
}

/** Live data of one ref (null workspace = no live data: the plain link is shown). */
export function useSmartLink(workspaceSlug: string | null, ref: string): SmartLinkEntry | null {
    const key = workspaceSlug ? keyOf(workspaceSlug, ref) : null;

    const entry = useSyncExternalStore(
        (onChange) => {
            if (!key) return () => {};
            let set = listeners.get(key);
            if (!set) listeners.set(key, (set = new Set()));
            set.add(onChange);
            return () => {
                set!.delete(onChange);
            };
        },
        () => (key ? cache.get(key) ?? LOADING : null),
    );

    useEffect(() => {
        if (workspaceSlug && key && !cache.has(key)) {
            cache.set(key, LOADING);
            enqueue(workspaceSlug, ref);
        }
    }, [workspaceSlug, key, ref]);

    return entry;
}
