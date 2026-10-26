/**
 * Live boards + plans (BRAI-152): this tab's id, sent as `X-Live-Client` with this tab's requests to
 * Brainitive (axios and Inertia share the same axios instance). The server echoes it as `client_id`
 * in the live events a request causes, so the tab that made a change ignores its own echo — its own
 * optimistic update / Inertia reload already shows it. Never sent to other origins.
 */
import axios from 'axios';
import { usePage } from '@inertiajs/react';
import { useRef } from 'react';

const randomId = (): string => {
    try {
        return `t${crypto.randomUUID().replace(/-/g, '').slice(0, 20)}`;
    } catch {
        return `t${Math.random().toString(36).slice(2, 12)}${Date.now().toString(36)}`;
    }
};

export const LIVE_CLIENT_ID = randomId();

let installed = false;

const sameOrigin = (url: string | undefined, base: string | undefined): boolean => {
    try {
        return new URL(url ?? '', base ?? window.location.href).origin === window.location.origin;
    } catch {
        return false;
    }
};

/** Send the id with every same-origin request of this tab (idempotent). */
export function installLiveClientId(): void {
    if (installed || typeof window === 'undefined') return;
    installed = true;
    axios.interceptors.request.use((config) => {
        if (sameOrigin(config.url, config.baseURL)) config.headers.set('X-Live-Client', LIVE_CLIENT_ID);
        return config;
    });
}

/** Was this live event caused by this tab (its id and its user — both, so nobody can reuse a seen tab id)? */
export const isOwnEcho = (payload: Record<string, unknown>, userId: number | undefined): boolean =>
    userId !== undefined && payload.client_id === LIVE_CLIENT_ID && payload.actor_id === userId;

/** The signed-in user's id, always current inside callbacks (for isOwnEcho). */
export function useMyUserIdRef() {
    const id = (usePage().props as { auth?: { user?: { id: number } | null } }).auth?.user?.id;
    const ref = useRef(id);
    ref.current = id;
    return ref;
}
