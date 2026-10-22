import { useEffect, useState } from 'react';
import { browser } from 'wxt/browser';
import { getSession } from './storage';
import type { Destinations, Session } from './types';

/** The stored session, kept in sync when another context signs in or out. */
export function useSession(): { session: Session | null; loading: boolean } {
    const [state, setState] = useState<{ session: Session | null; loading: boolean }>({ session: null, loading: true });

    useEffect(() => {
        getSession().then((session) => setState({ session, loading: false }));
        const listener = (changes: Record<string, { newValue?: unknown }>, area: string) => {
            if (area === 'local' && 'session' in changes) {
                setState({ session: (changes.session.newValue as Session | undefined) ?? null, loading: false });
            }
        };
        browser.storage.onChanged.addListener(listener);
        return () => browser.storage.onChanged.removeListener(listener);
    }, []);

    return state;
}

/**
 * Where the user can save. Renders the cached list right away (popups must feel
 * instant) and refreshes it in the background.
 */
export function useDestinations(load: () => Promise<Destinations>): { destinations: Destinations | null; error: string | null; reload: () => void } {
    const [destinations, setDestinations] = useState<Destinations | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [nonce, setNonce] = useState(0);

    useEffect(() => {
        let cancelled = false;
        browser.storage.local.get('destinations').then(({ destinations: cached }) => {
            if (!cancelled && cached) setDestinations((current) => current ?? (cached as Destinations));
        });
        load()
            .then((fresh) => {
                if (cancelled) return;
                setDestinations(fresh);
                setError(null);
                browser.storage.local.set({ destinations: fresh });
            })
            .catch((e: Error) => !cancelled && setError(e.message));
        return () => {
            cancelled = true;
        };
    }, [nonce]);

    return { destinations, error, reload: () => setNonce((n) => n + 1) };
}
