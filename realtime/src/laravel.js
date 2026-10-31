// Client for Brainitive's internal collab API (app/Http/Controllers/CollabInternalController.php).
import { createAppFetch, describeTarget } from './appFetch.js';

export class LaravelError extends Error {
    constructor(status, message, target = '') {
        super(message ?? `Brainitive answered ${status}`);
        this.status = status;
        /** the call that failed, for log lines (BRAI-345) */
        this.target = target;
    }
}

/**
 * @param appHost COLLAB_APP_HOST: call appUrl (e.g. https://127.0.0.1) with this Host header (appFetch.js)
 */
export function createLaravelClient({ appUrl, appHost = '', internalKey, fetchImpl = globalThis.fetch, timeoutMs = 15_000 }) {
    const base = `${appUrl.replace(/\/+$/, '')}/api/internal/collab`;
    const send = createAppFetch({ host: appHost, fetchImpl });

    async function call(method, path, body) {
        const url = `${base}${path}`;
        const target = describeTarget(method, url, appHost);
        let response;
        try {
            response = await send(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${internalKey}`,
                    ...(body ? { 'Content-Type': 'application/json' } : {}),
                },
                body: body ? JSON.stringify(body) : undefined,
                signal: AbortSignal.timeout(timeoutMs),
            });
        } catch (error) {
            const cause = error?.cause?.code ?? error?.code ?? error?.cause?.message ?? error?.message ?? error;
            throw new LaravelError(0, `Brainitive unreachable at ${target}: ${cause}`, target);
        }
        if (response.status === 204) return null;
        if (response.status >= 300 && response.status < 400) {
            // e.g. http:// → https:// on the same host: the call never reaches the app
            throw new LaravelError(response.status, `${target} redirects to ${response.headers?.get?.('location') ?? '?'} — point COLLAB_APP_URL at the final address`, target);
        }
        if (!response.ok) throw new LaravelError(response.status, `${target} answered ${response.status}`, target);
        return response.json();
    }

    const documentPath = (name) => `/documents/${encodeURIComponent(name)}`;

    return {
        /** Stored Yjs state (Uint8Array) or null for a new document. 409 = stale epoch, 404 = page gone. */
        async loadState(documentName) {
            const data = await call('GET', documentPath(documentName));
            return data?.state ? new Uint8Array(Buffer.from(data.state, 'base64')) : null;
        },
        async storeState(documentName, update) {
            await call('PUT', documentPath(documentName), { state: Buffer.from(update).toString('base64') });
        },
        /** {current: bool, users: {id: 'edit' | 'view' | null}} */
        async access(documentName, userIds) {
            return call('POST', '/access', { document: documentName, user_ids: userIds });
        },
    };
}
