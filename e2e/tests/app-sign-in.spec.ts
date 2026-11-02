import { createHash, randomBytes } from 'node:crypto';
import type { Browser, BrowserContext, Page } from '@playwright/test';
import { cacheBuildAssets } from '../assets';
import { expect, test } from '../fixtures';
import { PASSWORD, REMOTE, USERS } from '../env';
import { seedAppToken } from '../helpers/app-api';
import { GUEST, guestContext } from '../helpers/auth-account';

/*
 * Native sign-in for the desktop app (v1.49.0, BRAI-355 / BRAI-383), server side: the system
 * browser's consent page, the code exchange (PKCE), the webview session link.
 * The browser can't follow brainitive:// here, so the decision's answer is intercepted
 * (Inertia::location → 409 + X-Inertia-Location) and the app's part runs through `request`.
 * The webview link asks before it signs a plain browser in or replaces another account (BRAI-377).
 */

const base64url = (buffer: Buffer) => buffer.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

function pkce() {
    const verifier = base64url(randomBytes(48));
    return { verifier, challenge: base64url(createHash('sha256').update(verifier).digest()) };
}

/** A browser context whose user agent says it is the desktop app's webview (no session). */
async function desktopWebview(browser: Browser): Promise<BrowserContext> {
    const context = await browser.newContext({ storageState: GUEST, userAgent: `${DEFAULT_UA} BrainitiveDesktop/0.4.2` });
    await cacheBuildAssets(context);
    return context;
}

const DEFAULT_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

/** A device + its one-time webview link (`POST /api/v1/auth/web-session`), for the signed-in user of `page`. */
async function webSessionLink(page: Page, to = ''): Promise<{ link: string; token: string }> {
    const token = await seedAppToken(page);
    const res = await page.request.post('/api/v1/auth/web-session', { headers: { Authorization: `Bearer ${token}` } });
    expect(res.status()).toBe(200);
    return { link: `${(await res.json()).data.url}${to}`, token };
}

function authorizePath(challenge: string, state: string): string {
    const query = new URLSearchParams({
        client: 'desktop',
        code_challenge: challenge,
        code_challenge_method: 'S256',
        state,
        device_name: 'E2E MacBook',
    });
    return `/app/authorize?${query}`;
}

/**
 * Where the decision sends the browser: the app's custom-scheme callback. The answer is
 * intercepted and handed to the page unchanged; the URL resolves once it arrived.
 */
async function captureCallback(page: Page): Promise<() => Promise<URL>> {
    let resolve: (location: string | null) => void = () => {};
    const captured = new Promise<string | null>((r) => (resolve = r));
    await page.route('**/app/authorize', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        const response = await route.fetch({ maxRedirects: 0 });
        resolve(response.headers()['x-inertia-location'] ?? response.headers()['location'] ?? null);
        await route.fulfill({ response });
    });
    return async () => {
        const location = await captured;
        expect(location, 'the decision redirects to the app').not.toBeNull();
        return new URL(location as string);
    };
}

test('Continue hands the app a code it exchanges for tokens, and the webview link signs a fresh browser in', async ({ page, request, browser }) => {
    const { verifier, challenge } = pkce();
    const state = `st-${Date.now()}`;
    const callback = await captureCallback(page);

    await page.goto(authorizePath(challenge, state));
    await expect(page.getByRole('heading', { name: `Sign in to Brainitive Desktop as ${USERS.owner.email}?` })).toBeVisible();
    await expect(page.getByText('E2E MacBook')).toBeVisible();
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('status')).toContainText('Opening Brainitive Desktop');

    const url = await callback();
    expect(`${url.protocol}//${url.host}${url.pathname}`).toBe('brainitive://auth/callback');
    expect(url.searchParams.get('state')).toBe(state);
    const code = url.searchParams.get('code');
    expect(code).toMatch(/^[A-Za-z0-9]{64}$/);

    // the app: exchange the code with its verifier
    const exchange = await request.post('/api/v1/auth/exchange', {
        data: { code, code_verifier: verifier, device: { name: 'E2E MacBook', platform: 'macos', app_version: '0.1.0' } },
    });
    expect(exchange.status()).toBe(200);
    const tokens = await exchange.json();
    expect(tokens.token_type).toBe('Bearer');
    expect(tokens.access_token).toMatch(/^bra_/);
    expect(tokens.device).toMatchObject({ name: 'E2E MacBook', platform: 'macos' });

    // the code works once
    const again = await request.post('/api/v1/auth/exchange', {
        data: { code, code_verifier: verifier, device: { name: 'E2E MacBook', platform: 'macos', app_version: '0.1.0' } },
    });
    expect(again.status()).toBe(400);
    expect((await again.json()).code).toBe('invalid_grant');

    // the webview: a one-time link that signs a browser without any session in
    const webSession = await request.post('/api/v1/auth/web-session', { headers: { Authorization: `Bearer ${tokens.access_token}` } });
    expect(webSession.status()).toBe(200);
    const link: string = (await webSession.json()).data.url;
    expect(link).toMatch(/\/app\/session\/[A-Za-z0-9]{64}$/);

    // the desktop app's webview (its user agent ends in ` BrainitiveDesktop/<version>`) with no session: one step
    const webview = await desktopWebview(browser);
    const webviewPage = await webview.newPage();
    await webviewPage.goto(link);
    // signed in: the dashboard (which forwards to the workspaces), not the login page
    await expect(webviewPage).toHaveURL(/\/(dashboard|workspaces)$/);
    expect((await webview.cookies()).some((cookie) => cookie.name.startsWith('remember_web_'))).toBe(true);

    // used once: opening it again in another browser lands on the login page
    const other = await guestContext(browser);
    const otherPage = await other.newPage();
    await otherPage.goto(link);
    await expect(otherPage).toHaveURL(/\/login$/);
    await expect(otherPage.getByText('This sign-in link has expired or was already used.')).toBeVisible();

    // sign the test device out again
    const logout = await request.post('/api/v1/auth/logout', { headers: { Authorization: `Bearer ${tokens.access_token}` } });
    expect(logout.status()).toBe(204);

    await webview.close();
    await other.close();
});

