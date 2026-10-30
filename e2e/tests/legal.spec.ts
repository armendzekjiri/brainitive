import type { Browser, Page } from '@playwright/test';
import { BASE_URL } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { createHelpdesk, createProject, createWorkspace, guestPage, projectKey } from '../helpers/helpdesk';

/**
 * Legal & privacy (BRAI-156): the legal pages and their footer links, no cookie banner, the statistics
 * choice on /cookies (the consent flow itself: analytics-consent.spec.ts), and customers' public pages naming who runs them — Workspace settings → Legal, the operator
 * line on a support page and a lead form, and the lead form's consent checkbox.
 * The e2e app runs as Brainitive Cloud (LEGAL_CLOUD=true in e2e/env.ts).
 */

const LEGAL_PAGES: [string, string][] = [
    ['Imprint', 'Imprint'],
    ['Privacy', 'Privacy Policy'],
    ['Terms', 'Terms of Use'],
    ['Cookies', 'Cookies and storage'],
    ['Sub-processors', 'Sub-processors'],
];

async function xsrf(page: Page): Promise<Record<string, string>> {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!cookie) throw new Error('no XSRF-TOKEN cookie: open an app page first');
    return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value), Accept: 'application/json' };
}

async function visitor(browser: Browser, options: { colorScheme?: 'light' | 'dark'; viewport?: { width: number; height: number } } = {}) {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, ...options });
    const page = await context.newPage();
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    return { page, errors, close: () => context.close() };
}

