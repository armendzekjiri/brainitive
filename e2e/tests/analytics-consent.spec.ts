import type { Browser, BrowserContext, Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { REMOTE } from '../env';
import { createHelpdesk } from '../helpers/helpdesk';

/**
 * Visit statistics only with consent (BRAI-156 follow-up): the operator's Umami is not loaded until the
 * visitor clicks "Allow"; "No thanks", GPC / Do Not Track and customer pages mean no script and no
 * request; Inertia navigations are counted by hand while consented; withdrawing on /cookies stops it.
 *
 * The visitor's requests carry `X-E2E-Analytics: 1` (ANALYTICS_E2E_OPT_IN, e2e/env.ts) so the local app
 * treats them like brainitive.app. The stats host is intercepted: a fake tracker stands in for Umami
 * and nothing leaves the machine.
 */

const STATS = 'https://stats.novabrainhub.de';

/** Minimal Umami stand-in: same attributes, same `track(fn)` API, same before-send hook. */
const FAKE_TRACKER = `(() => {
    const script = document.currentScript;
    const filterName = script.getAttribute('data-before-send');
    window.umami = {
        track(arg) {
            let payload = { website: script.getAttribute('data-website-id'), hostname: location.hostname, url: location.pathname,
                referrer: document.referrer, title: document.title, language: navigator.language };
            if (typeof arg === 'function') payload = arg(payload);
            const filter = filterName && window[filterName];
            if (filter) { payload = filter('event', payload); if (!payload) return; }
            fetch('${STATS}/api/send', { method: 'POST', mode: 'no-cors', body: JSON.stringify({ type: 'event', payload }) });
        },
    };
})();`;

interface Visitor {
    context: BrowserContext;
    page: Page;
    /** every request to the stats host (script + sends) */
    stats: string[];
    /** payloads of page views sent to Umami */
    views: { url: string; title?: string; referrer?: string }[];
    close: () => Promise<void>;
}

async function visitor(browser: Browser, opts: { signal?: 'gpc' | 'dnt'; optIn?: boolean; colorScheme?: 'dark'; viewport?: { width: number; height: number } } = {}): Promise<Visitor> {
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
        extraHTTPHeaders: opts.optIn === false ? {} : { 'X-E2E-Analytics': '1' },
        colorScheme: opts.colorScheme,
        viewport: opts.viewport,
    });
    if (opts.signal === 'gpc') {
        await context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', { get: () => true }));
    }
    if (opts.signal === 'dnt') {
        await context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'doNotTrack', { get: () => '1' }));
    }
    const stats: string[] = [];
    const views: Visitor['views'] = [];
    await context.route(`${STATS}/**`, async (route) => {
        const request = route.request();
        stats.push(request.url());
        if (request.url().endsWith('/script.js')) {
            await route.fulfill({ status: 200, contentType: 'application/javascript', body: FAKE_TRACKER });
            return;
        }
        if (request.url().endsWith('/api/send')) views.push(JSON.parse(request.postData() ?? '{}').payload);
        await route.fulfill({ status: 200, body: '{}' });
    });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    return {
        context,
        page,
        stats,
        views,
        close: async () => {
            await context.close();
            expect(errors, 'uncaught errors in the visitor page').toEqual([]);
        },
    };
}

const notice = (page: Page) => page.getByTestId('analytics-consent');

