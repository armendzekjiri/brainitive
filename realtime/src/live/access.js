// Re-checks live channel access with Brainitive (BRAI-151): POST /api/internal/collab/live/access
// (app/Http/Controllers/LiveInternalController.php), same bearer as the co-editing internal API.

export function createAccessClient({ appUrl, internalKey, fetchImpl = globalThis.fetch, timeoutMs = 10_000 }) {
    const url = `${appUrl.replace(/\/+$/, '')}/api/internal/collab/live/access`;

    /**
     * @param {{id: number, channels: string[]}[]} users
     * @returns {Promise<Record<string, string[]>>} allowed channels per user id
     */
    return async function access(users) {
        const response = await fetchImpl(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${internalKey}` },
            body: JSON.stringify({ users }),
            signal: AbortSignal.timeout(timeoutMs),
        });
        if (!response.ok) throw new Error(`Brainitive answered ${response.status}`);
        const data = await response.json();
        return data?.users && typeof data.users === 'object' ? data.users : {};
    };
}
