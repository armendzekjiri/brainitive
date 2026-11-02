import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { getAccessToken, NotSignedInError, signIn, signOut } from '@/lib/auth';
import { deferred, json, metadata, mockFetch, SERVER, storeSession } from './helpers';

beforeEach(() => {
    fakeBrowser.reset();
    vi.stubGlobal('navigator', { userAgent: 'Chrome/130' });
    vi.spyOn(fakeBrowser.identity, 'getRedirectURL').mockReturnValue('https://extension-id.chromiumapp.org/');
    vi.spyOn(fakeBrowser.identity, 'launchWebAuthFlow').mockImplementation(
        async ({ url }) => `https://extension-id.chromiumapp.org/?state=${new URL(url).searchParams.get('state')}&code=code-B`,
    );
});

const stored = async () => (await fakeBrowser.storage.local.get('session')).session as { accessToken: string } | undefined;

/**
 * A server whose refresh answer is held open by the test; a sign-in (authorization_code)
 * answers at once with account B's tokens.
 */
function serverWithSlowRefresh(refreshAnswer: Promise<Response>) {
    return mockFetch({
        [`${SERVER}/.well-known/oauth-authorization-server`]: () => json(metadata()),
        [`${SERVER}/oauth/register`]: () => json({ client_id: 'client-1' }, 201),
        [`${SERVER}/oauth/token`]: (init) =>
            new URLSearchParams(String(init.body)).get('grant_type') === 'refresh_token'
                ? refreshAnswer
                : json({ access_token: 'access-B', refresh_token: 'refresh-B', expires_in: 3600 }),
        [`${SERVER}/api/ext/v1/logout`]: () => json({ message: 'Logged out.' }),
    });
}

/** Codex CLI/extension review 3: an in-flight refresh must not undo a sign-out or a new sign-in. */
describe('a refresh that finishes late', () => {
    it('does not sign a signed-out user back in', async () => {
        await storeSession({ expiresAt: 0 });
        const answer = deferred<Response>();
        serverWithSlowRefresh(answer.promise);

        const pending = getAccessToken();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await signOut();
        answer.resolve(json({ access_token: 'access-2', refresh_token: 'refresh-2', expires_in: 3600 }));

        await expect(pending).rejects.toBeInstanceOf(NotSignedInError);
        expect(await stored()).toBeUndefined();
    });

    it('does not overwrite a session signed in meanwhile', async () => {
        await storeSession({ expiresAt: 0 });
        const answer = deferred<Response>();
        serverWithSlowRefresh(answer.promise);

        const pending = getAccessToken();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await signIn(SERVER);
        answer.resolve(json({ access_token: 'access-2', refresh_token: 'refresh-2', expires_in: 3600 }));

        // The caller gets the current session's token, never the old account's fresh one.
        await expect(pending).resolves.toMatchObject({ token: 'access-B' });
        expect(await stored()).toMatchObject({ accessToken: 'access-B' });
    });

    it('does not delete a session signed in meanwhile when it fails', async () => {
        await storeSession({ expiresAt: 0 });
        const answer = deferred<Response>();
        serverWithSlowRefresh(answer.promise);

        const pending = getAccessToken();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalled());
        await signIn(SERVER);
        answer.resolve(json({ error: 'invalid_grant' }, 400));

        await expect(pending).rejects.toThrow();
        expect(await stored()).toMatchObject({ accessToken: 'access-B' });
    });
});