test.describe('analytics consent', () => {
    test.skip(REMOTE, 'the X-E2E-Analytics opt-in exists only in the local e2e app (APP_ENV=e2e)');

    test('without a choice nothing loads; the notice asks with two equal answers', async ({ browser }) => {
        const v = await visitor(browser);
        await v.page.goto('/');
        const box = notice(v.page);
        await expect(box).toBeVisible();
        await expect(box).toContainText('May we count your visit');
        await expect(box.getByRole('link', { name: 'What we count' })).toHaveAttribute('href', '/privacy#analytics');
        expect(await v.page.evaluate(() => document.getElementById('brainitive-analytics'))).toBeNull();

        // no dark pattern: both buttons have the same size and look
        const allow = box.getByRole('button', { name: 'Allow' });
        const deny = box.getByRole('button', { name: 'No thanks' });
        const look = (b: typeof allow) =>
            b.evaluate((el) => {
                const s = getComputedStyle(el);
                return [s.backgroundColor, s.color, s.fontSize, s.fontWeight, s.borderColor, s.height, el.className].join('|');
            });
        expect(await look(allow)).toBe(await look(deny));
        const [a, d] = [await allow.boundingBox(), await deny.boundingBox()];
        expect(Math.abs(a!.width - d!.width)).toBeLessThanOrEqual(1);
        expect(a!.height).toBe(d!.height);

        // keyboard reachable, never blocks the page
        await allow.focus();
        await expect(allow).toBeFocused();
        await expect(v.page.getByRole('dialog')).toHaveCount(0);

        await v.page.waitForLoadState('networkidle');
        expect(v.stats, 'requests to the stats host before consent').toEqual([]);
        await v.close();
    });

    test('“No thanks” hides the notice, loads nothing, and is remembered', async ({ browser }) => {
        const v = await visitor(browser);
        await v.page.goto('/');
        await notice(v.page).getByRole('button', { name: 'No thanks' }).click();
        await expect(notice(v.page)).toHaveCount(0);
        const stored = await v.page.evaluate(() => JSON.parse(localStorage.getItem('brainitive:analytics-consent') ?? 'null'));
        expect(stored).toMatchObject({ choice: 'denied', version: 1 });

        await v.page.reload();
        await v.page.waitForLoadState('networkidle');
        await expect(notice(v.page)).toHaveCount(0);
        expect(v.stats).toEqual([]);
        await v.close();
    });

    test('“Allow” loads Umami without auto-track and counts Inertia navigations (path only)', async ({ browser }) => {
        const v = await visitor(browser);
        await v.page.goto('/privacy');
        await notice(v.page).getByRole('button', { name: 'Allow' }).click();
        await expect(notice(v.page)).toHaveCount(0);

        await expect.poll(() => v.views.map((x) => x.url)).toEqual(['/privacy']);
        expect(v.stats[0]).toBe(`${STATS}/script.js`);
        const script = v.page.locator('#brainitive-analytics');
        await expect(script).toHaveAttribute('data-auto-track', 'false');
        await expect(script).toHaveAttribute('data-do-not-track', 'true');
        await expect(script).toHaveAttribute('data-exclude-search', 'true');
        expect(v.views[0].title, 'no page titles').toBeUndefined();

        // client-side navigation: counted by hand, referrer = previous path
        await v.page.getByRole('link', { name: 'Back to home' }).click();
        await expect(v.page).toHaveURL(/\/$/);
        await expect.poll(() => v.views.map((x) => x.url)).toEqual(['/privacy', '/']);
        expect(v.views[1].referrer).toBe('/privacy');

        // a full reload keeps the choice: script again, one page view, no notice
        await v.page.goto('/terms?utm_source=x#top');
        await expect.poll(() => v.views.map((x) => x.url)).toEqual(['/privacy', '/', '/terms']);
        await expect(notice(v.page)).toHaveCount(0);
        await v.close();
    });

    test('withdrawing on /cookies stops counting at once and for later visits', async ({ browser }) => {
        const v = await visitor(browser);
        await v.page.goto('/cookies');
        const setting = v.page.getByTestId('analytics-consent-setting');
        await expect(setting.getByTestId('analytics-state')).toContainText('not allowed');
        await setting.getByRole('button', { name: 'Allow', exact: true }).click();
        await expect(setting.getByTestId('analytics-state')).toHaveText('allowed');
        await expect(notice(v.page)).toHaveCount(0);
        await expect.poll(() => v.views.map((x) => x.url)).toEqual(['/cookies']);

        await setting.getByRole('button', { name: 'Don’t allow' }).click();
        await expect(setting.getByTestId('analytics-state')).toHaveText('not allowed');
        await expect(setting.getByRole('button', { name: 'Don’t allow' })).toHaveAttribute('aria-pressed', 'true');
        await expect(v.page.locator('#brainitive-analytics')).toHaveCount(0);
        expect(await v.page.evaluate(() => 'umami' in window)).toBe(false);

        // SPA navigation after withdrawal: nothing sent
        await v.page.getByRole('link', { name: 'Back to home' }).click();
        await expect(v.page).toHaveURL(/\/$/);
        await v.page.waitForLoadState('networkidle');
        // and a new page load: no script either
        const before = v.stats.length;
        await v.page.goto('/privacy');
        await v.page.waitForLoadState('networkidle');
        expect(v.stats.length).toBe(before);
        expect(v.views.map((x) => x.url)).toEqual(['/cookies']);
        await expect(notice(v.page)).toHaveCount(0);
        await v.close();
    });

    for (const signal of ['gpc', 'dnt'] as const) {
        test(`a ${signal === 'gpc' ? 'Global Privacy Control' : 'Do Not Track'} signal means no without asking`, async ({ browser }) => {
            const v = await visitor(browser, { signal });
            await v.page.goto('/');
            await v.page.waitForLoadState('networkidle');
            await expect(notice(v.page)).toHaveCount(0);
            await v.page.goto('/cookies');
            await expect(v.page.getByTestId('analytics-state')).toContainText('privacy signal');
            await expect(v.page.getByTestId('analytics-consent-setting').getByRole('button')).toHaveCount(0);
            await v.page.waitForLoadState('networkidle');
            expect(v.stats).toEqual([]);
            await v.close();
        });
    }

    test('the notice works at phone width in dark mode', async ({ browser }) => {
        const v = await visitor(browser, { colorScheme: 'dark', viewport: { width: 375, height: 740 } });
        await v.page.goto('/privacy');
        const box = notice(v.page);
        await expect(box).toBeVisible();
        await expect(v.page.locator('html')).toHaveClass(/dark/);
        const rect = await box.boundingBox();
        expect(rect!.x).toBeGreaterThanOrEqual(0);
        expect(rect!.x + rect!.width).toBeLessThanOrEqual(375);
        expect(await v.page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
        await v.close();
    });

    test('a customer’s support page never asks and never loads analytics', async ({ page, browser }) => {
        const hd = await createHelpdesk(page, uid('Consent desk'), { template: 'contact-form' });
        const address = uid('consent').toLowerCase();
        await page.goto(`${hd.url}/edit`);
        await page.getByRole('tab', { name: 'Embed' }).click();
        await page.getByLabel('Address').fill(address);
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.locator('code', { hasText: `/support/${address}` })).toBeVisible();

        const v = await visitor(browser);
        // even a visitor who allowed statistics on brainitive.app
        await v.page.goto('/terms');
        await notice(v.page).getByRole('button', { name: 'Allow' }).click();
        await expect.poll(() => v.views.length).toBe(1);
        const before = v.stats.length;

        await v.page.goto(`/support/${address}`);
        await expect(v.page.getByTestId('operator-notice')).toBeVisible();
        await v.page.waitForLoadState('networkidle');
        await expect(notice(v.page)).toHaveCount(0);
        expect(await v.page.evaluate(() => 'brainitiveAnalytics' in window)).toBe(false);
        expect(v.stats.length, 'no request to the stats host on a customer page').toBe(before);

        // and a fresh visitor there is not asked either
        const fresh = await visitor(browser);
        await fresh.page.goto(`/support/${address}`);
        await expect(fresh.page.getByTestId('operator-notice')).toBeVisible();
        await fresh.page.waitForLoadState('networkidle');
        await expect(notice(fresh.page)).toHaveCount(0);
        expect(fresh.stats).toEqual([]);
        await fresh.close();
        await v.close();
    });

    test('without the analytics host (self-hosted, dev, other journeys) there is no notice', async ({ browser }) => {
        const v = await visitor(browser, { optIn: false });
        await v.page.goto('/');
        await v.page.waitForLoadState('networkidle');
        await expect(notice(v.page)).toHaveCount(0);
        expect(await v.page.evaluate(() => 'brainitiveAnalytics' in window)).toBe(false);
        expect(v.stats).toEqual([]);
        await v.close();
    });
});
