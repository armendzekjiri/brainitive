// Client for Brainitive's internal collab API (app/Http/Controllers/CollabInternalController.php).

export class LaravelError extends Error {
    constructor(status, message) {
        super(message ?? `Brainitive answered ${status}`);
        this.status = status;
    }
}

export function createLaravelClient({ appUrl, internalKey, fetchImpl = globalThis.fetch, timeoutMs = 15_000 }) {
    const base = `${appUrl.replace(/\/+$/, '')}/api/internal/collab`;

    async function call(method, path, body) {
        let response;
        try {
            response = await fetchImpl(`${base}${path}`, {
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
            throw new LaravelError(0, `Brainitive unreachable: ${error?.message ?? error}`);
        }
        if (response.status === 204) return null;
        if (!response.ok) throw new LaravelError(response.status);
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
