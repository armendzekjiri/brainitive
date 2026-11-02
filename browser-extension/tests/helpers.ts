import { vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';

export const SERVER = 'https://brainitive.example.com';

export interface Call {
    url: string;
    init: RequestInit;
}

/** A JSON response like `fetch` returns. */
export function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/**
 * Replaces `fetch` with a router: `routes[url]` answers a request (exact URL, query ignored).
 * Every call is recorded; an unknown URL fails the request like a network error.
 */
export function mockFetch(routes: Record<string, (init: RequestInit) => Response | Promise<Response>>): Call[] {
    const calls: Call[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (input: string | URL, init: RequestInit = {}) => {
            const url = String(input);
            calls.push({ url, init });
            const route = routes[url.split('?')[0] ?? url];
            if (!route) throw new TypeError(`Failed to fetch ${url}`);
            return route(init);
        }),
    );
    return calls;
}

/** Metadata of a well-behaved server at `server`. */
export function metadata(server = SERVER) {
    return {
        authorization_endpoint: `${server}/oauth/authorize`,
        token_endpoint: `${server}/oauth/token`,
        registration_endpoint: `${server}/oauth/register`,
    };
}

/** A stored, valid session (what signIn saves). */
export async function storeSession(overrides: Record<string, unknown> = {}) {
    const session = {
        server: SERVER,
        tokenEndpoint: `${SERVER}/oauth/token`,
        clientId: 'client-1',
        redirectUri: 'https://extension.example/',
        accessToken: 'access-1',
        refreshToken: 'refresh-1',
        expiresAt: Date.now() + 3_600_000,
        ...overrides,
    };
    await fakeBrowser.storage.local.set({ session, account: { server: session.server } });
    return session;
}

/** A promise with its resolve function, to hold a request open. */
export function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => (resolve = r));
    return { promise, resolve };
}
