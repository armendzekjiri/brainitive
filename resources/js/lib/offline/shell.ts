/**
 * Offline mode (BRAI-82): the app shell in the Cache API, read by `public/sw.js`.
 *
 * `/offline` (Pages/Offline/Shell, rendered without a session or shared props) plus the built
 * files it needs: the closure of the app entry and the shell page over Vite's manifest (static
 * imports, CSS, fonts). Hashed assets are immutable; the cache is rebuilt when the manifest changes.
 * Only for users who turned offline mode on; deleted with their data.
 */

export const SHELL_CACHE = 'brainitive-offline-shell-v1';
const STAGING_CACHE = `${SHELL_CACHE}-next`;
const SHELL_URL = '/offline';
const MANIFEST_URL = '/build/manifest.json';
const MANIFEST_MARK = '/__offline-manifest';
const ENTRIES = ['resources/js/app.tsx', 'resources/js/Pages/Offline/Shell.tsx'];

type ManifestChunk = { file: string; imports?: string[]; css?: string[]; assets?: string[] };

export function cacheSupported(): boolean {
    return typeof window !== 'undefined' && 'caches' in window;
}

/** Every built file the shell needs (pure, exported for tests). */
export function shellFiles(manifest: Record<string, ManifestChunk>, entries: string[] = ENTRIES): string[] {
    const files = new Set<string>();
    const seen = new Set<string>();
    const visit = (key: string) => {
        if (seen.has(key)) return;
        seen.add(key);
        const chunk = manifest[key];
        if (!chunk) return;
        files.add(chunk.file);
        chunk.css?.forEach((f) => files.add(f));
        // fonts: woff2 is enough for every browser that has service workers
        chunk.assets?.filter((f) => !f.endsWith('.woff')).forEach((f) => files.add(f));
        chunk.imports?.forEach(visit);
    };
    entries.forEach(visit);
    return [...files].map((f) => `/build/${f}`);
}

export async function isShellCached(): Promise<boolean> {
    if (!cacheSupported()) return false;
    try {
        const cache = await caches.open(SHELL_CACHE);
        return !!(await cache.match(SHELL_URL));
    } catch {
        return false;
    }
}

/**
 * Cache (or refresh) the shell. `force` re-downloads even when the build didn't change.
 * Throws when something essential couldn't be fetched, so the setting can say "not ready".
 */
export async function cacheShell(force = false): Promise<void> {
    if (!cacheSupported()) throw new Error('This browser cannot keep the app for offline use.');

    const manifestResponse = await fetch(MANIFEST_URL, { cache: 'no-store', credentials: 'same-origin' });
    if (!manifestResponse.ok) throw new Error('The app files are not available (development server?).');
    const manifestText = await manifestResponse.text();
    const manifest = JSON.parse(manifestText) as Record<string, ManifestChunk>;

    const version = manifestText.length + ':' + hash(manifestText);
    const cache = await caches.open(SHELL_CACHE);
    const mark = await cache.match(MANIFEST_MARK);
    if (mark && (await mark.text()) === version && !force && (await cache.match(SHELL_URL))) return;

    // Download into a staging cache: the working shell stays until the new one is complete
    // (a connection that drops halfway must not leave the device without an offline app).
    await caches.delete(STAGING_CACHE);
    const staging = await caches.open(STAGING_CACHE);
    try {
        // the shell renders without a session (see OfflineSyncController::shell)
        const shell = await fetch(SHELL_URL, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (!shell.ok) throw new Error('Could not download the offline app.');
        await staging.put(SHELL_URL, shell);
        const results = await Promise.allSettled(shellFiles(manifest).map((url) => staging.add(url)));
        if (results.some((r) => r.status === 'rejected')) throw new Error('Could not download all app files.');
        await staging.put(MANIFEST_MARK, new Response(version));

        // swap without a gap: the new files go in first, the shell page and the build mark last, and
        // only then are the old build's files removed. A page that closes or navigates halfway (a
        // copy takes a while in Safari) always leaves a working shell — the old one or the new one.
        const live = await caches.open(SHELL_CACHE);
        const fresh = await staging.keys();
        const last = (request: Request) => {
            const path = new URL(request.url).pathname;
            return path === SHELL_URL || path === MANIFEST_MARK;
        };
        for (const request of [...fresh.filter((r) => !last(r)), ...fresh.filter(last)]) {
            const response = await staging.match(request);
            if (response) await live.put(request, response);
        }
        const keep = new Set(fresh.map((r) => r.url));
        for (const request of await live.keys()) {
            if (!keep.has(request.url)) await live.delete(request);
        }
    } finally {
        await caches.delete(STAGING_CACHE);
    }
}

export async function dropShell(): Promise<void> {
    if (!cacheSupported()) return;
    try {
        await Promise.all([caches.delete(SHELL_CACHE), caches.delete(STAGING_CACHE)]);
    } catch {
        // nothing cached
    }
}

/** Small non-cryptographic hash, only to notice a new build. */
function hash(text: string): string {
    let h = 0;
    for (let i = 0; i < text.length; i++) h = (Math.imul(31, h) + text.charCodeAt(i)) | 0;
    return (h >>> 0).toString(36);
}