test.describe('legal pages', () => {
    test('the homepage footer links every legal page, and each one renders with its version', async ({ browser }) => {
        const guest = await visitor(browser);
        await guest.page.goto('/');
        const footer = guest.page.getByRole('contentinfo').getByTestId('legal-footer-links');
        await expect(footer).toBeVisible();

        for (const [link, title] of LEGAL_PAGES) {
            await guest.page.goto('/');
            await guest.page.getByRole('contentinfo').getByTestId('legal-footer-links').getByRole('link', { name: link, exact: true }).click();
            await expect(guest.page.getByTestId('legal-title')).toHaveText(title);
            await expect(guest.page.getByTestId('legal-version')).toContainText(/Version \d+\.\d+ · Last updated/);
        }

        // the DPA is linked from the legal pages' own footer
        await guest.page.goto('/privacy');
        await guest.page.getByTestId('legal-footer-links').getByRole('link', { name: 'DPA' }).click();
        await expect(guest.page.getByTestId('legal-title')).toHaveText('Data Processing Agreement');

        // no cookie banner: the only optional thing (statistics) asks with its own notice, on brainitive.app only
        await guest.page.goto('/');
        await expect(guest.page.getByRole('dialog', { name: 'Cookie consent' })).toHaveCount(0);

        expect(guest.errors).toEqual([]);
        await guest.close();
    });

    test('paid plans (v1.46.0): terms name Paddle and cancellation, 14-day money-back, privacy lists Paddle', async ({ browser }) => {
        const guest = await visitor(browser);

        await guest.page.goto('/terms');
        const plans = guest.page.locator('#plans');
        await expect(plans.getByRole('heading', { name: /Plans, prices and payment/ })).toBeVisible();
        await expect(plans.getByText(/merchant of record/)).toBeVisible();
        await expect(plans.getByText(/takes effect at the end of the period already paid/)).toBeVisible();
        await expect(guest.page.getByText(/early access/i)).toHaveCount(0);

        await guest.page.goto('/refund');
        await expect(guest.page.getByRole('heading', { name: /14-day money-back guarantee/ })).toBeVisible();
        await expect(guest.page.getByRole('heading', { name: /After 14 days: no refunds/ })).toBeVisible();
        await expect(guest.page.getByText(/7-day/)).toHaveCount(0);

        await guest.page.goto('/privacy');
        await expect(guest.page.locator('#payments').getByText(/Paddle\.com Market Ltd/).first()).toBeVisible();

        expect(guest.errors).toEqual([]);
        await guest.close();
    });

    test('the imprint names the operator’s contact; the privacy policy shows no third-party loads', async ({ browser }) => {
        const guest = await visitor(browser);
        const external: string[] = [];
        guest.page.on('request', (r) => {
            const host = new URL(r.url()).hostname;
            if (!['127.0.0.1', 'localhost', new URL(BASE_URL).hostname].includes(host)) external.push(r.url());
        });

        await guest.page.goto('/imprint');
        await expect(guest.page.getByTestId('imprint-details').getByRole('link', { name: 'hello@brainitive.app' })).toBeVisible();

        await guest.page.goto('/privacy');
        await expect(guest.page.getByRole('heading', { name: /Visit statistics \(Umami, only with your consent\)/ })).toBeVisible();
        await guest.page.goto('/');
        await guest.page.waitForLoadState('networkidle');
        expect(external, 'requests to other hosts').toEqual([]);
        await guest.close();
    });

    test('legal pages work at phone width and in dark mode', async ({ browser }) => {
        const guest = await visitor(browser, { colorScheme: 'dark', viewport: { width: 375, height: 740 } });
        for (const url of ['/privacy', '/terms', '/legal/subprocessors', '/legal/dpa', '/cookies']) {
            await guest.page.goto(url);
            await expect(guest.page.getByTestId('legal-title')).toBeVisible();
            const overflow = await guest.page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            expect(overflow, `${url} scrolls sideways`).toBeLessThanOrEqual(1);
        }
        await expect(guest.page.locator('html')).toHaveClass(/dark/);
        expect(guest.errors).toEqual([]);
        await guest.close();
    });

    test('a visitor sees and changes the statistics choice on /cookies', async ({ browser }) => {
        const guest = await visitor(browser);
        // an opt-out from before consent existed counts as “not allowed”
        await guest.page.addInitScript(() => localStorage.setItem('umami.disabled', '1'));
        await guest.page.goto('/cookies');
        const setting = guest.page.getByTestId('analytics-consent-setting');
        await expect(setting.getByTestId('analytics-state')).toHaveText('not allowed');
        await expect(guest.page.getByRole('cell', { name: 'brainitive:analytics-consent' })).toBeVisible();

        await setting.getByRole('button', { name: 'Allow', exact: true }).click();
        await expect(setting.getByTestId('analytics-state')).toHaveText('allowed');
        expect(await guest.page.evaluate(() => JSON.parse(localStorage.getItem('brainitive:analytics-consent') ?? '{}').choice)).toBe('granted');

        await setting.getByRole('button', { name: 'Don’t allow' }).click();
        await expect(setting.getByTestId('analytics-state')).toHaveText('not allowed');
        expect(await guest.page.evaluate(() => JSON.parse(localStorage.getItem('brainitive:analytics-consent') ?? '{}').choice)).toBe('denied');
        await guest.close();
    });

    test('sign-up shows the Terms notice and the legal links', async ({ browser }) => {
        const guest = await visitor(browser);
        await guest.page.goto('/register');
        const notice = guest.page.getByTestId('terms-notice');
        await expect(notice.getByRole('link', { name: 'Terms of Use' })).toHaveAttribute('href', /\/terms$/);
        await expect(notice.getByRole('link', { name: 'Privacy Policy' })).toHaveAttribute('href', /\/privacy$/);
        await expect(guest.page.getByTestId('legal-footer-links').getByRole('link', { name: 'Imprint' })).toBeVisible();
        await guest.close();
    });
});

