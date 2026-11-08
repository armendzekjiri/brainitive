import { REMOTE } from '../env';
import { expect, test } from '../fixtures';

/**
 * Brainitive Desktop download page (v1.49.0): public, three platform tiles, every button disabled
 * "Coming soon" until DESKTOP_DOWNLOAD_*_URL are set (empty in the e2e app); linked from the homepage.
 */
test.describe('desktop download page', () => {
    test('a guest sees macOS, Windows and Linux as coming soon, in light and dark on a phone', async ({ browser }) => {
        test.skip(REMOTE, 'on dev and production nginx sends /download to the marketing website, not this app page');
        for (const colorScheme of ['light', 'dark'] as const) {
            const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, colorScheme, viewport: { width: 375, height: 812 } });
            const page = await context.newPage();
            const errors: string[] = [];
            page.on('pageerror', (e) => errors.push(e.message));

            await page.goto('/download');
            await expect(page.getByRole('heading', { level: 1, name: 'Brainitive Desktop' })).toBeVisible();
            for (const platform of ['macOS', 'Windows', 'Linux']) {
                const tile = page.getByRole('region', { name: platform, exact: true });
                await expect(tile.getByRole('heading', { name: platform, exact: true })).toBeVisible();
                await expect(tile.getByRole('button', { name: 'Coming soon' })).toBeDisabled();
                await expect(tile.getByRole('link')).toHaveCount(0);
            }
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, 'horizontal scroll').toBeLessThanOrEqual(0);
            expect(errors).toEqual([]);
            await context.close();
        }
    });
});
