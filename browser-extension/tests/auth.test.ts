import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { getAccessToken, NotSignedInError, signIn } from '@/lib/auth';
import { json, metadata, mockFetch, SERVER, storeSession } from './helpers';

beforeEach(() => {
    fakeBrowser.reset();
    vi.stubGlobal('navigator', { userAgent: 'Chrome/130' });
    vi.spyOn(fakeBrowser.identity, 'getRedirectURL').mockReturnValue('https://extension-id.chromiumapp.org/');
});

/** The browser's sign-in window: approves and redirects back with the request's state. */
function approveSignIn() {
    return vi.spyOn(fakeBrowser.identity, 'launchWebAuthFlow').mockImplementation(async ({ url }) => {
        const state = new URL(url).searchParams.get('state');
        return `${fakeBrowser.identity.getRedirectURL()}?state=${state}&code=code-1`;
    });
}

function server(origin: string, meta = metadata(origin)) {
    return {
        [`${origin}/.well-known/oauth-authorization-server`]: () => json(meta),
        [`${origin}/oauth/register`]: () => json({ client_id: 'client-1' }, 201),
        [`${origin}/oauth/token`]: () => json({ access_token: 'access-1', refresh_token: 'refresh-1', expires_in: 3600 }),
    };
}

describe('OAuth discovery stays on the chosen server (Codex CLI/extension review 4)', () => {
    const foreign: Record<string, Partial<ReturnType<typeof metadata>>> = {
        'another host': { token_endpoint: 'https://evil.example/oauth/token' },
        'another port': { token_endpoint: `${SERVER}:8443/oauth/token` },
        'userinfo': { token_endpoint: `https://user:pass@brainitive.example.com/oauth/token` },
        'https → http': { token_endpoint: 'http://brainitive.example.com/oauth/token' },
        'foreign authorize page': { authorization_endpoint: 'https://evil.example/oauth/authorize' },
        'foreign registration': { registration_endpoint: 'https://evil.example/oauth/register' },
    };

    for (const [name, override] of Object.entries(foreign)) {
        it(`refuses metadata with ${name}`, async () => {
            const calls = mockFetch({ ...server(SERVER, { ...metadata(), ...override }), 'https://evil.example/oauth/token': () => json({}) });
            const flow = approveSignIn();

            await expect(signIn(SERVER)).rejects.toThrow(/another address/);

            expect(calls.map((c) => c.url)).toEqual([`${SERVER}/.well-known/oauth-authorization-server`]);
            expect(flow).not.toHaveBeenCalled();
            expect(await fakeBrowser.storage.local.get('session')).toEqual({});
        });
    }

    it('refuses a remote server over plain http', async () => {
        const calls = mockFetch(server('http://brainitive.example.com'));
        await expect(signIn('http://brainitive.example.com')).rejects.toThrow(/https/);
        expect(calls).toEqual([]);
    });

    it('allows the loopback development flow', async () => {
        mockFetch(server('http://localhost:8000'));
        approveSignIn();
        const session = await signIn('http://localhost:8000');
        expect(session).toMatchObject({ server: 'http://localhost:8000', tokenEndpoint: 'http://localhost:8000/oauth/token', accessToken: 'access-1' });
    });

    it('never sends a stored refresh token to a token endpoint on another origin', async () => {
        await storeSession({ tokenEndpoint: 'https://evil.example/oauth/token', expiresAt: 0 });
        const calls = mockFetch({ 'https://evil.example/oauth/token': () => json({ access_token: 'x', expires_in: 60 }) });

        await expect(getAccessToken()).rejects.toBeInstanceOf(NotSignedInError);
        expect(calls).toEqual([]);
    });
});
