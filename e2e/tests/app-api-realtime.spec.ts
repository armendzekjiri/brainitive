import type { Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { LIVE, REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { liveServerAvailable } from '../helpers/live';

/*
 * App API v1 realtime (v1.49.0, BRAI-426), as the desktop app's native notifications use it: connection info and a
 * channel token from the App API (only channels the user may open — someone else's user channel is denied), a WebSocket
 * to the realtime server global-setup started, and a live event when the inbox changes through the API. Signing the
 * device out closes its socket with 4410 ("Device signed out", fix/live-revoked-devices) — and since BRAI-377 its live
 * co-editing (document) sockets too.
 */

type Message = { type: string; channel?: string; event?: string; payload?: Record<string, unknown>; code?: string };

/** Open a socket in the page (a plain WebSocket, as a native client would), auth + subscribe, collect messages. */
async function connect(page: Page, url: string, token: string, channel: string): Promise<void> {
    await page.evaluate(([u, t, c]) => new Promise<void>((resolve, reject) => {
        const w = window as unknown as { __appLive: Message[]; __appLiveClosed: { code: number; reason: string } | null };
        w.__appLive = [];
        w.__appLiveClosed = null;
        const socket = new WebSocket(u);
        socket.onclose = (event) => { w.__appLiveClosed = { code: event.code, reason: event.reason }; };
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
const closed = (page: Page) => page.evaluate(() => (window as unknown as { __appLiveClosed: { code: number; reason: string } | null }).__appLiveClosed);

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

        // signing the device out closes its open socket at once (pushed by Brainitive, else the minute re-check)
        expect(await closed(page)).toBeNull();
        await page.request.post('/api/v1/auth/logout', { headers: auth });
        await expect.poll(() => closed(page), { timeout: 10_000 }).toEqual({ code: 4410, reason: 'Device signed out' });
    });

    test('signing the device out closes its live document socket too (4410)', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}`, 'X-E2E-Collab': '1', 'X-E2E-Collab-Port': String(LIVE.port) };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const doc = (await ok(await page.request.post(`/api/v1/projects/${projectId}/pages`, { headers: auth, data: { name: uid('Revoked device doc'), markdown: 'Text' } }), 201)).data;
        const session = (await ok(await page.request.post(`/api/v1/pages/${doc.uuid}/collab-token`, { headers: auth }))).data;

        // a native editor's Hocuspocus connection: [doc name][Auth = 2][Token = 0][token] (lib0 var-length encoding)
        await page.evaluate(([u, name, t]) => new Promise<void>((resolve, reject) => {
            const w = window as unknown as { __docClosed: { code: number; reason: string } | null };
            w.__docClosed = null;
            const bytes: number[] = [];
            const uint = (n: number) => { while (n > 127) { bytes.push((n & 127) | 128); n = Math.floor(n / 128); } bytes.push(n); };
            const text = (value: string) => { const b = new TextEncoder().encode(value); uint(b.length); bytes.push(...b); };
            const socket = new WebSocket(u);
            socket.binaryType = 'arraybuffer';
            socket.onopen = () => { text(name); uint(2); uint(0); text(t); socket.send(new Uint8Array(bytes)); };
            socket.onmessage = () => resolve(); // authenticated: the server answers (Authenticated, sync step 1)
            socket.onerror = () => reject(new Error('socket error'));
            socket.onclose = (event) => { w.__docClosed = { code: event.code, reason: event.reason }; resolve(); };
        }), [session.url, session.document, session.token] as const);
        const docClosed = () => page.evaluate(() => (window as unknown as { __docClosed: { code: number; reason: string } | null }).__docClosed);
        expect(await docClosed(), 'the realtime server accepted the device').toBeNull();

        await page.request.post('/api/v1/auth/logout', { headers: auth });
        await expect.poll(docClosed, { timeout: 10_000 }).toEqual({ code: 4410, reason: 'Device signed out' });
    });
});
