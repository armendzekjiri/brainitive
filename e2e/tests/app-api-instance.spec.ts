import { expect, test } from '../fixtures';

/**
 * App API v1 (BRAI-354 / BRAI-384), as the desktop and mobile apps call it: no cookies, no CSRF,
 * JSON only. The first-run screen reads `/api/v1/instance` before anyone signs in; account
 * routes need an app token and answer 401 in the API's error shape without one.
 */
test.describe('app api: instance and account', () => {
    test('instance info is public and has the documented shape', async ({ request }) => {
        const res = await request.get('/api/v1/instance');
        expect(res.status(), await res.text()).toBe(200);
        expect(res.headers()['cache-control']).toContain('max-age=300');

        const { data } = await res.json();
        expect(Object.keys(data).sort()).toEqual([
            'api_version', 'authorize_path', 'email_verification_required', 'min_versions', 'name',
            'sign_in', 'sign_up_open', 'url', 'version',
        ]);
        expect(data.api_version).toBe('1');
        expect(data.authorize_path).toBe('/app/authorize');
        expect(data.version).toMatch(/^\d+\.\d+\.\d+$/);
        expect(data.sign_in.password).toBe(true);
        for (const provider of ['google', 'github', 'microsoft']) {
            expect(typeof data.sign_in[provider]).toBe('boolean');
        }
        expect(data.min_versions.desktop).toMatch(/^\d+\.\d+\.\d+$/);
        expect(data.min_versions.mobile).toMatch(/^\d+\.\d+\.\d+$/);
    });

    test('account routes need an app token: 401 in the error shape', async ({ request }) => {
        const me = await request.get('/api/v1/me');
        expect(me.status()).toBe(401);
        expect(await me.json()).toEqual({ message: 'Unauthenticated.', code: 'unauthenticated' });

        // a made-up token is no better than none
        const forged = await request.patch('/api/v1/me', {
            headers: { Authorization: 'Bearer bra_not-a-real-token' },
            data: { name: 'Nope' },
        });
        expect(forged.status()).toBe(401);
        expect((await forged.json()).code).toBe('unauthenticated');
    });
});
