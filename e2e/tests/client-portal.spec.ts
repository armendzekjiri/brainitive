import type { Page } from '@playwright/test';
import { SEED } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { guestContext } from '../helpers/auth-account';
import { docEditor, docReader, draftSaved, publish } from '../helpers/content';
import { expectNotification } from '../helpers/notifications';
import { newGuest } from '../helpers/portal';
import { createBlankProject } from '../helpers/workspaces-projects';
import { canReadMail, mailLink } from '../mail';

/**
 * Client portal (v1.42.0, BRAI-73): the team shares an exact doc version with an invited client, the
 * client approves it, a newer version supersedes the approval, and a revoked client is locked out.
 * Runs in its own project: one portal per project.
 */

/** A published doc in `projectUrl` with `text`; returns the page URL. */
async function publishedDoc(page: Page, projectUrl: string, name: string, text: string): Promise<string> {
    await page.goto(projectUrl);
    await page.getByRole('button', { name: 'Add content' }).first().click();
    await page.getByRole('dialog', { name: 'Create New' }).getByRole('button', { name: 'Page', exact: true }).click();
    const form = page.getByRole('dialog', { name: 'Create Page' });
    await form.getByLabel('Page Name').fill(name);
    await form.getByRole('button', { name: 'Create Page' }).click();
    await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}$/);

    await docEditor(page).click();
    const saved = draftSaved(page);
    await page.keyboard.type(text);
    await saved;
    await publish(page);
    await expect(docReader(page)).toContainText(text);
    return page.url();
}

/** Edit a published doc and publish the change as a new version. */
async function publishNewVersion(page: Page, pageUrl: string, addition: string): Promise<void> {
    await page.goto(pageUrl);
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await docEditor(page).click();
    await page.keyboard.press('End');
    const draft = draftSaved(page);
    await page.keyboard.type(addition);
    await draft;
    const updated = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().endsWith('/publish-changes'));
    await page.getByRole('button', { name: 'Update', exact: true }).click();
    await updated;
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
}

async function publishToPortal(page: Page, teamUrl: string, docName: string, version: number): Promise<void> {
    await page.goto(teamUrl);
    await page.getByLabel('Published doc (its latest version)').selectOption({ label: `${docName} (v${version})` });
    await page.getByRole('button', { name: 'Publish to the portal' }).click();
    await expect(page).toHaveURL(/\/client-portal\/deliverables\/\d+$/);
    await expect(page.getByText(`Version ${version} is now in the client portal.`)).toBeVisible();
}

