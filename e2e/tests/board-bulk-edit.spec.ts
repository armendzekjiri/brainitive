import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createBoard, postJson } from '../helpers/boards';
import { pageProps } from '../helpers/helpdesk';

/**
 * BRAI-108: the board's list view selects several issues and edits them at once. Every test works
 * on its own board (uid names), so the seeded issues stay as they are.
 */

/** A new board in the seeded project with these issues in its first column; returns its URL. */
async function boardWithIssues(page: Page, titles: string[]): Promise<string> {
    const boardUrl = await createBoard(page, uid('Bulk board'));
    await page.goto(boardUrl); // a full load: data-page holds this board
    const { board } = await pageProps<{ board: { columns: { id: number }[] } }>(page);
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value);
    for (const title of titles) {
        // what the "Create card" form posts (setup shortcut, one after another)
        const response = await page.request.post(`${boardUrl}/items`, {
            headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
            data: { title, column_id: board.columns[0].id },
            maxRedirects: 0,
        });
        expect(response.status(), `create ${title}`).toBe(302);
    }
    await page.goto(`${boardUrl}?view=list`);
    for (const title of titles) await expect(row(page, title)).toBeVisible();
    return boardUrl;
}

const row = (page: Page, title: string): Locator => page.getByRole('row').filter({ hasText: title });
const checkbox = (page: Page, title: string): Locator => row(page, title).getByRole('checkbox', { name: /^Select [A-Z]+-\d+$/ });
const bar = (page: Page): Locator => page.getByRole('toolbar', { name: 'Bulk edit selected issues' });
const bulkWrite = (page: Page, method: 'PATCH' | 'DELETE' = 'PATCH') =>
    page.waitForResponse((r) => r.request().method() === method && /\/items\/bulk$/.test(new URL(r.url()).pathname));

test('select 3, set assignee and priority, move to Done, add a label, delete 2', async ({ page }) => {
    const [a, b, c, d] = ['A', 'B', 'C', 'D'].map((n) => uid(`bulk-${n}`));
    const label = uid('bulk-label');
    const { id: labelId } = await postJson<{ id: number }>(page, `${urls.project()}/labels`, { name: label, color: '#22c55e' });
    const boardUrl = await boardWithIssues(page, [a, b, c, d]);
    await expect(bar(page)).toHaveCount(0);

    // click the first, shift-click the third: the range in between is selected too
    await checkbox(page, a).check();
    await checkbox(page, c).click({ modifiers: ['Shift'] });
    for (const title of [a, b, c]) await expect(checkbox(page, title)).toBeChecked();
    await expect(checkbox(page, d)).not.toBeChecked();
    await expect(bar(page)).toContainText('3 selected');

    // assignee (searchable list)
    let saved = bulkWrite(page);
    await bar(page).getByRole('button', { name: 'Assignee' }).click();
    await page.getByPlaceholder('Search people…').fill('Max');
    await page.getByRole('option', { name: 'Max Member' }).click();
    await saved;
    await expect(page.getByText('Updated 3 issues.').first()).toBeVisible();
    for (const title of [a, b, c]) await expect(row(page, title)).toContainText('Max Member');
    await expect(row(page, d)).toContainText('Unassigned');

    // the selection stays for the next action
    await expect(bar(page)).toContainText('3 selected');
    saved = bulkWrite(page);
    await bar(page).getByRole('button', { name: 'Priority' }).click();
    await page.getByRole('menuitem', { name: 'Highest' }).click();
    await saved;
    for (const title of [a, b, c]) await expect(row(page, title)).toContainText('Highest');

    saved = bulkWrite(page);
    await bar(page).getByRole('button', { name: 'Status' }).click();
    await page.getByRole('menuitem', { name: /^Done$/i }).click();
    await saved;
    for (const title of [a, b, c]) await expect(row(page, title).getByText('Done', { exact: true })).toBeVisible();

    saved = bulkWrite(page);
    await bar(page).getByRole('button', { name: 'Labels' }).click();
    await page.getByRole('menuitem', { name: 'Add label' }).click();
    await page.getByRole('menuitem', { name: label }).click();
    await saved;

    // persisted: a fresh load filtered by the label shows exactly the three, done and assigned
    await page.goto(`${boardUrl}?view=list&labels=${labelId}`);
    await expect(row(page, d)).toHaveCount(0);
    for (const title of [a, b, c]) {
        await expect(row(page, title)).toContainText('Max Member');
        await expect(row(page, title)).toContainText('Highest');
        await expect(row(page, title).getByText('Done', { exact: true })).toBeVisible();
    }

    // delete two, after confirming
    await page.goto(`${boardUrl}?view=list`);
    await checkbox(page, a).check();
    await checkbox(page, b).check();
    await expect(bar(page)).toContainText('2 selected');
    await bar(page).getByRole('button', { name: 'Delete' }).click();
    const confirm = page.getByRole('alertdialog', { name: 'Delete 2 issues?' });
    await expect(confirm).toBeVisible();
    saved = bulkWrite(page, 'DELETE');
    await confirm.getByRole('button', { name: 'Delete 2 issues' }).click();
    await saved;
    await expect(page.getByText('Deleted 2 issues.').first()).toBeVisible();
    await expect(row(page, a)).toHaveCount(0);
    await expect(row(page, b)).toHaveCount(0);
    await expect(bar(page)).toHaveCount(0);

    await page.reload();
    await expect(row(page, c)).toBeVisible();
    await expect(row(page, d)).toBeVisible();
    await expect(row(page, a)).toHaveCount(0);
});

