import { execFileSync } from 'node:child_process';
import { REMOTE, ROOT, USERS, appEnv } from '../env';
import { expect, test } from '../fixtures';
import { newestMail } from '../mail';

/**
 * BRAI-155 branded emails: Instance admin → Emails previews every email the app sends (sample data
 * only) in light / dark and desktop / phone, and "Send test to me" mails the admin. The seeded owner
 * is made an instance admin for this file (artisan, local e2e database only) and demoted afterwards.
 */
test.describe('Email templates', () => {
    test.skip(REMOTE, 'promotes the owner with artisan and reads the local mail log');
    // one worker: the promotion (beforeAll) and demotion (afterAll) must wrap every test of this file
    test.describe.configure({ mode: 'serial' });

    const artisan = (...args: string[]) => {
        try {
            execFileSync('php', ['artisan', ...args], { cwd: ROOT, env: appEnv(), stdio: 'pipe' });
        } catch (e) {
            const { stdout, stderr } = e as { stdout?: Buffer; stderr?: Buffer };
            throw new Error(`php artisan ${args.join(' ')}: ${stdout?.toString() ?? ''}${stderr?.toString() ?? ''}`);
        }
    };

    test.beforeAll(() => {
        artisan('make:instance-admin', USERS.owner.email);
    });

    test.afterAll(() => {
        artisan('remove:instance-admin', USERS.owner.email);
    });

    test('an admin previews emails and sends one to themselves', async ({ page }) => {
        await page.goto('/admin/emails');
        await expect(page.getByRole('heading', { name: 'Emails', level: 1 })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Emails' })).toHaveAttribute('aria-current', 'page');

        const nav = page.getByRole('navigation', { name: 'Emails' });
        const frame = page.frameLocator('iframe[title^="Preview:"]');

        // a member notification: branded layout, logo, "Manage notifications"
        await nav.getByRole('button', { name: 'Issue commented', exact: true }).click();
        await expect(page.getByTestId('email-subject')).toHaveText('Sam Rivera commented on WEB-42');
        await expect(frame.locator('[data-brand="brainitive"]')).toBeAttached();
        await expect(frame.getByRole('img', { name: 'Brainitive' }).first()).toBeVisible();
        await expect(frame.getByRole('heading', { name: 'Sam Rivera commented on WEB-42' })).toBeVisible();
        await expect(frame.getByRole('link', { name: 'Manage notifications' })).toBeVisible();

        // a customer mail: signed by the helpdesk, no preferences link — also in dark mode at phone width
        await nav.getByRole('button', { name: 'Ticket reply', exact: true }).click();
        await expect(page.getByTestId('email-subject')).toContainText('Reply on ticket SUP-12');
        await page.getByRole('button', { name: 'Dark', exact: true }).click();
        await page.getByRole('button', { name: 'Phone', exact: true }).click();
        await expect(page.locator('iframe[title^="Preview:"]')).toHaveCSS('width', '375px');
        await expect(frame.getByText('— Northwind Support', { exact: true })).toBeVisible();
        await expect(frame.getByRole('link', { name: 'Manage notifications' })).toHaveCount(0);
        await expect(frame.getByRole('img', { name: 'Brainitive' }).last()).toBeVisible(); // the dark logo

        // the text part
        await page.getByRole('button', { name: 'Text', exact: true }).click();
        await expect(page.locator('pre')).toContainText('You get this email because you have a ticket with Northwind Support.');

        // send it to myself
        await page.getByRole('button', { name: 'Send test to me' }).click();
        await expect(page.getByText(`Test email sent to ${USERS.owner.email}.`)).toBeVisible();
        // the same confirmation again on a second send (flash toasts are keyed on the visit)
        await expect(page.getByText(`Test email sent to ${USERS.owner.email}.`)).toBeHidden({ timeout: 10_000 });
        await page.getByRole('button', { name: 'Send test to me' }).click();
        await expect(page.getByText(`Test email sent to ${USERS.owner.email}.`)).toBeVisible();

        const mail = await newestMail(USERS.owner.email, /Subject: \[Test\] Reply on ticket SUP-12/);
        expect(mail).toContain('data-brand="brainitive"');
        expect(mail).toContain('/images/email/logo.png');
        expect(mail).toContain('Northwind Support');
        expect(mail).not.toContain('Manage notifications');
        expect(mail).not.toMatch(/Regards,\s*Laravel/);
    });

    test('the member does not get the admin console', async ({ memberPage }) => {
        const response = await memberPage.goto('/admin/emails');
        expect(response?.status()).toBe(403);
    });
});
