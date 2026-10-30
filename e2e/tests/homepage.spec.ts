import type { Browser, Page } from '@playwright/test';
import { expect, test } from '../fixtures';

/*
 * The public homepage (Pages/Welcome.tsx) shows what the latest releases shipped (BRAI-98).
 * When a release adds feature cards to the homepage, add their titles here.
 */
const SPOTLIGHT = ['Brainitive in your terminal', 'Emails that look like Brainitive', 'Privacy by default'];
const NEW_FEATURES = [
    'Command line (CLI)',
    'AI agents (MCP)',
    'Project templates',
    'Saved board views',
    'Custom fields',
    'Releases & release notes',
    'Quick capture',
    'My Day & focus timer',
    'Command palette',
    'Related content & backlinks',
    'Share links',
    'Helpdesk templates',
    'Lead forms',
    'GitHub development',
    'Project health',
    'Workload planning',
    'Meeting notes → issues',
    'Time tracking',
    'Team time overview',
    'Client billing',
    'Bulk edit',
    'Your sidebar, your way',
    'Admin insights',
    'Notifications & push',
    'Outgoing webhooks',
    'Email to helpdesk',
    'Helpdesk SLAs',
    'Recurring issues',
    'Workflow automation',
    'Import from Trello, Jira & Notion',
    'Project export & restore',
    'Calendar sync',
    'Calendar feed',
    'Smart links',
    'Document review',
    'Meetings: Google Meet & Teams',
    'Client portal',
    'Feedback portal',
    'Social login',
    'Find anything',
    'Offline mode',
    'Live co-editing',
];
const GROUPS = ['Plan & organize', 'Capture & focus', 'Create & write', 'Collaborate & share', 'Customers, leads & billing', 'Insight & planning', 'Connect & automate'];

/** A signed-out visitor, collecting uncaught page errors. */
async function guestPage(browser: Browser, options: { colorScheme?: 'light' | 'dark'; viewport?: { width: number; height: number } } = {}) {
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
        httpCredentials: process.env.E2E_HTTP_USER ? { username: process.env.E2E_HTTP_USER, password: process.env.E2E_HTTP_PASSWORD ?? '' } : undefined,
        ...options,
    });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    return { context, page, errors };
}

async function expectNoHorizontalScroll(page: Page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, 'horizontal scroll on the homepage').toBeLessThanOrEqual(0);
}

