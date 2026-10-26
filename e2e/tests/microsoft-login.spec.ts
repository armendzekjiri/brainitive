import { expect, test } from '../fixtures';
import { OUTLOOK_E2E, REMOTE } from '../env';
import { GUEST } from '../helpers/auth-account';

/**
 * Sign in with Microsoft (BRAI-148). The local e2e app has a fake Microsoft app registration
 * (e2e/env.ts, shared with the Outlook calendar), so the button is offered; Microsoft itself is
 * never called: the sign-in redirect is read without following it, and only refusing callbacks run.
 */
test.describe('sign in with Microsoft', () => {
    test.use({ storageState: GUEST });

    test('login and the homepage offer Microsoft; its sign-in carries state, nonce and PKCE; a foreign state is refused', async ({ page }) => {
        test.skip(REMOTE, 'needs the fake Microsoft app registration of the local e2e app');

        await page.goto('/');
        await expect(page.getByRole('link', { name: 'Continue with Microsoft' })).toBeVisible();

        await page.goto('/login');
        const button = page.getByRole('link', { name: 'Continue with Microsoft' });
        await expect(button).toBeVisible();
        await expect(button).toHaveAttribute('href', /\/login\/microsoft$/);

        // Start the flow (same session as the page) without following the redirect to Microsoft.
        const res = await page.request.get('/login/microsoft', { maxRedirects: 0 });
        expect(res.status()).toBe(302);
        const location = new URL(res.headers()['location']);
        expect(`${location.origin}${location.pathname}`).toBe('https://login.microsoftonline.com/common/oauth2/v2.0/authorize');
        const query = location.searchParams;
        expect(query.get('client_id')).toBe(OUTLOOK_E2E.clientId);
        expect(query.get('response_type')).toBe('code');
        expect(query.get('scope')).toBe('openid profile email User.Read');
        expect(query.get('code_challenge_method')).toBe('S256');
        expect(query.get('code_challenge')).toMatch(/^[A-Za-z0-9_-]{43}$/);
        expect(query.get('state')).toMatch(/^[A-Za-z0-9]{40}$/);
        expect(query.get('nonce')).toMatch(/^[A-Za-z0-9]{40}$/);
        expect(query.get('redirect_uri')).toMatch(/\/login\/microsoft\/callback$/);
        expect(location.href).not.toContain('e2e-outlook-secret');

        // A callback with another state is refused before any token request; still signed out.
        await page.goto(`/login/microsoft/callback?code=fake&state=x${query.get('state')}`);
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText('The Microsoft sign-in expired. Please try again.')).toBeVisible();

        // Cancelling on Microsoft's page comes back with a message too.
        await page.goto('/login/microsoft/callback?error=access_denied');
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText('You cancelled signing in with Microsoft.')).toBeVisible();

        await page.goto('/my-work');
        await expect(page).toHaveURL(/\/login$/);
    });
});
