import { expect, test, uid, urls } from '../fixtures';
import { createProject, projectKey } from '../helpers/helpdesk';

/** The N shortcut (C is Quick capture), the desktop menu command, and the Create modal remembering the last project. */

const createDialog = (page: import('@playwright/test').Page) => page.getByRole('dialog', { name: /^Create/ });

test.describe('create shortcut', () => {
    test('N opens Create on the Task tab, but never while typing', async ({ page }) => {
        await page.goto(urls.workspace());
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
        await page.keyboard.press('n');
        await expect(createDialog(page)).toBeVisible();
        await expect(createDialog(page).getByPlaceholder('What needs to be done?')).toBeVisible();
        // inside the open dialog the key is just a letter
        await createDialog(page).getByPlaceholder('What needs to be done?').fill('');
        await page.keyboard.type('nn');
        await expect(createDialog(page).getByPlaceholder('What needs to be done?')).toHaveValue('nn');
        await page.keyboard.press('Escape');
        await expect(createDialog(page)).toBeHidden();

        // an input on the page: nothing opens
        await page.goto('/captures');
        const search = page.getByLabel('Search captures');
        await search.click();
        await page.keyboard.press('n');
        await expect(createDialog(page)).toBeHidden();
    });

    test('the desktop menu command opens Create and is handled (default prevented)', async ({ page }) => {
        await page.goto(urls.workspace());
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
        const prevented = await page.evaluate(() => {
            const event = new CustomEvent('brainitive-desktop:command', { detail: { command: 'new-issue' }, cancelable: true });
            window.dispatchEvent(event);
            return event.defaultPrevented;
        });
        expect(prevented).toBe(true);
        await expect(createDialog(page)).toBeVisible();
    });

    test('Create pre-selects the project a task was last created in', async ({ page }) => {
        const name = uid('Remembered');
        const project = await createProject(page, name, projectKey());
        await page.goto(urls.board('main-board', project.slug));
        await page.keyboard.press('n');
        const dialog = createDialog(page);
        const title = uid('remember-card');
        await dialog.getByPlaceholder('What needs to be done?').fill(title);
        await dialog.getByRole('button', { name: /^Create (Task|Issue)/ }).click();
        await expect(dialog).toBeHidden();

        await page.goto(urls.workspace());
        await page.keyboard.press('n');
        await expect(createDialog(page)).toBeVisible();
        await expect(createDialog(page).getByRole('combobox').first()).toContainText(name);
    });
});
