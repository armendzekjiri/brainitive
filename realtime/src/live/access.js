// Re-checks live channel access with Brainitive (BRAI-151): POST /api/internal/collab/live/access
// (app/Http/Controllers/LiveInternalController.php), same bearer as the co-editing internal API.
import { createAppFetch, describeTarget } from '../appFetch.js';

/** @param appHost COLLAB_APP_HOST (see ../appFetch.js) */
export function createAccessClient({ appUrl, appHost = '', internalKey, fetchImpl = globalThis.fetch, timeoutMs = 10_000 }) {
    const url = `${appUrl.replace(/\/+$/, '')}/api/internal/collab/live/access`;
    const target = describeTarget('POST', url, appHost);
    const send = createAppFetch({ host: appHost, fetchImpl });

    /**
     * @param {{id: number, channels: string[]}[]} users
     * @returns {Promise<Record<string, string[]>>} allowed channels per user id
     */
    return async function access(users) {
        let response;
        try {
            response = await send(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${internalKey}` },
                body: JSON.stringify({ users }),
                signal: AbortSignal.timeout(timeoutMs),
            });
        } catch (error) {
            throw new Error(`Brainitive unreachable at ${target}: ${error?.cause?.code ?? error?.code ?? error?.message ?? error}`);
        }
        if (!response.ok) throw new Error(`${target} answered ${response.status}`);
        const data = await response.json();
        return data?.users && typeof data.users === 'object' ? data.users : {};
    };
}