test.describe('customers’ public pages', () => {
    test.describe.configure({ mode: 'serial' });

    const company = `Acme Legal ${uid('co')}`;
    let project: { workspace: string; slug: string } | null = null;

    test('a workspace admin adds the legal name, privacy policy and imprint', async ({ page }) => {
        const workspace = await createWorkspace(page, uid('Legal WS'));
        project = await createProject(page, uid('Legal project'), projectKey(), workspace);

        await page.goto(`/workspaces/${workspace}/edit`);
        await page.getByRole('tab', { name: 'Legal' }).click();
        const form = page.getByTestId('workspace-legal-form');

        // only https links
        await form.getByLabel('Privacy policy URL').fill('http://acme.example/privacy');
        await form.getByRole('button', { name: 'Save legal details' }).click();
        await expect(form.getByText('Enter a full https:// address.')).toBeVisible();

        await form.getByLabel('Legal name of your organisation').fill(company);
        await form.getByLabel('Privacy policy URL').fill('https://acme.example/privacy');
        await form.getByLabel('Imprint URL').fill('https://acme.example/imprint');
        await form.getByRole('button', { name: 'Save legal details' }).click();
        await expect(page.getByText('Legal details saved.').first()).toBeVisible();

        await page.reload();
        await page.getByRole('tab', { name: 'Legal' }).click();
        await expect(page.getByLabel('Legal name of your organisation')).toHaveValue(company);
    });

    test('the support page names the workspace and links its privacy policy and imprint', async ({ page, browser }) => {
        test.skip(!project, 'needs the workspace');
        const hd = await createHelpdesk(page, uid('Legal desk'), { template: 'contact-form', project: project! });
        const address = uid('legal').toLowerCase();
        await page.goto(`${hd.url}/edit`);
        await page.getByRole('tab', { name: 'Embed' }).click();
        await page.getByLabel('Address').fill(address);
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.locator('code', { hasText: `/support/${address}` })).toBeVisible();

        const guest = await guestPage(browser);
        await guest.page.goto(`/support/${address}`);
        const notice = guest.page.getByTestId('operator-notice');
        await expect(notice.getByTestId('operator-name')).toHaveText(company);
        await expect(notice.getByTestId('operator-privacy')).toHaveAttribute('href', 'https://acme.example/privacy');
        await expect(notice.getByTestId('operator-imprint')).toHaveAttribute('href', 'https://acme.example/imprint');
        await expect(guest.page.getByTestId('submit-privacy-line')).toContainText(`${company} uses what you send here`);
        await guest.close();
    });

    test('a lead form with a required consent checkbox only sends once it is ticked', async ({ page, browser }) => {
        test.skip(!project, 'needs the workspace');
        const projectUrl = urls.project(project!.slug, project!.workspace);
        await page.goto(projectUrl);
        const name = uid('Legal form');
        const created = await page.request.post(`${projectUrl}/lead-forms`, { headers: await xsrf(page), form: { name, template: 'blank' } });
        expect(created.ok(), `create form: ${created.status()}`).toBeTruthy();
        const formSlug = new URL(created.url()).pathname.split('/')[6];
        const formUrl = `${projectUrl}/lead-forms/${formSlug}`;

        // the builder: consent checkbox, required, own text
        await page.goto(`${formUrl}?tab=builder`);
        await page.getByRole('button', { name: 'Texts' }).click();
        await page.getByRole('button', { name: 'Required', exact: true }).click();
        await page.getByTestId('lead-consent-text').fill('Yes, {organization} may contact me about my request.');
        await expect(page.getByText('All changes saved')).toBeVisible();

        const publicSlug = uid('legalform').toLowerCase();
        const published = await page.request.post(`${formUrl}/publish`, { headers: { ...(await xsrf(page)), Accept: 'text/html' }, form: { public_slug: publicSlug } });
        expect(published.ok(), `publish: ${published.status()}`).toBeTruthy();

        const guest = await guestPage(browser);
        await guest.page.goto(`/f/${publicSlug}`);
        await expect(guest.page.getByTestId('operator-notice').getByTestId('operator-name')).toHaveText(company);
        await guest.page.getByLabel('Full name').fill('Lena Lead');
        await guest.page.getByLabel('Email').fill(`${uid('lena')}@example.test`);
        const consent = guest.page.getByTestId('lead-consent');
        await expect(consent).toContainText(`Yes, ${company} may contact me about my request.`);
        await expect(consent.getByRole('link', { name: 'Privacy policy' })).toHaveAttribute('href', 'https://acme.example/privacy');

        // not ticked: the form says so and sends nothing
        await guest.page.getByRole('button', { name: 'Submit' }).click();
        await expect(guest.page.getByText('Please confirm to send the form.')).toBeVisible();

        await consent.getByRole('checkbox').check();
        test.setTimeout(150_000);
        await expect(async () => {
            const submitted = guest.page.waitForResponse((r) => r.url().endsWith(`/f/${publicSlug}/submit`) && r.request().method() === 'POST');
            await guest.page.getByRole('button', { name: 'Submit' }).click();
            expect((await submitted).status()).toBeLessThan(300);
        }).toPass({ intervals: [10_000], timeout: 120_000 });
        await expect(guest.page.getByRole('heading', { name: 'Thank you!' })).toBeVisible();
        await guest.close();

        // the team sees the consent on the lead
        await page.goto(`${formUrl}?tab=leads`);
        await page.getByRole('button', { name: /Lead #1: Lena Lead/ }).click();
        await expect(page.getByTestId('lead-consent-record')).toContainText(`Yes, ${company} may contact me about my request.`);
    });
});
