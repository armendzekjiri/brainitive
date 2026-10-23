import type { BrowserContext } from '@playwright/test';
/*
 * Built assets (/build/assets/*, content-hashed, immutable) served from memory after the first
 * fetch in this worker. `php artisan serve` closes the connection after every request and an
 * app page loads ~200 chunks, so without this a parallel run exhausts the loopback ports
 * (net::ERR_ADDRESS_INVALID). The app's own requests are untouched.
 */
type CachedAsset = { status: number; headers: Record<string, string>; body: Buffer };
const assetCache = new Map<string, Promise<CachedAsset>>();

export async function cacheBuildAssets(context: BrowserContext): Promise<void> {
    await context.route('**/build/assets/**', async (route) => {
        const url = route.request().url();
        let asset = assetCache.get(url);
        if (!asset) {
            asset = route.fetch().then(async (response) => ({
                status: response.status(),
                headers: response.headers(),
                body: await response.body(),
            }));
            assetCache.set(url, asset);
            asset.catch(() => assetCache.delete(url));
        }
        try {
            await route.fulfill(await asset);
        } catch {
            await route.continue();
        }
    });
}
