import type { Locator, Page } from '@playwright/test';
import { SEED } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { card, column, openCard, sidebarField } from '../helpers/boards';
import { createBlankProject } from '../helpers/workspaces-projects';

/**
 * BRAI-430 / GitHub #133: several item types can be turned off per project, they stay off, no type
 * picker offers them, and a card that already has one keeps showing it. Works on its own project:
 * the switch is per project, so the seeded project (and parallel specs) stay as they are.
 */

const typeRow = (page: Page, slug: string): Locator => page.getByTestId(`item-type-${slug}`);

async function openItemTypes(page: Page, projectSlug: string): Promise<void> {
    await page.goto(`${urls.project(projectSlug)}/edit`);
    await page.getByRole('tab', { name: 'Item types' }).click();
    await expect(typeRow(page, 'task')).toBeVisible();
}

async function setType(page: Page, name: string, slug: string, on: boolean): Promise<void> {
    const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && /\/item-types\/\d+$/.test(new URL(r.url()).pathname));
    await typeRow(page, slug).getByRole('button', { name: `${on ? 'Enable' : 'Disable'} ${name}` }).click();
    await saved;
    await expect(typeRow(page, slug)).toHaveAttribute('data-enabled', on ? 'true' : 'false');
}

/** The column's "Create card" dialog with its Type select open; returns the dialog. */
async function openQuickAddTypes(page: Page): Promise<Locator> {
    await column(page, 'To Do').getByRole('button', { name: 'Create', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Create card' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('combobox').first().click(); // Type (the parent picker comes after it)
    return dialog;
}

test('turn off two item types: they stay off, pickers skip them, old cards keep theirs', async ({ page }) => {
    const project = await createBlankProject(page, SEED.workspace, uid('Types'));
    const boardUrl = new URL(page.url()).pathname;
    const oldBug = uid('Old bug');

    // a card that gets its type before the type is turned off
    let dialog = await openQuickAddTypes(page);
    await page.getByRole('option', { name: 'Bug', exact: true }).click();
    await dialog.getByPlaceholder('What needs to be done?').fill(oldBug);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(dialog.getByPlaceholder('What needs to be done?')).toHaveValue('');
    await page.keyboard.press('Escape');
    await expect(card(page, oldBug).locator('svg.lucide-bug')).toBeVisible();

    // turn off Bug, then Story (the second one used to snap back on)
    await openItemTypes(page, project.slug);
    await setType(page, 'Bug', 'bug', false);
    await setType(page, 'Story', 'story', false);
    await page.reload();
    await page.getByRole('tab', { name: 'Item types' }).click();
    for (const slug of ['bug', 'story']) {
        await expect(typeRow(page, slug)).toHaveAttribute('data-enabled', 'false');
        await expect(typeRow(page, slug)).toContainText('Disabled');
    }
    await expect(typeRow(page, 'task')).toHaveAttribute('data-enabled', 'true');

    // board quick add
    await page.goto(boardUrl);
    dialog = await openQuickAddTypes(page);
    await expect(page.getByRole('option', { name: 'Task', exact: true })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Epic', exact: true })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Bug', exact: true })).toHaveCount(0);
    await expect(page.getByRole('option', { name: 'Story', exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    // the global create dialog (Ctrl+K → Create Issue) loads this project's types
    await page.keyboard.press('Control+k');
    const palette = page.getByRole('combobox', { name: 'Search or type a command' });
    await palette.fill('Create Issue');
    await page.getByRole('option', { name: /^Create Issue/ }).click();
    const create = page.getByRole('dialog', { name: 'Create Task' });
    await expect(create.getByRole('button', { name: 'Epic', exact: true })).toBeVisible();
    await expect(create.getByRole('button', { name: 'Bug', exact: true })).toHaveCount(0);
    await expect(create.getByRole('button', { name: 'Story', exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(create).toBeHidden();

    // the old card still shows Bug; its issue panel keeps it (marked off) but offers no Story
    await page.goto(boardUrl);
    await expect(card(page, oldBug).locator('svg.lucide-bug')).toBeVisible();
    const issue = await openCard(page, oldBug);
    const typeField = sidebarField(issue, 'Type');
    await expect(typeField).toContainText('Bug');
    await expect(typeField).toContainText('(turned off)');
    await typeField.getByRole('combobox').click();
    await expect(page.getByRole('option', { name: 'Epic', exact: true })).toBeVisible();
    await expect(page.getByRole('option', { name: /^Bug/ })).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByRole('option', { name: 'Story', exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');

    // turning Bug back on brings it back
    await openItemTypes(page, project.slug);
    await setType(page, 'Bug', 'bug', true);
    await expect(typeRow(page, 'story')).toHaveAttribute('data-enabled', 'false');
    await page.goto(boardUrl);
    await openQuickAddTypes(page);
    await expect(page.getByRole('option', { name: 'Bug', exact: true })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Story', exact: true })).toHaveCount(0);
});
