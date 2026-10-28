import type { Locator, Page } from '@playwright/test';
import { expect, test } from '../fixtures';

/*
 * The app's top bar on a phone (iPhone Pro Max size): it stays in reach while a long page scrolls,
 * every button is a real touch target (≥ 40 px), the bell's panel opens and closes by tap, and
 * nothing scrolls sideways. The theme switch lives in the account menu on phones.
 */
test.use({ viewport: { width: 440, height: 956 }, hasTouch: true, isMobile: true });

const TOUCH = 40;

async function expectTouchTarget(target: Locator, name: string) {
    const box = await target.boundingBox();
    expect(box, `${name} is on screen`).not.toBeNull();
    expect(box!.width, `${name} width`).toBeGreaterThanOrEqual(TOUCH);
    expect(box!.height, `${name} height`).toBeGreaterThanOrEqual(TOUCH);
}

const topBar = (page: Page) => page.locator('header').filter({ has: page.getByRole('button', { name: /^Notifications/ }) });

test.describe('the top bar on a phone', () => {
    test('touch-sized buttons; the bell panel opens and closes by tap', async ({ page }, testInfo) => {
        await page.goto('/dashboard');
        const bar = topBar(page);
        await expect(bar).toBeVisible();

        await expectTouchTarget(bar.getByRole('button', { name: /^Notifications/ }), 'bell');
        await expectTouchTarget(bar.getByRole('button', { name: 'Search' }), 'search');
        await expectTouchTarget(bar.getByRole('button', { name: 'Help & what’s new' }), 'help');
        await expectTouchTarget(bar.getByRole('button', { name: 'Toggle Sidebar' }), 'sidebar');
        await expectTouchTarget(bar.getByRole('button', { name: 'Create', exact: true }), 'create');
        // light / dark is in the account menu on phones, so the bar keeps room for the targets above
        await expect(bar.getByTitle(/Switch to (dark|light) mode/)).toBeHidden();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
        await page.screenshot({ path: testInfo.outputPath('top-bar.png') });

        await bar.getByRole('button', { name: /^Notifications/ }).tap();
        const panel = page.getByRole('dialog', { name: 'Notifications' });
        await expect(panel).toBeVisible();
        const close = panel.getByRole('button', { name: 'Close' });
        await expectTouchTarget(close, 'panel close');
        await page.screenshot({ path: testInfo.outputPath('bell-panel.png') });
        await close.tap();
        await expect(panel).toBeHidden();

        // the search icon opens the command palette
        await bar.getByRole('button', { name: 'Search' }).tap();
        await expect(page.getByRole('combobox', { name: 'Search or type a command' })).toBeVisible();
    });

    test('the bar stays at the top while a long page scrolls, and still works there', async ({ page }) => {
        await page.goto('/dashboard');
        const bar = topBar(page);
        await expect(bar).toBeVisible();
        // the app fills exactly the visible screen (100dvh): the page itself never scrolls, so the
        // bar can't slide under the browser's own bars — the content scrolls inside <main>
        const content = page.locator('main main').first();
        await content.evaluate((main) => {
            const spacer = document.createElement('div');
            spacer.style.height = '4000px';
            main.appendChild(spacer);
            main.scrollTop = 3000;
        });
        await expect.poll(() => content.evaluate((main) => main.scrollTop)).toBeGreaterThan(1000);
        await page.mouse.wheel(0, 2000);
        expect(await page.evaluate(() => window.scrollY)).toBe(0);
        expect(await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight)).toBeLessThanOrEqual(0);
        await expect.poll(async () => (await bar.boundingBox())?.y).toBe(0);

        await bar.getByRole('button', { name: /^Notifications/ }).tap();
        await expect(page.getByRole('dialog', { name: 'Notifications' })).toBeVisible();
    });
});
