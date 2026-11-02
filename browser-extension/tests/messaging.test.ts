import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import type { Browser } from 'wxt/browser';
import { createApi } from '@/lib/api';
import { getAccessToken } from '@/lib/auth';
import { createMessageHandler } from '@/lib/background-messages';
import type { Message } from '@/lib/messages';
import { getAccount, setSession } from '@/lib/storage';
import { json, mockFetch, SERVER, storeSession } from './helpers';

beforeEach(() => {
    fakeBrowser.reset();
});

const page = (path: string): Browser.runtime.MessageSender => ({ id: fakeBrowser.runtime.id, url: fakeBrowser.runtime.getURL(path as '/popup.html') });
const contentScript: Browser.runtime.MessageSender = {
    id: fakeBrowser.runtime.id,
    url: 'https://example.com/some/page',
    tab: { id: 7 } as Browser.tabs.Tab,
};
const otherExtension: Browser.runtime.MessageSender = { id: 'another-extension', url: 'chrome-extension://another-extension/popup.html' };

function handler() {
    return createMessageHandler({
        api: createApi(getAccessToken),
        signIn: vi.fn(async (server: string) => ({ server })),
        signOut: vi.fn(async () => ({ revoked: true })),
        captureArea: vi.fn(async () => undefined),
    } as never);
}

/**
 * Codex CLI/extension review 8: tokens stay in the background. Extension pages ask the
 * background to make API calls; nothing hands out a token, and only the extension's own pages
 * (not content scripts, not other extensions) may send privileged messages.
 */
describe('background messages', () => {
    it('never hand out a token', async () => {
        await storeSession();
        const reply = await handler()({ type: 'get-token' } as unknown as Message, page('/popup.html'));
        expect(JSON.stringify(reply)).not.toContain('access-1');
        expect(reply.ok).toBe(false);
    });

    it('run API calls in the background for extension pages', async () => {
        await storeSession();
        const calls = mockFetch({ [`${SERVER}/api/ext/v1/me`]: () => json({ user: { name: 'Ada' }, instance: { name: 'Acme' } }) });

        const reply = await handler()({ type: 'api', method: 'me', args: [] } as unknown as Message, page('/sidepanel.html'));

        expect(reply).toEqual({ ok: true, data: { user: { name: 'Ada' }, instance: { name: 'Acme' } } });
        expect(new Headers(calls[0]!.init.headers).get('Authorization')).toBe('Bearer access-1');
        expect(JSON.stringify(reply)).not.toContain('access-1');
    });

    it('pass API errors on with their status and field errors', async () => {
        await storeSession();
        mockFetch({ [`${SERVER}/api/ext/v1/captures`]: () => json({ message: 'Invalid.', errors: { text: ['Required.'] } }, 422) });
        const reply = await handler()({ type: 'api', method: 'saveToInbox', args: [{ text: '' }] } as unknown as Message, page('/popup.html'));
        expect(reply).toMatchObject({ ok: false, error: 'Required.', status: 422, errors: { text: ['Required.'] } });
    });

    it('refuse methods that are not API calls', async () => {
        const reply = await handler()({ type: 'api', method: 'constructor', args: [] } as unknown as Message, page('/popup.html'));
        expect(reply.ok).toBe(false);
    });

    for (const [name, sender] of [
        ['a content script', contentScript],
        ['another extension', otherExtension],
        ['a sender without a URL', { id: fakeBrowser.runtime.id }],
    ] as const) {
        it(`refuse privileged messages from ${name}`, async () => {
            await storeSession();
            const calls = mockFetch({ [`${SERVER}/api/ext/v1/me`]: () => json({}) });
            const h = handler();
            for (const message of [
                { type: 'api', method: 'me', args: [] },
                { type: 'sign-out' },
                { type: 'sign-in', server: SERVER },
                { type: 'get-token' },
            ]) {
                const reply = await h(message as unknown as Message, sender as Browser.runtime.MessageSender);
                expect(reply.ok, `${message.type} from ${name}`).toBe(false);
            }
            expect(calls).toEqual([]);
            expect((await fakeBrowser.storage.local.get('session')).session).toBeDefined();
        });
    }

    it('extension pages learn the server from `account`, which holds no token', async () => {
        await setSession({ ...(await storeSession()), accessToken: 'access-9' });
        const account = await getAccount();
        expect(account).toEqual({ server: SERVER });
        expect(JSON.stringify((await fakeBrowser.storage.local.get('account')).account)).not.toContain('access');
        await setSession(null);
        expect(await getAccount()).toBeNull();
    });
});