test('Cancel tells the app the sign-in was denied', async ({ page }) => {
    const { challenge } = pkce();
    const state = `st-${Date.now()}`;
    const callback = await captureCallback(page);

    await page.goto(authorizePath(challenge, state));
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('status')).toContainText('Sign-in cancelled');

    const url = await callback();
    expect(url.searchParams.get('error')).toBe('access_denied');
    expect(url.searchParams.get('state')).toBe(state);
    expect(url.searchParams.has('code')).toBe(false);
});

test('a guest signs in first and comes back to the consent page; a broken link never reaches the app', async ({ browser }) => {
    const { challenge } = pkce();
    const context = await guestContext(browser);
    const page = await context.newPage();

    await page.goto(authorizePath(challenge, 'from-guest'));
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Email', { exact: true }).fill(USERS.member.email);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/app\/authorize\?/);
    await expect(page.getByRole('heading', { name: `Sign in to Brainitive Desktop as ${USERS.member.email}?` })).toBeVisible();

    const broken = await page.goto(`/app/authorize?client=desktop&code_challenge=${challenge}&code_challenge_method=plain&state=x`);
    expect(broken?.status()).toBe(400);
    await expect(page.getByRole('heading', { name: 'This sign-in link is not valid' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue' })).toHaveCount(0);

    await context.close();
});

test('a webview link opened in a plain browser asks first; Continue signs in', async ({ page, browser }) => {
    test.skip(REMOTE, 'the device seed is an e2e-only route');
    const { link } = await webSessionLink(page, '?to=/my-work');

    const context = await guestContext(browser);
    const other = await context.newPage();
    await other.goto(link);
    await expect(other.getByRole('heading', { name: `Sign in as ${USERS.owner.email} on this browser?` })).toBeVisible();
    // nothing happened yet
    expect((await context.cookies()).some((cookie) => cookie.name.startsWith('remember_web_'))).toBe(false);

    await other.getByRole('button', { name: 'Continue' }).click();
    await expect(other).toHaveURL(/\/my-work/);
    expect((await context.cookies()).some((cookie) => cookie.name.startsWith('remember_web_'))).toBe(true);
    await context.close();
});

test('a webview link never swaps the signed-in account silently', async ({ page, memberPage }) => {
    test.skip(REMOTE, 'the device seed is an e2e-only route');
    // the owner's link, opened where the member is signed in
    const { link } = await webSessionLink(page);

    await memberPage.goto(link);
    await expect(memberPage.getByTestId('current-account')).toContainText(`You’re signed in as ${USERS.member.email}.`);
    await expect(memberPage.getByRole('heading', { name: `Continue as ${USERS.owner.email}?` })).toBeVisible();

    // Cancel: still the member
    await memberPage.getByRole('button', { name: 'Cancel' }).click();
    await expect(memberPage).toHaveURL(/\/(dashboard|workspaces)/);
    await memberPage.goto('/profile');
    await expect(memberPage.getByLabel('Email', { exact: true })).toHaveValue(USERS.member.email);

    // the link was spent on the first open (expired → the login page, which sends a signed-in user on)
    await memberPage.goto(link);
    await expect(memberPage).toHaveURL(/\/(dashboard|workspaces|login)/);
    await expect(memberPage.getByRole('heading', { name: `Continue as ${USERS.owner.email}?` })).toHaveCount(0);
});
