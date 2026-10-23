import { expect, test, uid, urls } from '../fixtures';
import { card, createCard, openCard } from '../helpers/boards';

/**
 * After every deploy to dev.brainitive.app (pre-release.yml): the deployed server itself — nginx,
 * the PHP pool, migrated data, sessions — not a fresh copy. Runs against E2E_BASE_URL with the
 * e2e users seeded there (idempotent E2eSeeder) and only touches the "E2E Workspace" on dev.
 * Locally it runs against the local e2e app like any spec.
 */
test.describe('dev smoke', () => {
    test('the main pages load without errors', async ({ page }) => {
        for (const url of [urls.home, urls.workspace(), urls.project(), urls.board(), '/my-work', '/calendar', '/notifications', '/profile']) {
            const response = await page.goto(url);
            expect(response?.status(), url).toBeLessThan(400);
            await expect(page.locator('body')).not.toBeEmpty();
        }
        await page.goto(urls.board());
        for (const column of ['To Do', 'In Progress', 'Done']) {
            await expect(page.getByRole('heading', { name: column, exact: false }).or(page.getByText(column, { exact: true })).first()).toBeVisible();
        }
    });

    test('a card is created, commented, moved and still there after a reload', async ({ page }) => {
        const title = uid('smoke');
        const note = uid('smoke-comment');
        await page.goto(urls.board());
        await createCard(page, title);

        const dialog = await openCard(page, title);
        await dialog.locator('[contenteditable="true"][aria-placeholder="Add a comment…"]').click();
        await page.keyboard.type(note);
        const commented = page.waitForResponse((r) => r.request().method() === 'POST' && /\/items\/\d+\/comments/.test(r.url()));
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        expect((await commented).status()).toBeLessThan(400);

        const moved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+/.test(r.url()));
        await dialog.getByRole('combobox', { name: 'Status' }).click();
        await page.getByRole('option', { name: 'Done' }).click();
        expect((await moved).status()).toBeLessThan(400);

        await page.reload();
        await expect(card(page, title, 'Done')).toBeVisible();
        await expect((await openCard(page, title)).getByText(note)).toBeVisible();
    });

    test('two people: the member sees the card and is notified when it is assigned to him', async ({ page, memberPage }) => {
        const title = uid('smoke-assign');
        await page.goto(urls.board());
        const created = await createCard(page, title);
        const key = (await created.getByText(/^[A-Z]+-\d+$/).textContent())?.trim();
        await created.getByTitle('Unassigned — click to assign').click();
        await page.getByRole('button', { name: 'Max Member' }).click();
        await expect(created.getByTitle(/Assignee: Max Member/)).toBeVisible();

        await memberPage.goto(urls.board());
        await expect(card(memberPage, title)).toBeVisible();
        await memberPage.goto('/notifications');
        await expect(memberPage.getByText(`assigned you to ${key}`).first()).toBeVisible();
    });
});
