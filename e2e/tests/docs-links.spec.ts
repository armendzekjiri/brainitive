import type { Browser } from '@playwright/test';
import { expect, test } from '../fixtures';

/*
 * The public documentation lives on docs.brainitive.app (docs-site/, VitePress). The homepage, the
 * account menu, the help window and the command palette all lead there in a new tab.
 */
const DOCS = 'https://docs.brainitive.app';

async function guestPage(browser: Browser) {
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
        httpCredentials: process.env.E2E_HTTP_USER ? { username: process.env.E2E_HTTP_USER, password: process.env.E2E_HTTP_PASSWORD ?? '' } : undefined,
        viewport: { width: 1280, height: 900 },
    });
    return { context, page: await context.newPage() };
}

test.describe('documentation links', () => {
    test('the homepage links the docs and the self-hosting guide', async ({ browser }) => {
        const { context, page } = await guestPage(browser);
        await page.goto('/');

        const footer = page.locator('footer').last();
        await expect(footer.getByRole('link', { name: 'Docs', exact: true })).toHaveAttribute('href', DOCS);
        await expect(footer.getByRole('link', { name: 'Self-hosting', exact: true })).toHaveAttribute('href', `${DOCS}/self-hosting/`);
        const guide = page.getByRole('link', { name: 'Self-host guide' });
        await expect(guide).toHaveAttribute('href', `${DOCS}/self-hosting/`);
        await expect(guide).toHaveAttribute('target', '_blank');
        await context.close();
    });

    test('signed in: account menu, help window and command palette open the docs', async ({ page }) => {
        await page.goto('/dashboard');

        await page.getByRole('button', { name: /Erin Owner/ }).last().click();
        const item = page.getByRole('menuitem', { name: 'Documentation' });
        await expect(item).toHaveAttribute('href', DOCS);
        await expect(item).toHaveAttribute('target', '_blank');
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: 'Help & what’s new' }).click();
        const help = page.getByRole('dialog', { name: 'Brainitive Help & Changelog' });
        await expect(help.getByRole('link', { name: 'Documentation' })).toHaveAttribute('href', DOCS);
        await help.getByRole('button', { name: 'Close' }).click();
        await expect(help).toBeHidden();

        // the command palette opens the docs in a new tab
        await page.keyboard.press('Control+k');
        await page.getByRole('combobox', { name: 'Search or type a command' }).fill('documentation');
        const popup = page.waitForEvent('popup');
        await page.getByRole('option', { name: /^Documentation/ }).click();
        expect((await popup).url()).toContain('docs.brainitive.app');
    });
});
