import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { getAccessToken, signOut } from '@/lib/auth';
import { deferred, json, mockFetch, SERVER, storeSession } from './helpers';

beforeEach(() => {
    fakeBrowser.reset();
});

const LOGOUT = `${SERVER}/api/ext/v1/logout`;
const local = (key: string) => fakeBrowser.storage.local.get(key).then((r) => r[key]);

/** Codex CLI/extension review 2: sign-out revokes the tokens on the server, then forgets them. */
describe('sign-out', () => {
    it('revokes the access + refresh token on the server', async () => {
        await storeSession();
        const calls = mockFetch({ [LOGOUT]: () => json({ message: 'Logged out.' }) });

        await expect(signOut()).resolves.toEqual({ revoked: true });

        expect(calls).toHaveLength(1);
        expect(calls[0]!.init.method).toBe('DELETE');
        expect(calls[0]!.init.redirect).toBe('error');
        expect(new Headers(calls[0]!.init.headers).get('Authorization')).toBe('Bearer access-1');
        expect(await local('session')).toBeUndefined();
        expect(await local('signOutNotice')).toBeUndefined();
    });

    for (const [name, route] of [
        ['offline', undefined],
        ['an older server without the endpoint', () => json({ message: 'Not Found' }, 404)],
    ] as const) {
        it(`still signs out locally when ${name}, and says so`, async () => {
            await storeSession();
            mockFetch(route ? { [LOGOUT]: route } : {});

            await expect(signOut()).resolves.toEqual({ revoked: false });

            expect(await local('session')).toBeUndefined();
            expect(await local('signOutNotice')).toMatch(/Connected apps/);
        });
    }

    it('revokes tokens a refresh received after the sign-out', async () => {
        await storeSession({ expiresAt: 0 });
        const answer = deferred<Response>();
        const calls = mockFetch({
            [`${SERVER}/oauth/token`]: () => answer.promise,
            [LOGOUT]: () => json({ message: 'Logged out.' }),
        });

        const pending = getAccessToken().catch(() => null);
        await vi.waitFor(() => expect(calls).toHaveLength(1));
        await signOut();
        answer.resolve(json({ access_token: 'access-2', refresh_token: 'refresh-2', expires_in: 3600 }));
        await pending;

        await vi.waitFor(() =>
            expect(calls.filter((c) => c.url === LOGOUT).map((c) => new Headers(c.init.headers).get('Authorization'))).toEqual([
                'Bearer access-1',
                'Bearer access-2',
            ]),
        );
    });
});
