import path from 'node:path';
import { expect, test, uid } from '../fixtures';
import { ROOT, SEED } from '../env';
import { card, column, openCard, sidebarField } from '../helpers/boards';

/**
 * BRAI-139: import a Jira CSV export. Upload → preview with the status / type / priority / people
 * mapping (nothing written yet) → start → report → the new board with the epic → story → sub-task
 * hierarchy and the comments. Fixture: tests/Fixtures/imports/jira/atlas.csv (synthetic).
 */
const FIXTURE = path.join(ROOT, 'tests', 'Fixtures', 'imports', 'jira', 'atlas.csv');

async function uploadJira(page: import('@playwright/test').Page, file: string | { name: string; mimeType: string; buffer: Buffer }) {
    await page.goto(`/workspaces/${SEED.workspace}/imports`);
    await page.getByRole('radio', { name: /Jira \(CSV\)/ }).click();
    await page.getByRole('combobox', { name: 'Project' }).click();
    await page.getByRole('option', { name: 'Website', exact: true }).click();
    await page.getByLabel('Exported file').setInputFiles(file);
    await page.getByRole('button', { name: 'Upload and preview' }).click();
    await expect(page).toHaveURL(/\/imports\/[0-9a-f-]{36}$/);
}

test('a Jira CSV export becomes a new board with epics, sub-tasks and comments', async ({ page }) => {
    const boardName = uid('From Jira');
    await uploadJira(page, FIXTURE);

    // the preview: counts, the default mapping and what can't come over
    await expect(page.getByTestId('import-status')).toHaveText('Ready to import');
    const preview = page.getByTestId('jira-preview');
    await expect(preview).toContainText('5 issues from Atlas (ATL)');
    await expect(preview.getByRole('list', { name: 'What the file contains' })).toContainText('2 nested under an epic or parent');
    await expect(page.getByLabel('Column for Code Review')).toHaveValue('Code Review');
    await expect(page.getByLabel('Column for Closed')).toHaveValue('Done');
    // matched by name: a global "Improvement" type (dev.brainitive.app's shared data) is picked instead of a new one
    await expect(page.getByRole('combobox', { name: 'Item type for Improvement' })).toHaveText(/^(Create “Improvement”|Improvement)$/);
    await expect(page.getByRole('combobox', { name: 'Item type for Sub-task' })).toHaveText('Subtask');
    await expect(page.getByRole('combobox', { name: 'Priority for Blocker' })).toHaveText('Highest');
    await expect(page.getByRole('combobox', { name: 'Member for Ola Nordmann' })).toHaveText('Unassigned');
    const notImported = page.getByRole('list', { name: 'Not imported' });
    await expect(notImported).toContainText('Attachments: 1');
    await expect(notImported).toContainText('Custom fields: 2');

    // map: merge Code Review into In Progress, Jane Doe → Erin Owner, no sprints (a Kanban board)
    await page.getByLabel('Board name').fill(boardName);
    await page.getByLabel('Column for Code Review').fill('In Progress');
    await page.getByRole('combobox', { name: 'Member for Jane Doe' }).click();
    await page.getByRole('option', { name: 'Erin Owner', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Member for Jane Doe' })).toHaveText('Erin Owner');
    await page.getByLabel(/Create the 2 sprints/).uncheck();
    await page.getByRole('button', { name: 'Start import', exact: true }).click();

    // the report
    await expect(page.getByTestId('import-status')).toHaveText('Done', { timeout: 30_000 });
    const report = page.getByTestId('import-report');
    await expect(report).toContainText('5 issues');
    await expect(report).toContainText('3 columns');
    await expect(report).toContainText('2 comments');
    await expect(report).toContainText('2 links');

    // the board: statuses → columns
    await page.getByRole('link', { name: 'Open what was imported' }).click();
    await expect(page.getByRole('heading', { level: 1, name: boardName })).toBeVisible();
    for (const name of ['To Do', 'In Progress', 'Done']) {
        await expect(column(page, name)).toBeVisible();
    }
    await expect(card(page, 'Checkout revamp', 'In Progress')).toBeVisible();
    await expect(card(page, 'Pay with card ✓', 'To Do')).toBeVisible();
    await expect(card(page, 'Crash on empty cart', 'Done')).toBeVisible();

    // the story: under its epic, with its sub-task, the Jira reference and the comments
    const story = await openCard(page, 'Pay with card ✓');
    await expect(story.getByRole('navigation', { name: 'Parent issues' })).toContainText('Checkout revamp');
    await expect(story.getByText('Validate card number')).toBeVisible();
    await expect(story.getByText(/Jira: ATL-2 · Reported by Jane Doe on 5 Mar 2024/)).toBeVisible();
    await expect(story.getByRole('cell', { name: 'Mastercard' })).toBeVisible();
    await expect(story.getByText('Originally by Jane Doe on 17 Mar 2024')).toBeVisible();
    await expect(story.getByText('Looks good; ship it.')).toBeVisible();
    await expect(sidebarField(story, 'Assignee')).not.toContainText('Erin Owner'); // Ola Nordmann stayed unassigned
    await page.keyboard.press('Escape');

    const epic = await openCard(page, 'Checkout revamp');
    await expect(sidebarField(epic, 'Assignee')).toContainText('Erin Owner');
    await expect(epic.getByText('Pay with card ✓')).toBeVisible(); // its story, as a sub-issue
});

test('a CSV that is not a Jira export fails before anything is created', async ({ page }) => {
    await uploadJira(page, { name: 'contacts.csv', mimeType: 'text/csv', buffer: Buffer.from('Name,Email\nJane,jane@example.test\n') });
    await expect(page.getByTestId('import-status')).toHaveText('Failed');
    await expect(page.getByRole('alert').filter({ hasText: 'This doesn’t look like a Jira CSV export' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Start import', exact: true })).toHaveCount(0);
});
