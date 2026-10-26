import path from 'node:path';
import { expect, test, uid } from '../fixtures';
import { ROOT, SEED } from '../env';
import { card, column, openCard, sidebarField } from '../helpers/boards';

/**
 * BRAI-65: import a Trello board export (JSON). Upload → preview (nothing written yet) → map a
 * member → start → report → the new board with the cards in the right columns.
 * Fixture: tests/Fixtures/imports/trello/board.json (synthetic).
 */
const FIXTURE = path.join(ROOT, 'tests', 'Fixtures', 'imports', 'trello', 'board.json');

test('a Trello board export becomes a new board, with a preview and member mapping first', async ({ page }) => {
    const boardName = uid('From Trello');

    await page.goto(`/workspaces/${SEED.workspace}/imports`);
    await expect(page.getByRole('heading', { level: 1, name: 'Bring your data' })).toBeVisible();
    await page.getByRole('radio', { name: /Trello board \(JSON\)/ }).click();
    await page.getByRole('combobox', { name: 'Project' }).click();
    await page.getByRole('option', { name: 'Website', exact: true }).click();
    await page.getByLabel('Exported file').setInputFiles(FIXTURE);
    await page.getByRole('button', { name: 'Upload and preview' }).click();

    // the preview: lists with card counts, the done column, people and what can't come over
    await expect(page).toHaveURL(/\/imports\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('import-status')).toHaveText('Ready to import');
    const lists = page.getByRole('list', { name: 'Lists' });
    await expect(lists.getByRole('listitem')).toHaveCount(4);
    await expect(lists.getByRole('listitem').first()).toContainText('Backlog 📋');
    await expect(lists.getByRole('listitem').first()).toContainText('2 cards');
    await expect(lists.getByRole('listitem').filter({ hasText: 'Done ✅' })).toContainText('Done column');
    await expect(page.getByText('5 open cards in 4 lists')).toBeVisible();
    await expect(page.getByRole('list', { name: 'Labels' })).toContainText('Frontend');
    const notImported = page.getByRole('list', { name: 'Not imported' });
    await expect(notImported).toContainText('Comments: 3');
    await expect(notImported).toContainText('Custom fields: 1');
    await expect(notImported).toContainText('Power-ups: 1');

    await page.getByLabel('New board name').fill(boardName);
    await page.getByRole('combobox', { name: "Assign Jane Doe's cards to" }).click();
    await page.getByRole('option', { name: 'Max Member', exact: true }).click();
    await expect(page.getByRole('combobox', { name: "Assign Jane Doe's cards to" })).toHaveText('Max Member');
    await expect(page.getByRole('combobox', { name: "Assign Bob Builder's cards to" })).toHaveText('Unassigned');
    await page.getByRole('button', { name: 'Start import', exact: true }).click();

    // the report
    await expect(page.getByTestId('import-status')).toHaveText('Done', { timeout: 30_000 });
    const report = page.getByTestId('import-report');
    await expect(report).toContainText('5 issues');
    await expect(report).toContainText('4 columns');
    await expect(report).toContainText('2 archived card(s) were skipped.');

    // the new board: lists → columns, cards in order, the mapped assignee
    await page.getByRole('link', { name: 'Open what was imported' }).click();
    await expect(page.getByRole('heading', { level: 1, name: boardName })).toBeVisible();
    for (const name of ['Backlog 📋', 'Doing', 'Done ✅', 'Later']) {
        await expect(column(page, name)).toBeVisible();
    }
    const backlog = column(page, 'Backlog 📋').getByRole('article');
    await expect(backlog).toHaveCount(2);
    await expect(backlog.nth(0)).toContainText('Write the docs — Ünïcødé 文档 🚀');
    await expect(backlog.nth(1)).toContainText('Set up the repository');
    await expect(card(page, 'Implement the API', 'Doing')).toBeVisible();
    await expect(card(page, 'Ship v1', 'Done ✅')).toBeVisible();
    await expect(page.getByRole('heading', { level: 4, name: 'Archived card', exact: true })).toHaveCount(0);

    const issue = await openCard(page, 'Set up the repository');
    await expect(sidebarField(issue, 'Assignee')).toContainText('Max Member');
    await expect(issue.getByText('Install Node')).toBeVisible();
    await expect(issue.getByRole('link', { name: 'the README' })).toHaveAttribute('href', 'https://example.com/readme');
});

test('a file that is not a Trello export fails before anything is created', async ({ page }) => {
    await page.goto(`/workspaces/${SEED.workspace}/imports`);
    await page.getByRole('radio', { name: /Trello board \(JSON\)/ }).click();
    await page.getByRole('combobox', { name: 'Project' }).click();
    await page.getByRole('option', { name: 'Website', exact: true }).click();
    await page.getByLabel('Exported file').setInputFiles({ name: 'not-trello.json', mimeType: 'application/json', buffer: Buffer.from('{"issues": []}') });
    await page.getByRole('button', { name: 'Upload and preview' }).click();

    await expect(page.getByTestId('import-status')).toHaveText('Failed');
    await expect(page.getByRole('alert').filter({ hasText: 'This isn’t a Trello board export.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Start import', exact: true })).toHaveCount(0);
});
