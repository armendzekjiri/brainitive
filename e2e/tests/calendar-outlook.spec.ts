import { expect, test } from '../fixtures';
import { OUTLOOK_E2E, REMOTE } from '../env';

/**
 * Outlook / Microsoft 365 calendars (BRAI-140). The local e2e app has a fake Outlook app
 * registration (e2e/env.ts), so the provider is offered; Microsoft itself is never called:
 * the sign-in redirect is read without following it, and only the refusing callback paths run.
 */
test.describe('outlook calendar', () => {
    test('the connect menu offers Outlook and its sign-in carries state + PKCE', async ({ page }) => {
        test.skip(REMOTE, 'needs the fake Outlook app registration of the local e2e app');

        await page.goto('/calendar?view=month');
        await page.getByRole('button', { name: 'Add calendar' }).click();
        await expect(page.getByRole('menuitem', { name: 'Outlook / Microsoft 365' })).toBeVisible();
        await page.keyboard.press('Escape');

        // Start the flow (same session as the page) without following the redirect to Microsoft.
        const res = await page.request.get('/calendar/connections/outlook/redirect', { maxRedirects: 0 });
        expect(res.status()).toBe(302);
        const location = new URL(res.headers()['location']);
        expect(`${location.origin}${location.pathname}`).toBe('https://login.microsoftonline.com/common/oauth2/v2.0/authorize');
        const query = location.searchParams;
        expect(query.get('client_id')).toBe(OUTLOOK_E2E.clientId);
        expect(query.get('response_type')).toBe('code');
        expect(query.get('scope')).toBe('offline_access User.Read Calendars.ReadWrite');
        expect(query.get('code_challenge_method')).toBe('S256');
        expect(query.get('code_challenge')).toMatch(/^[A-Za-z0-9_-]{43}$/);
        expect(query.get('state')).toMatch(/^[A-Za-z0-9]{40}$/);
        expect(query.get('redirect_uri')).toMatch(/\/calendar\/connections\/outlook\/callback$/);
        expect(location.href).not.toContain('e2e-outlook-secret');

        // A callback with another state is refused before any token request.
        await page.goto(`/calendar/connections/outlook/callback?code=fake&state=x${query.get('state')}`);
        await expect(page).toHaveURL(/\/calendar(\?.*)?$/);
        await expect(page.getByText('The Microsoft sign-in expired. Try connecting again.')).toBeVisible();

        // Cancelling on Microsoft's page comes back with a message too.
        await page.goto('/calendar/connections/outlook/callback?error=access_denied');
        await expect(page.getByText('Connecting your Outlook calendar was cancelled.')).toBeVisible();
    });
});
