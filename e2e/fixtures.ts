import { test as base, expect, request, type BrowserContext, type Page } from '@playwright/test';
import { cacheBuildAssets } from './assets';
import { showCursor } from './cursor';
import { BASE_URL, PASSWORD, SEED, USERS, authFile, type Role } from './env';

/** Unique suffix so parallel tests never collide on names in the shared e2e database. */
export const uid = (prefix = 'e2e') => `${prefix}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

export const urls = {
    home: '/workspaces',
    workspace: (w: string = SEED.workspace) => `/workspaces/${w}`,
    project: (p: string = SEED.project, w: string = SEED.workspace) => `/workspaces/${w}/projects/${p}`,
    board: (b: string = SEED.board, p: string = SEED.project, w: string = SEED.workspace) => `/workspaces/${w}/projects/${p}/boards/${b}`,
};

/**
 * Sign a seeded user in over HTTP (no UI) and save the session to `file`. One session per worker:
 * Laravel's one-time flash data (validation errors, success toasts) belongs to a session, and a
 * session shared by parallel workers lets one test's request consume another test's flash.
 */
async function signIn(role: Role, file: string) {
    const api = await request.newContext({
        baseURL: BASE_URL,
        // explicitly empty: inside the runner a new request context inherits the project's storageState
        storageState: { cookies: [], origins: [] },
        httpCredentials: process.env.E2E_HTTP_USER ? { username: process.env.E2E_HTTP_USER, password: process.env.E2E_HTTP_PASSWORD ?? '' } : undefined,
    });
    expect((await api.get('/login', { maxRedirects: 0 })).status(), `${role}: the login page, not an existing session`).toBe(200);
    const xsrf = (await api.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await api.post('/login', {
        form: { email: USERS[role].email, password: PASSWORD },
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
        maxRedirects: 0,
    });
    expect(response.status(), `sign-in as ${role}`).toBe(302);
    expect(response.headers().location ?? '', `sign-in as ${role}`).not.toContain('/login');
    await api.storageState({ path: file });
    await api.dispose();
}

type WorkerFixtures = {
    /** This worker's own signed-in sessions (storageState files). */
    ownerState: string;
    memberState: string;
};

type Fixtures = {
    /** A second browser session, signed in as the seeded member (Max Member, project editor). */
    memberPage: Page;
    /** Auto: fails the test when the page threw an uncaught error (React crashes show up here). */
    pageErrors: void;
};

/**
 * Browsers with web push (Firefox, WebKit) show the "Turn on notifications?" prompt bottom right,
 * where it covers Save buttons: journeys run as someone who already answered it (PushPrompt.tsx).
 */
async function answeredPushPrompt(context: BrowserContext, browserName: string) {
    await context.addInitScript(() => {
        try {
            localStorage.setItem('push-prompt-dismissed', '1');
        } catch {
            /* storage blocked: the prompt may show */
        }
    });
    // Playwright's WebKit has no push service: PushManager.getSubscription() never settles and
    // freezes the page (Profile → Notifications looks up this browser's subscription). Real Safari
    // has one. Here push answers "not subscribed"; subscribing reports it isn't available.
    if (browserName === 'webkit') {
        await context.addInitScript(() => {
            if (typeof PushManager === 'undefined') return;
            PushManager.prototype.getSubscription = () => Promise.resolve(null);
            PushManager.prototype.subscribe = () => Promise.reject(new DOMException('No push service in this test browser', 'NotAllowedError'));
        });
    }
}

export const test = base.extend<Fixtures, WorkerFixtures>({
    ownerState: [async ({}, use, workerInfo) => {
        const file = authFile('owner', workerInfo.parallelIndex);
        await signIn('owner', file);
        await use(file);
    }, { scope: 'worker' }],
    memberState: [async ({}, use, workerInfo) => {
        const file = authFile('member', workerInfo.parallelIndex);
        await signIn('member', file);
        await use(file);
    }, { scope: 'worker' }],
    // every context a spec opens itself (guests, new accounts, a second person) gets the same
    // browser preparation as the default one — the push prompt answered, WebKit's push stubbed
    browser: [async ({ browser, browserName }, use) => {
        const newContext = browser.newContext.bind(browser);
        browser.newContext = async (options) => {
            const context = await newContext(options);
            await answeredPushPrompt(context, browserName);
            return context;
        };
        await use(browser);
    }, { scope: 'worker' }],
    // specs run as the owner, in this worker's session
    storageState: async ({ ownerState }, use) => use(ownerState),
    // every context serves the (immutable) built assets from memory — see assets.ts
    context: async ({ context, browserName }, use) => {
        await answeredPushPrompt(context, browserName);
        await cacheBuildAssets(context);
        if (process.env.E2E_SHOW_CURSOR) await showCursor(context);
        await use(context);
    },
    memberPage: async ({ browser, browserName, memberState, serviceWorkers }, use) => {
        const context = await browser.newContext({
            serviceWorkers,
            storageState: memberState,
            httpCredentials: process.env.E2E_HTTP_USER ? { username: process.env.E2E_HTTP_USER, password: process.env.E2E_HTTP_PASSWORD ?? '' } : undefined,
        });
        await answeredPushPrompt(context, browserName);
        await cacheBuildAssets(context);
        if (process.env.E2E_SHOW_CURSOR) await showCursor(context);
        const page = await context.newPage();
        await use(page);
        await context.close();
    },
    pageErrors: [
        async ({ page }, use) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => {
                // WebKit reports a same-origin request it cancelled (page reload / navigation) as
                // "… due to access control checks"; there is no CORS on our own origin
                if (/due to access control checks\.?$/.test(error.message) && error.message.includes(new URL(BASE_URL).host)) return;
                errors.push(error.message);
            });
            await use();
            expect(errors, 'uncaught errors in the page').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };
