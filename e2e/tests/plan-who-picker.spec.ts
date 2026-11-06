import { expect, test, uid, urls } from '../fixtures';
import { projectSectionAdd } from '../helpers/leads-plans-search';

/**
 * A plan row's Who cell offers the project's people in a real list (not the browser datalist):
 * type part of a name, pick it with the keyboard or the mouse.
 */
test('pick a project member for a plan row from the Who list', async ({ page }) => {
    await page.goto(urls.project());
    await projectSectionAdd(page, 'Plans').click();
    const dialog = page.getByRole('dialog', { name: 'Create Plan' });
    await dialog.getByPlaceholder('e.g., Roadmap 2027').fill(uid('plan'));
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page).toHaveURL(/\/plans\/[^/]+$/);

    await page.getByRole('toolbar', { name: 'Plan tools' }).getByRole('button', { name: 'Row', exact: true }).click();
    const nameInput = page.getByRole('textbox', { name: 'name', exact: true });
    await nameInput.fill(uid('row'));
    // Tab: next cell (Who) opens its editor
    await nameInput.press('Tab');
    await page.keyboard.press('Enter');

    const who = page.getByRole('combobox', { name: 'who' });
    await expect(who).toBeFocused();
    const list = page.getByRole('listbox', { name: 'Project members' });
    await expect(list).toBeVisible();
    const first = (await list.getByRole('option').first().textContent())!.trim();
    await who.fill(first.slice(0, 3));
    await expect(list.getByRole('option', { name: first })).toBeVisible();
    await list.getByRole('option', { name: first }).click();
    await expect(who).toBeHidden();
    await expect(page.getByText(first, { exact: true }).first()).toBeVisible();
});
