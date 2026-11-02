import type { Page } from '@playwright/test';
import { cacheBuildAssets } from '../assets';
import { expect, test, uid, urls } from '../fixtures';
import { REMOTE } from '../env';
import { GUEST } from '../helpers/auth-account';

/**
 * Profile → "Desktop & mobile apps" (App API v1, BRAI-385): a signed-in desktop app shows up with its
 * name, version and platform, and "Sign out" removes it and kills its token.
 *
 * The device is seeded through the e2e-only POST /profile/app-devices/e2e-create (APP_ENV=e2e), the
 * stand-in for the desktop app's sign-in exchange.
 */

async function seedDevice(page: Page, name: string): Promise<{ id: number; access_token: string }> {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!cookie) throw new Error('no XSRF-TOKEN cookie: open an app page first');
    const res = await page.request.post('/profile/app-devices/e2e-create', {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value), Accept: 'application/json' },
        data: { name, platform: 'macos' },
    });
    expect(res.ok(), `seed app device: ${res.status()}`).toBeTruthy();
    return (await res.json()) as { id: number; access_token: string };
}

test('profile lists a signed-in desktop app and signs it out', async ({ page }) => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');
    const name = `E2E Mac ${uid()}`;

    await page.goto(urls.home); // any app page, for the XSRF cookie
    const device = await seedDevice(page, name);
    // the token works before the sign-out
    const before = await page.request.get('/api/v1/devices', { headers: { Authorization: `Bearer ${device.access_token}` } });
    expect(before.status()).toBe(200);

    await page.goto('/profile#app-devices');
    const section = page.locator('#app-devices');
    await expect(section.getByText('Desktop & mobile apps')).toBeVisible();
    const row = section.getByTestId('app-device').filter({ hasText: name });
    await expect(row).toBeVisible();
    await expect(row).toContainText('Brainitive Desktop 0.1.0');
    await expect(row).toContainText('macOS');
    await expect(row).toContainText('signed in');

    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: `Sign out ${name}` }).click();
    await expect(row).toHaveCount(0);

    // the app's token stopped working
    const after = await page.request.get('/api/v1/devices', { headers: { Authorization: `Bearer ${device.access_token}` } });
    expect(after.status()).toBe(401);

    // still gone after a reload
    await page.reload();
    await expect(page.locator('#app-devices').getByTestId('app-device').filter({ hasText: name })).toHaveCount(0);
});

test('signing a device out in Profile ends the web session its webview opened', async ({ page, browser }) => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');
    const name = `E2E Mac ${uid()}`;
    await page.goto(urls.home);
    const device = await seedDevice(page, name);

    // the desktop app's webview signs in through its one-time link (BRAI-377: bound to the device)
    const link = (await (await page.request.post('/api/v1/auth/web-session', { headers: { Authorization: `Bearer ${device.access_token}` } })).json()).data.url as string;
    const webview = await browser.newContext({ storageState: GUEST, userAgent: 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 (KHTML, like Gecko) BrainitiveDesktop/0.4.2' });
    await cacheBuildAssets(webview);
    const webviewPage = await webview.newPage();
    await webviewPage.goto(link);
    await expect(webviewPage).toHaveURL(/\/(dashboard|workspaces)$/);

    // signed out from the other browser
    await page.goto('/profile#app-devices');
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#app-devices').getByTestId('app-device').filter({ hasText: name }).getByRole('button', { name: `Sign out ${name}` }).click();
    await expect(page.locator('#app-devices').getByTestId('app-device').filter({ hasText: name })).toHaveCount(0);

    // the webview's next request lands on the login page; this browser stays signed in
    await webviewPage.goto('/profile');
    await expect(webviewPage).toHaveURL(/\/login$/);
    await page.reload();
    await expect(page).toHaveURL(/\/profile/);
    await webview.close();
});