test('select all visible rows, Esc clears; due date set and cleared; cancel keeps the issues', async ({ page }) => {
    const [a, b] = [uid('bulk-all-A'), uid('bulk-all-B')];
    await boardWithIssues(page, [a, b]);

    await page.getByRole('checkbox', { name: 'Select all issues' }).check();
    await expect(bar(page)).toContainText('2 selected');
    await expect(page.getByRole('checkbox', { name: 'Select all issues' })).toBeChecked();

    // the first Esc closes an open menu, the next one clears the selection
    await bar(page).getByRole('button', { name: 'Priority' }).click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect(bar(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(bar(page)).toHaveCount(0);
    await expect(checkbox(page, a)).not.toBeChecked();

    // due date: set, then clear
    await checkbox(page, a).check();
    await checkbox(page, b).check();
    let saved = bulkWrite(page);
    await bar(page).getByRole('button', { name: 'Due date' }).click();
    await page.getByLabel('Due date for 2 issues').fill('2030-03-15');
    await page.getByRole('button', { name: 'Set', exact: true }).click();
    await saved;
    // the list prints the date in the browser's locale; the year is enough to tell set from cleared
    const shown = /2030/;
    const dueCell = (title: string) => row(page, title).getByRole('cell').nth(7); // ☐ Key Title Parent Status Priority Assignee Due
    for (const title of [a, b]) await expect(dueCell(title)).toContainText(shown);

    saved = bulkWrite(page);
    await bar(page).getByRole('button', { name: 'Due date' }).click();
    await page.getByRole('button', { name: 'Clear due date' }).click();
    await saved;
    for (const title of [a, b]) await expect(dueCell(title)).toHaveText('—');

    // Delete asks first; Cancel changes nothing
    await bar(page).getByRole('button', { name: 'Delete' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('alertdialog')).toHaveCount(0);
    await expect(row(page, a)).toBeVisible();
    await expect(bar(page)).toContainText('2 selected');

    // a click on the row itself still opens the issue, not the selection
    await row(page, a).getByText(a, { exact: true }).click();
    await expect(page.getByRole('dialog', { name: new RegExp(`^Edit [A-Z]+-\\d+: ${a}`) })).toBeVisible();
});
