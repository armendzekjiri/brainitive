import { createECDH, randomBytes } from 'node:crypto';
import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { REMOTE } from '../env';

/**
 * Web push devices + test notification (BRAI-154): Profile → Notifications lists every registered
 * browser, marks "This device", sends a test push and explains the push service's answer, removes
 * devices and re-registers this browser.
 *
 * Headless Chromium has no push service, so the page's Notification permission and PushManager are
 * stubbed (real P-256 keys, so the server really encrypts and signs); the server answers through
 * App\Support\Push\FakePushService (APP_ENV=e2e + WEBPUSH_FAKE): endpoints containing `e2e-expired`
 * get 410, `e2e-rejected` 403, everything else 201.
 */

const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

function pushKeys() {
    const ecdh = createECDH('prime256v1');
    ecdh.generateKeys();
    return { p256dh: ecdh.getPublicKey('base64url'), auth: randomBytes(16).toString('base64url') };
}

/** Stub Notification permission + PushManager in every page of this context (state survives reloads). */
async function stubPush(page: Page, prefix: string) {
    await page.addInitScript(
        ({ keys, prefix }) => {
            const KEY = '__e2e_push';
            type State = { permission?: NotificationPermission; endpoint?: string; count?: number };
            const load = (): State => {
                try {
                    return JSON.parse(localStorage.getItem(KEY) || '{}') as State;
                } catch {
                    return {};
                }
            };
            const save = (s: State) => localStorage.setItem(KEY, JSON.stringify(s));
            const make = (endpoint: string) => ({
                endpoint,
                expirationTime: null,
                options: {},
                getKey: () => null,
                toJSON: () => ({ endpoint, expirationTime: null, keys }),
                unsubscribe: async () => {
                    const s = load();
                    if (s.endpoint === endpoint) {
                        delete s.endpoint;
                        save(s);
                    }
                    return true;
                },
            });
            if (typeof Notification === 'undefined' || typeof PushManager === 'undefined') return;
            Object.defineProperty(Notification, 'permission', { configurable: true, get: () => load().permission ?? 'default' });
            Notification.requestPermission = async () => {
                save({ ...load(), permission: 'granted' });
                return 'granted';
            };
            PushManager.prototype.getSubscription = async function () {
                const s = load();
                return (s.endpoint ? make(s.endpoint) : null) as unknown as PushSubscription;
            };
            PushManager.prototype.subscribe = async function () {
                const s = load();
                s.count = (s.count ?? 0) + 1;
                s.endpoint = `https://fcm.googleapis.com/fcm/send/${prefix}-${s.count}`;
                save(s);
                return make(s.endpoint) as unknown as PushSubscription;
            };
        },
        { keys: pushKeys(), prefix },
    );
}

async function xsrf(page: Page) {
    return decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
}

/** Register another "device" for the signed-in user, as if that browser had subscribed. */
async function registerDevice(page: Page, endpoint: string, userAgent: string) {
    const response = await page.request.post('/api/push-subscriptions', {
        data: { endpoint, keys: pushKeys() },
        headers: { 'X-XSRF-TOKEN': await xsrf(page), 'User-Agent': userAgent, Accept: 'application/json' },
    });
    expect(response.ok(), `register ${endpoint}: ${response.status()}`).toBeTruthy();
}

/** Start from no devices (the spec owns the owner's push subscriptions). */
async function removeAllDevices(page: Page) {
    const list = await page.request.get('/api/push-subscriptions', { headers: { Accept: 'application/json' } });
    expect(list.ok()).toBeTruthy();
    for (const device of ((await list.json()) as { data: { id: number }[] }).data) {
        await page.request.delete(`/api/push-subscriptions/${device.id}`, { headers: { 'X-XSRF-TOKEN': await xsrf(page), Accept: 'application/json' } });
    }
}