test.describe('client portal', () => {
    test.skip(!canReadMail, 'the client follows the invitation mail, which only the local e2e app writes to a file');

    test('an invited client approves an exact version, a new version needs a fresh approval, a revoked client is denied', async ({ page, browser }) => {
        test.setTimeout(120_000);
        const project = await createBlankProject(page, SEED.workspace);
        const projectUrl = urls.project(project.slug);
        const teamUrl = `${projectUrl}/client-portal`;
        const docName = uid('Proposal');
        const pageUrl = await publishedDoc(page, projectUrl, docName, 'First wording for the client');

        // ── the admin turns the portal on (entry point: the project's quick links) ──
        await page.goto(projectUrl);
        await page.getByRole('link', { name: 'Project client portal' }).click();
        await expect(page.getByRole('heading', { name: 'Client portal', level: 1 })).toBeVisible();
        const slug = uid('acme').toLowerCase();
        await page.getByLabel('Address').fill(slug);
        await page.getByLabel('Welcome text').fill('Hello! Everything for your new website is here.');
        await page.getByRole('button', { name: 'Turn on the client portal' }).click();
        await expect(page.getByTestId('client-portal-url')).toContainText(`/client/${slug}`);

        // ── publishes version 1 of the doc and invites a client ──
        await publishToPortal(page, teamUrl, docName, 1);
        await page.goto(teamUrl);
        const guest = newGuest('client');
        await page.getByLabel('Client email').fill(guest.email);
        await page.getByLabel(/^Name/).fill(guest.name);
        await page.getByRole('button', { name: 'Invite' }).click();
        await expect(page.getByText(`Invitation sent to ${guest.email}.`)).toBeVisible();
        await expect(page.getByTestId('client-member').filter({ hasText: guest.email })).toContainText('active');

        // ── the client follows the emailed sign-in link and lands in the portal ──
        const context = await guestContext(browser);
        try {
            const client = await context.newPage();
            await client.goto(await mailLink(guest.email, /\/guest\/login\/[A-Za-z0-9]{48}/));
            await client.getByTestId('portal-login-confirm').click();
            await client.waitForURL((url) => url.pathname === `/client/${slug}`);
            await expect(client.getByText('Hello! Everything for your new website is here.')).toBeVisible();

            await client.getByRole('link', { name: new RegExp(docName) }).click();
            await expect(client.getByTestId('deliverable-content')).toContainText('First wording for the client');
            await expect(client.getByTestId('deliverable-status').first()).toHaveText('Awaiting review');
            await client.getByRole('button', { name: 'Approve', exact: true }).click();
            await expect(client.getByText('Approved. The team has been told.')).toBeVisible();
            await expect(client.getByTestId('decision-entry')).toContainText(`${guest.name} (you) approved version 1`);
            const v1Url = client.url();

            // ── the team sees the decision (page + bell) ──
            await page.goto(teamUrl);
            await page.getByTestId('team-deliverable').filter({ hasText: docName }).click();
            await expect(page.getByTestId('decision-entry')).toContainText(`${guest.name} approved version 1`);
            await expect(page.getByTestId('deliverable-status').first()).toHaveText('Approved');
            await expectNotification(page, (n) => n.type === 'client_decision' && (n.title ?? '').includes(docName), 'client approved');

            // ── a new version of the doc supersedes the approval ──
            await publishNewVersion(page, pageUrl, ' and a second paragraph');
            await publishToPortal(page, teamUrl, docName, 2);

            await client.goto(`/client/${slug}`);
            const row = client.getByRole('link', { name: new RegExp(docName) });
            await expect(row).toHaveCount(1);
            await expect(row).toContainText('Version 2');
            await expect(row.getByTestId('deliverable-status')).toHaveText('Awaiting review');

            await client.goto(v1Url);
            await expect(client.getByTestId('deliverable-status').first()).toHaveText('Superseded');
            await expect(client.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
            await client.getByRole('link', { name: 'Open version 2' }).click();
            await expect(client.getByTestId('deliverable-content')).toContainText('and a second paragraph');
            await expect(client.getByRole('button', { name: 'Approve', exact: true })).toBeEnabled();
            await expect(client.getByText('No decision yet.')).toBeVisible();

            // phone width: no sideways scrolling
            await client.setViewportSize({ width: 375, height: 800 });
            expect(await client.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

            // ── the admin revokes the client: the portal is closed to them ──
            await page.goto(teamUrl);
            page.once('dialog', (dialog) => void dialog.accept());
            await page.getByTestId('client-member').filter({ hasText: guest.email }).getByRole('button', { name: 'Revoke' }).click();
            await expect(page.getByTestId('client-member').filter({ hasText: guest.email })).toContainText('revoked');

            await client.reload();
            await expect(client.getByTestId('client-portal-denied')).toContainText('Your access was removed');
            await client.goto(`/client/${slug}`);
            await expect(client.getByTestId('client-portal-denied')).toBeVisible();
        } finally {
            await context.close();
        }
    });

    test('a visitor who is not signed in is sent to the sign-in page first, whether or not the portal exists', async ({ browser }) => {
        const context = await guestContext(browser);
        try {
            const visitor = await context.newPage();
            // not signed in: the sign-in page first; the portal decides after that
            await visitor.goto(`/client/${uid('nobody')}`);
            await expect(visitor).toHaveURL(/\/guest\/login\?return_to=/);
        } finally {
            await context.close();
        }
    });
});
