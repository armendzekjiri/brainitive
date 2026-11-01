import type { APIRequestContext } from '@playwright/test';
import { expect, test } from '../fixtures';
import { USERS } from '../env';

/*
 * Device-code sign-in for the TV app (v1.49.0, BRAI-438, RFC 8628): the TV asks for a code
 * (`request`), the signed-in user confirms it on /app/device (`page`), the TV polls for its tokens.
 * Opening the TV's QR link shows the code but never approves without the click.
 */

const TV = { name: 'E2E Living room TV', platform: 'android_tv', app_version: '0.1.0' };

async function startOnTv(request: APIRequestContext) {
    const response = await request.post('/api/v1/auth/device', { data: { client: 'tv', device: TV } });
    expect(response.status()).toBe(200);
    const flow = await response.json();
    expect(flow.user_code).toMatch(/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/);
    expect(flow.verification_uri).toMatch(/\/app\/device$/);
    return flow as { device_code: string; user_code: string; verification_uri: string; verification_uri_complete: string; interval: number };
}

const poll = (request: APIRequestContext, deviceCode: string) => request.post('/api/v1/auth/device/token', { data: { device_code: deviceCode } });

test('the QR link shows the code and Approve signs the TV in', async ({ page, request }) => {
    const flow = await startOnTv(request);

    const pending = await poll(request, flow.device_code);
    expect(pending.status()).toBe(400);
    expect((await pending.json()).code).toBe('authorization_pending');

    await page.goto(flow.verification_uri_complete);
    await expect(page.getByRole('heading', { name: `Sign in to Brainitive TV as ${USERS.owner.email}?` })).toBeVisible();
    await expect(page.getByTestId('device-code')).toHaveText(flow.user_code);
    await expect(page.getByText(TV.name)).toBeVisible();
    await expect(page.getByText('Android TV')).toBeVisible();

    // opening the link alone approved nothing
    await page.waitForTimeout(flow.interval * 1000);
    expect((await (await poll(request, flow.device_code)).json()).code).toBe('authorization_pending');

    await page.getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByRole('status')).toContainText(`${TV.name} is now signed in as ${USERS.owner.email}`);

    await page.waitForTimeout(flow.interval * 1000);
    const token = await poll(request, flow.device_code);
    expect(token.status()).toBe(200);
    const tokens = await token.json();
    expect(tokens.access_token).toMatch(/^bra_/);
    expect(tokens.device).toMatchObject({ name: TV.name, platform: 'android_tv' });

    const me = await request.get('/api/v1/me', { headers: { Authorization: `Bearer ${tokens.access_token}` } });
    expect(me.status()).toBe(200);
    expect((await me.json()).data.device.client).toBe('tv');

    // the TV shows up with the other apps on the profile page
    await page.goto('/profile#app-devices');
    await expect(page.getByTestId('app-device').filter({ hasText: TV.name }).first()).toContainText('Brainitive TV');

    const logout = await request.post('/api/v1/auth/logout', { headers: { Authorization: `Bearer ${tokens.access_token}` } });
    expect(logout.status()).toBe(204);
});

test('a typed code can be denied, and a wrong code says so', async ({ page, request }) => {
    const flow = await startOnTv(request);

    await page.goto('/app/device');
    await expect(page.getByRole('heading', { name: 'Connect a device' })).toBeVisible();

    await page.getByLabel('Code').fill('BBBB-BBBB');
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('alert')).toContainText('This code is not valid');

    await page.getByLabel('Code').fill(flow.user_code.toLowerCase());
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByTestId('device-code')).toHaveText(flow.user_code);
    await page.getByRole('button', { name: 'Deny' }).click();
    await expect(page.getByRole('heading', { name: 'Sign-in denied' })).toBeVisible();

    const denied = await poll(request, flow.device_code);
    expect(denied.status()).toBe(400);
    expect((await denied.json()).code).toBe('access_denied');
});