test.describe('push devices', () => {
    test.describe.configure({ mode: 'serial' });
    test.skip(REMOTE, 'needs the fake push service and VAPID keys of the local e2e app');
    // leave the shared owner without devices: later journeys' notifications then push to nobody
    test.afterEach(async ({ page }) => removeAllDevices(page));

    test('register this browser, send test notifications and read what the push service answered', async ({ page }) => {
        const id = uid('push');
        await stubPush(page, `${id}-this`);
        await page.goto(urls.home); // (not /profile: the hash link below would not reload it)
        await removeAllDevices(page);
        await registerDevice(page, `https://fcm.googleapis.com/fcm/send/${id}-e2e-expired`, WINDOWS_CHROME);
        await registerDevice(page, `https://web.push.apple.com/${id}-e2e-rejected`, IPHONE_SAFARI);

        await page.goto('/profile#notifications');
        const settings = page.getByTestId('push-settings');
        await expect(settings.getByText('Browser notifications are off')).toBeVisible();
        const rows = settings.getByTestId('push-device');
        await expect(rows).toHaveCount(2);
        await expect(settings.getByText('Install Brainitive as an app to see its icon on notifications')).toBeVisible();

        // turn on → this browser is listed and marked
        await settings.getByRole('button', { name: 'Turn on' }).click();
        await expect(settings.getByText('Browser notifications are on for this device')).toBeVisible();
        await expect(rows).toHaveCount(3);
        const thisDevice = rows.filter({ has: page.getByText('This device', { exact: true }) });
        await expect(thisDevice).toHaveCount(1);
        await expect(thisDevice).toContainText('Chrome/Edge (Google push)');
        await expect(thisDevice).toContainText('Nothing delivered yet');

        // the main button tests this browser: accepted + what to check when nothing shows up
        await settings.getByRole('button', { name: 'Send test notification', exact: true }).click();
        const delivered = thisDevice.getByTestId('push-test-result');
        await expect(delivered).toHaveAttribute('data-status', 'delivered');
        await expect(delivered).toContainText('the push service accepted it');
        await expect(thisDevice).toContainText(/Last delivered/);

        // another device whose browser dropped the subscription → expired, flagged, steps to re-register there
        const pc = rows.filter({ hasText: 'Chrome on Windows' }).filter({ hasNot: page.getByText('This device', { exact: true }) });
        await pc.getByRole('button', { name: /Send test notification to/ }).click();
        const expired = pc.getByTestId('push-test-result');
        await expect(expired).toHaveAttribute('data-status', 'expired');
        await expect(expired).toContainText('This subscription has expired (HTTP 410)');
        await expect(expired).toContainText('Profile → Notifications → Register');
        await expect(pc.getByText('Expired', { exact: true })).toBeVisible();

        // refused by the push service → the reason, inline
        const iphone = rows.filter({ hasText: 'Safari on iPhone' });
        await expect(iphone).toContainText('Safari / iPhone');
        await iphone.getByRole('button', { name: /Send test notification to/ }).click();
        const rejected = iphone.getByTestId('push-test-result');
        await expect(rejected).toHaveAttribute('data-status', 'rejected');
        await expect(rejected).toContainText('BadJwtToken');

        // remove the dead device
        page.once('dialog', (dialog) => dialog.accept());
        await pc.getByRole('button', { name: /^Remove / }).click();
        await expect(rows).toHaveCount(2);

        // after a reload the expired state is still explained from the stored error
        await page.reload();
        await expect(rows.filter({ hasText: 'Safari on iPhone' })).toContainText('BadJwtToken');

        // fits a phone
        await page.setViewportSize({ width: 390, height: 844 });
        await expect(rows.first()).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    });

    test('removing this device or losing its subscription offers to register it again', async ({ page }) => {
        const id = uid('push');
        await stubPush(page, `${id}-again`);
        await page.goto(urls.home); // (not /profile: the hash link below would not reload it)
        await removeAllDevices(page);

        await page.goto('/profile#notifications');
        const settings = page.getByTestId('push-settings');
        await settings.getByRole('button', { name: 'Turn on' }).click();
        const thisDevice = settings.getByTestId('push-device').filter({ has: page.getByText('This device', { exact: true }) });
        await expect(thisDevice).toHaveCount(1);

        // removing this device also unsubscribes this browser; permission stays → "isn't registered"
        page.once('dialog', (dialog) => dialog.accept());
        await thisDevice.getByRole('button', { name: /^Remove / }).click();
        await expect(settings.getByText('This device isn’t registered')).toBeVisible();
        await expect(settings.getByTestId('push-device')).toHaveCount(0);

        await settings.getByRole('button', { name: 'Register this device' }).click();
        await expect(settings.getByText('Browser notifications are on for this device')).toBeVisible();
        await expect(thisDevice).toHaveCount(1);

        // the server lost the row (e.g. removed from another device) while the browser kept its subscription
        await removeAllDevices(page);
        await page.reload();
        await expect(settings.getByText('This device isn’t registered')).toBeVisible();
        await expect(settings.getByText('the server has no subscription for this browser')).toBeVisible();
        await settings.getByRole('button', { name: 'Register this device' }).click();
        await expect(settings.getByText('Browser notifications are on for this device')).toBeVisible();
        await expect(thisDevice).toHaveCount(1);
    });
});