test.describe('homepage', () => {
    test('a guest sees the features of the latest releases', async ({ browser }) => {
        const { context, page, errors } = await guestPage(browser);
        await page.goto('/');
        await expect(page.getByRole('link', { name: 'Start for free' })).toBeVisible();

        const features = page.locator('#features');
        await features.scrollIntoViewIfNeeded();
        await expect(features.getByRole('heading', { level: 2, name: 'Everything you need, in one place' })).toBeVisible();

        const spotlight = features.getByRole('region', { name: 'New in recent releases' });
        for (const title of SPOTLIGHT) {
            await expect(spotlight.getByRole('heading', { level: 4, name: title, exact: true })).toBeVisible();
        }

        for (const group of GROUPS) {
            await expect(features.getByRole('heading', { level: 3, name: group, exact: true })).toBeVisible();
        }
        for (const title of NEW_FEATURES) {
            // the card outside the spotlight, with its "New" pill
            const card = features.locator('div.group\\/item').filter({ has: page.getByRole('heading', { level: 4, name: title, exact: true }) });
            await expect(card).toHaveCount(1);
            await expect(card).toBeVisible();
            await expect(card.getByText('New', { exact: true })).toBeVisible();
        }
        // the MCP card names the connector's newer tools (v1.47.0: project setup from an export)
        await expect(features.getByText(/let it set up the whole project/)).toBeVisible();
        // the CLI section shows the one-line install
        await expect(page.locator('#cli').getByText('curl -fsSL https://brainitive.app/install.sh | sh')).toBeVisible();

        expect(errors, 'uncaught errors on the homepage').toEqual([]);
        await context.close();
    });

    test('a pasted link gets a rich preview (Open Graph + X card)', async ({ browser }) => {
        const { context, page } = await guestPage(browser);
        // crawlers read the raw HTML without running JavaScript
        const response = await page.request.get('/');
        expect(response.ok()).toBeTruthy();
        const html = await response.text();
        const tag = (attr: 'property' | 'name', key: string) => html.match(new RegExp(`<meta ${attr}="${key}" content="([^"]*)">`))?.[1];

        expect(tag('property', 'og:title')).toContain('Brainitive');
        expect(tag('property', 'og:description')?.length ?? 0).toBeGreaterThan(60);
        expect(tag('property', 'og:type')).toBe('website');
        expect(tag('name', 'twitter:card')).toBe('summary_large_image');
        const image = tag('property', 'og:image');
        expect(image).toMatch(/\/images\/og-image\.png\?v=\d+$/);

        const png = await page.request.get(new URL(image!).pathname);
        expect(png.status()).toBe(200);
        expect(png.headers()['content-type']).toContain('image/png');
        await context.close();
    });

    test('the browser extension section leads to the Chrome Web Store, Firefox gets the download', async ({ browser }) => {
        const { context, page, errors } = await guestPage(browser, { viewport: { width: 1280, height: 900 } });
        await page.goto('/#extension');
        const store = page.getByRole('link', { name: 'Add to Chrome' });
        await expect(store).toBeVisible();
        await expect(store).toHaveAttribute('href', /^https:\/\/chromewebstore\.google\.com\/detail\/brainitive\/[a-p]{32}$/);
        await expect(store).toHaveAttribute('target', '_blank');
        await expect(page.getByRole('link', { name: 'Firefox download' })).toHaveAttribute('href', /brainitive-extension-firefox\.zip$/);
        // the manual "Load unpacked" steps for Chrome are gone
        await expect(page.getByText('Load unpacked')).toHaveCount(0);

        expect(errors, 'uncaught errors on the homepage').toEqual([]);
        await context.close();
    });

    test('"Star on GitHub" buttons lead to the repository (top bar, hero, open-source card, footer)', async ({ browser }) => {
        const { context, page, errors } = await guestPage(browser, { viewport: { width: 1280, height: 900 } });
        await page.goto('/');

        const stars = page.getByTestId('github-star');
        await expect(stars).toHaveCount(4);
        for (const link of await stars.all()) {
            await expect(link).toHaveAttribute('href', /^https:\/\/github\.com\/[\w.-]+\/[\w.-]+$/);
            await expect(link).toHaveAttribute('target', '_blank');
            await expect(link).toHaveAttribute('aria-label', /Star Brainitive on GitHub/);
        }
        await expect(stars.first()).toBeVisible(); // top bar

        expect(errors, 'uncaught errors on the homepage').toEqual([]);
        await context.close();
    });

    test('signed in, the account menu has "Star on GitHub" too', async ({ page }) => {
        await page.goto('/dashboard');
        await page.getByRole('button', { name: /Erin Owner/ }).last().click();
        const star = page.getByRole('menuitem', { name: /Star Brainitive on GitHub/ });
        await expect(star).toBeVisible();
        await expect(star).toHaveAttribute('href', /^https:\/\/github\.com\//);
    });

    test('fits a 375 px phone in light and dark mode', async ({ browser }) => {
        for (const colorScheme of ['light', 'dark'] as const) {
            const { context, page, errors } = await guestPage(browser, { colorScheme, viewport: { width: 375, height: 812 } });
            await page.goto('/');
            await expect(page.locator('html')).toHaveClass(new RegExp(`\\b${colorScheme}\\b`));

            const spotlight = page.getByRole('region', { name: 'New in recent releases' });
            await spotlight.scrollIntoViewIfNeeded();
            await expect(spotlight.getByRole('heading', { name: SPOTLIGHT[0], exact: true })).toBeVisible();
            await page.getByRole('heading', { level: 3, name: 'Customers, leads & billing', exact: true }).scrollIntoViewIfNeeded();
            await expect(page.getByRole('heading', { level: 4, name: 'Lead forms', exact: true }).last()).toBeVisible();
            await expectNoHorizontalScroll(page);

            expect(errors, `uncaught errors on the homepage (${colorScheme})`).toEqual([]);
            await context.close();
        }
    });
});
