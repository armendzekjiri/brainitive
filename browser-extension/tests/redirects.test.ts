import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { createApi } from '@/lib/api';
import { getAccessToken, signIn } from '@/lib/auth';
import { json, metadata, mockFetch, SERVER, storeSession } from './helpers';

beforeEach(() => {
    fakeBrowser.reset();
    vi.stubGlobal('navigator', { userAgent: 'Chrome/130' });
    vi.spyOn(fakeBrowser.identity, 'getRedirectURL').mockReturnValue('https://extension-id.chromiumapp.org/');
});

/**
 * Codex CLI/extension review 5: a 307/308 could replay a token request (code + verifier,
 * refresh token) or an API request's Authorization to another origin. Every request to the
 * server is made with `redirect: 'error'`, so the browser fails it instead of following.
 */
describe('requests to the server never follow redirects', () => {
    it('sign-in: discovery, registration and token exchange', async () => {
        const calls = mockFetch({
            [`${SERVER}/.well-known/oauth-authorization-server`]: () => json(metadata()),
            [`${SERVER}/oauth/register`]: () => json({ client_id: 'client-1' }, 201),
            [`${SERVER}/oauth/token`]: () => json({ access_token: 'a', refresh_token: 'r', expires_in: 3600 }),
        });
        vi.spyOn(fakeBrowser.identity, 'launchWebAuthFlow').mockImplementation(
            async ({ url }) => `https://extension-id.chromiumapp.org/?state=${new URL(url).searchParams.get('state')}&code=c`,
        );
        await signIn(SERVER);
        expect(calls).toHaveLength(3);
        for (const call of calls) expect(call.init.redirect, call.url).toBe('error');
    });

    it('refresh', async () => {
        await storeSession({ expiresAt: 0 });
        const calls = mockFetch({ [`${SERVER}/oauth/token`]: () => json({ access_token: 'a2', refresh_token: 'r2', expires_in: 3600 }) });
        await getAccessToken();
        expect(calls).toHaveLength(1);
        expect(calls[0]!.init.redirect).toBe('error');
    });

    it('API requests with the bearer token', async () => {
        const calls = mockFetch({ [`${SERVER}/api/ext/v1/me`]: () => json({ user: {}, instance: {} }) });
        const api = createApi(async () => ({ token: 't', server: SERVER }));
        await api.me();
        expect(calls[0]!.init.redirect).toBe('error');
        expect(new Headers(calls[0]!.init.headers).get('Authorization')).toBe('Bearer t');
    });
});
