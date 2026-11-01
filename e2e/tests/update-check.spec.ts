import { execFileSync } from 'node:child_process';
import type { BrowserContext, Page } from '@playwright/test';
import { REMOTE, ROOT, appEnv } from '../env';
import { expect, test, urls } from '../fixtures';
import { guestContext, newAccount, register, skipWelcome, type Account } from '../helpers/auth-account';

/**
 * BRAI-433 "new version available": instance admins see the latest release in Instance admin →
 * Overview and a dismissible strip in the app (dismissed per version); regular users see neither.
 * GitHub is never called: the release is recorded with the e2e-only `--fake-latest` (APP_ENV=e2e).
 * A fresh account is the admin, so the seeded owner (promoted by email-templates.spec.ts) is untouched.
 */
test.describe('Update check', () => {
    test.skip(REMOTE, 'records a fake release and promotes an account with artisan (local e2e database only)');
    test.describe.configure({ mode: 'serial' });

    const artisan = (...args: string[]) => {
        try {
            execFileSync('php', ['artisan', ...args], { cwd: ROOT, env: appEnv(), stdio: 'pipe' });
        } catch (e) {
            const { stdout, stderr } = e as { stdout?: Buffer; stderr?: Buffer };
            throw new Error(`php artisan ${args.join(' ')}: ${stdout?.toString() ?? ''}${stderr?.toString() ?? ''}`);
        }
    };

    let admin: Account;
    let context: BrowserContext;
    let page: Page;

    test.beforeAll(async ({ browser }) => {
        context = await guestContext(browser);
        page = await context.newPage();
        admin = await register(page, newAccount('update-admin'));
        artisan('make:instance-admin', admin.email); // also verifies the address
        artisan('brainitive:check-update', '--fake-latest=99.0.0');
    });

    test.afterAll(async () => {
        artisan('brainitive:check-update', '--clear');
        artisan('remove:instance-admin', admin.email);
        await context?.close();
    });

    const strip = () => page.getByRole('status', { name: 'Update available' });

    test('an instance admin sees the new version in the admin console and dismisses the strip until a newer one', async () => {
        await page.goto('/admin');
        await skipWelcome(page);

        // the admin console: the alert + the Updates card with the links
        const alert = page.getByTestId('admin-update-available');
        await expect(alert).toContainText('Brainitive v99.0.0 available');
        await expect(alert.getByRole('link', { name: 'How to upgrade' })).toHaveAttribute('href', 'https://docs.brainitive.app/self-hosting/upgrading');
        await expect(alert.getByRole('link', { name: 'Release notes' })).toHaveAttribute('href', /\/releases\/tag\/v99\.0\.0$/);
        const card = page.getByRole('region', { name: 'Updates' });
        await expect(card).toContainText('Running version');
        await expect(card).toContainText('Update available');
        await expect(card.getByRole('link', { name: 'Upgrade with Docker' })).toHaveAttribute('href', /upgrading#docker$/);

        // the strip on every app page
        await page.goto(urls.home);
        await expect(strip()).toContainText('Brainitive v99.0.0 available');
        const saved = page.waitForResponse((r) => r.url().endsWith('/admin/update-notice/dismiss') && r.request().method() === 'POST');
        await strip().getByRole('button', { name: 'Dismiss update notice' }).click();
        await expect(strip()).toBeHidden();
        expect((await saved).status()).toBe(200);

        // dismissed for this version: gone after a reload, the admin console still says it
        await page.reload();
        await expect(page.getByRole('heading', { name: 'Workspaces', level: 1 })).toBeVisible();
        await expect(strip()).toHaveCount(0);
        await page.goto('/admin');
        await expect(page.getByTestId('admin-update-available')).toContainText('Brainitive v99.0.0 available');
        await expect(strip()).toHaveCount(0);

        // a newer release shows it again
        artisan('brainitive:check-update', '--fake-latest=99.1.0');
        await page.goto(urls.home);
        await expect(strip()).toContainText('Brainitive v99.1.0 available');
    });

    test('a regular user sees neither', async ({ memberPage }) => {
        await memberPage.goto(urls.home);
        await expect(memberPage.getByRole('heading', { name: 'Workspaces', level: 1 })).toBeVisible();
        await expect(memberPage.getByRole('status', { name: 'Update available' })).toHaveCount(0);
        await expect(memberPage.getByText(/Brainitive v99\.\d\.0 available/)).toHaveCount(0);

        const response = await memberPage.goto('/admin');
        expect(response?.status()).toBe(403);
    });
});
