import { expect, test, urls } from '../fixtures';

test.describe('smoke', () => {
    test('home and the seeded board load', async ({ page }) => {
        await page.goto(urls.home);
        await expect(page.getByText('E2E Workspace').first()).toBeVisible();

        await page.goto(urls.board());
        await expect(page.getByText('Homepage hero')).toBeVisible();
        await expect(page.getByText('Checkout bug')).toBeVisible();
    });

    test('guests are sent to the login page', async ({ browser }) => {
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const page = await context.newPage();
        await page.goto(urls.board());
        await expect(page).toHaveURL(/\/login$/);
        await context.close();
    });
});
