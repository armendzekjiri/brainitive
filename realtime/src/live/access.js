// Re-checks live channel access with Brainitive (BRAI-151): POST /api/internal/collab/live/access
// (app/Http/Controllers/LiveInternalController.php), same bearer as the co-editing internal API.
import { createAppFetch, describeTarget } from '../appFetch.js';

/** @param appHost COLLAB_APP_HOST (see ../appFetch.js) */
export function createAccessClient({ appUrl, appHost = '', internalKey, fetchImpl = globalThis.fetch, timeoutMs = 10_000 }) {
    const url = `${appUrl.replace(/\/+$/, '')}/api/internal/collab/live/access`;
    const target = describeTarget('POST', url, appHost);
    const send = createAppFetch({ host: appHost, fetchImpl });

    /**
     * @param {{id: number, channels: string[], devices?: number[]}[]} users `devices`: the app devices of the
     *   user's sockets (tokens with `dev`)
     * @returns {Promise<{users: Record<string, string[]>, revokedDevices: number[]}>} allowed channels per user id;
     *   the asked devices that are revoked / gone (an older Brainitive answers none)
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
        return {
            users: data?.users && typeof data.users === 'object' ? data.users : {},
            revokedDevices: Array.isArray(data?.revoked_devices) ? data.revoked_devices.filter((id) => Number.isInteger(id) && id > 0) : [],
        };
    };
}
