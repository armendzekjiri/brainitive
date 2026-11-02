import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures';
import { REMOTE } from '../env';
import { ok, seedAppToken } from '../helpers/app-api';
import { liveServerAvailable } from '../helpers/live';

/*
 * App API v1 realtime (v1.49.0, BRAI-426), as the desktop app's native notifications use it: connection info and a
 * channel token from the App API (only channels the user may open — someone else's user channel is denied), a WebSocket
 * to the realtime server global-setup started, and a live event when the inbox changes through the API.
 */

type Message = { type: string; channel?: string; event?: string; payload?: Record<string, unknown>; code?: string };

/** Open a socket in the page (a plain WebSocket, as a native client would), auth + subscribe, collect messages. */
async function connect(page: Page, url: string, token: string, channel: string): Promise<void> {
    await page.evaluate(([u, t, c]) => new Promise<void>((resolve, reject) => {
        const w = window as unknown as { __appLive: Message[] };
        w.__appLive = [];
        const socket = new WebSocket(u);
        socket.onopen = () => socket.send(JSON.stringify({ type: 'auth', token: t }));
        socket.onerror = () => reject(new Error('socket error'));
        socket.onmessage = (message) => {
            const data = JSON.parse(String(message.data)) as Message;
            w.__appLive.push(data);
            if (data.type === 'ready') socket.send(JSON.stringify({ type: 'subscribe', channel: c }));
            if (data.type === 'subscribed') resolve();
        };
    }), [url, token, channel] as const);
}

const messages = (page: Page) => page.evaluate(() => (window as unknown as { __appLive: Message[] }).__appLive);

test.describe('app api: realtime', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route and the realtime server is local');
    test.skip(!liveServerAvailable(), 'run `npm --prefix realtime ci` first');

    test('an app token joins its own live channel and hears the inbox change', async ({ page }) => {
        const token = await seedAppToken(page);
        // e2e: live channels are offered only with this header (the realtime server global-setup started)
        const auth = { Authorization: `Bearer ${token}`, 'X-E2E-Live': '1' };
        const me = (await ok(await page.request.get('/api/v1/me', { headers: auth }))).data.id as number;

        const info = await ok(await page.request.get('/api/v1/realtime', { headers: auth }));
        expect(info.data).toMatchObject({ enabled: true, token_path: '/api/v1/realtime/token', max_channels: 50 });
        expect(info.data.url).toMatch(/^wss?:\/\//);

        const granted = await ok(await page.request.post('/api/v1/realtime/token', { headers: auth, data: { channels: [`user.${me}`, `user.${me + 1}`] } }));
        expect(granted.data.channels).toEqual([`user.${me}`]);
        expect(granted.data.denied).toEqual([`user.${me + 1}`]);

        await connect(page, granted.data.url, granted.data.token, `user.${me}`);

        // a write through the API → the user's channel says so (ids / counts only)
        await ok(await page.request.post('/api/v1/notifications/read-all', { headers: auth }));
        await expect.poll(async () => (await messages(page)).find((m) => m.type === 'event' && m.event === 'notifications.changed'), { timeout: 10_000 })
            .toMatchObject({ channel: `user.${me}`, payload: { unread_count: 0 } });

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
