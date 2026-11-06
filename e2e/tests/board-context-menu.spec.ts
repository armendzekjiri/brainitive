import type { Locator, Page } from '@playwright/test';
import { SEED } from '../env';
import { expect, test, uid } from '../fixtures';
import { boardWithIssues, card, column, createCard, issueDialog, listRow as row } from '../helpers/boards';
import { memberId } from '../helpers/time-overview';
import { request } from '../helpers/workload';
import { createBlankProject } from '../helpers/workspaces-projects';

/**
 * GitHub #143: right-click an issue — a list row or a kanban card — for the bulk bar's actions.
 * Every test works on its own board (uid names), so the seeded issues stay as they are.
 */

const checkbox = (page: Page, title: string): Locator => row(page, title).getByRole('checkbox', { name: /^Select [A-Z]+-\d+$/ });
const menu = (page: Page): Locator => page.getByRole('menu').first();
const bulkWrite = (page: Page, method: 'PATCH' | 'DELETE' = 'PATCH') =>
    page.waitForResponse((r) => r.request().method() === method && /\/items\/bulk$/.test(new URL(r.url()).pathname));

/** Right-click the issue title (not a link or a checkbox) and wait for the menu. */
async function rightClick(page: Page, scope: Locator, title: string): Promise<Locator> {
    await scope.getByText(title, { exact: true }).click({ button: 'right' });
    await expect(menu(page)).toBeVisible();
    return menu(page);
}

/** Open a submenu ("Status", "Priority"…) and pick an entry. */
async function pick(page: Page, submenu: string, entry: string | RegExp): Promise<void> {
    await menu(page).getByRole('menuitem', { name: submenu, exact: true }).click();
    await page.getByRole('menuitem', { name: entry }).click();
}

test('list: right-click a row to change its status and priority, then delete it', async ({ page }) => {
    const [a, b] = [uid('ctx-A'), uid('ctx-B')];
    await boardWithIssues(page, uid('Context board'), [a, b]);

    // header cells keep the browser's own menu
    await page.getByRole('columnheader', { name: 'Parent' }).click({ button: 'right' });
    await expect(page.getByRole('menu')).toHaveCount(0);

    const opened = await rightClick(page, row(page, a), a);
    await expect(opened.getByText(/^[A-Z]+-\d+$/)).toBeVisible(); // one issue: its key as the header
    for (const entry of ['Open', 'Copy link', 'Copy key', 'Status', 'Assignee', 'Priority', 'Type', 'Due date', 'Labels', 'Delete…']) {
        await expect(opened.getByRole('menuitem', { name: entry, exact: true })).toBeVisible();
    }
    let saved = bulkWrite(page);
    await pick(page, 'Status', /^Done$/i);
    await saved;
    await expect(row(page, a).getByText('Done', { exact: true })).toBeVisible();
    await expect(row(page, b).getByText('Done', { exact: true })).toHaveCount(0);

    await rightClick(page, row(page, a), a);
    saved = bulkWrite(page);
    await pick(page, 'Priority', 'Highest');
    await saved;
    await expect(row(page, a)).toContainText('Highest');
    await expect(row(page, b)).not.toContainText('Highest');

    // persisted
    await page.reload();
    await expect(row(page, a)).toContainText('Highest');
    await expect(row(page, a).getByText('Done', { exact: true })).toBeVisible();

    // Delete asks first, like the bulk bar
    await rightClick(page, row(page, a), a);
    await menu(page).getByRole('menuitem', { name: 'Delete…' }).click();
    const confirm = page.getByRole('alertdialog', { name: 'Delete 1 issue?' });
    await expect(confirm).toBeVisible();
    saved = bulkWrite(page, 'DELETE');
    await confirm.getByRole('button', { name: 'Delete 1 issue' }).click();
    await saved;
    await expect(row(page, a)).toHaveCount(0);
    await expect(row(page, b)).toBeVisible();
});

test('list: right-click a selected row edits the whole selection; an unselected row only itself', async ({ page }) => {
    const [a, b, c] = [uid('ctx-sel-A'), uid('ctx-sel-B'), uid('ctx-sel-C')];
    await boardWithIssues(page, uid('Context board'), [a, b, c]);

    await checkbox(page, a).check();
    await checkbox(page, b).check();

    // an unselected row: only that issue, and the selection stays
    const single = await rightClick(page, row(page, c), c);
    await expect(single.getByText('2 issues')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menu')).toHaveCount(0);
    await expect(checkbox(page, a)).toBeChecked();
    await expect(checkbox(page, b)).toBeChecked();
    await expect(checkbox(page, c)).not.toBeChecked();

    // a selected row: both selected issues
    const both = await rightClick(page, row(page, b), b);
    await expect(both.getByText('2 issues', { exact: true })).toBeVisible();
    const saved = bulkWrite(page);
    await pick(page, 'Assignee', 'Max Member');
    await saved;
    await expect(page.getByText('Updated 2 issues.').first()).toBeVisible();
    for (const title of [a, b]) await expect(row(page, title)).toContainText('Max Member');
    await expect(row(page, c)).toContainText('Unassigned');
    await expect(checkbox(page, a)).toBeChecked();
});

test('board: right-click a card to move it to another status', async ({ page }) => {
    const title = uid('ctx-card');
    const boardUrl = await boardWithIssues(page, uid('Context board'), [title]);
    await page.goto(`${boardUrl}?view=kanban`); // the list view is remembered per board
    await expect(card(page, title, 'To Do')).toBeVisible();

    // column headers keep the browser's own menu
    await column(page, 'To Do').getByRole('heading', { level: 3 }).click({ button: 'right' });
    await expect(page.getByRole('menu')).toHaveCount(0);

    await rightClick(page, card(page, title), title);
    const saved = bulkWrite(page);
    await pick(page, 'Status', /^In Progress$/i);
    await saved;
    await expect(card(page, title, 'In Progress')).toBeVisible();
    await expect(card(page, title, 'To Do')).toHaveCount(0);

    // Open opens the issue dialog
    await rightClick(page, card(page, title), title);
    await menu(page).getByRole('menuitem', { name: 'Open', exact: true }).click();
    await expect(issueDialog(page)).toBeVisible();
});

test('viewers only get Open and the copy entries', async ({ page, memberPage }) => {
    const title = uid('ctx-viewer');
    const project = await createBlankProject(page, SEED.workspace);
    await createCard(page, title);
    const response = await request(page, 'POST', `/workspaces/${SEED.workspace}/projects/${project.slug}/members`, { user_id: await memberId(page), role: 'viewer' });
    expect(response.status(), `add viewer: ${await response.text()}`).toBeLessThan(400);

    const boardUrl = `/workspaces/${SEED.workspace}/projects/${project.slug}/boards/main-board`;
    for (const view of ['kanban', 'list']) {
        await memberPage.goto(`${boardUrl}?view=${view}`);
        const scope = view === 'list' ? row(memberPage, title) : card(memberPage, title);
        const opened = await rightClick(memberPage, scope, title);
        await expect(opened.getByRole('menuitem')).toHaveText(['Open', 'Copy link', 'Copy key']);
        await memberPage.keyboard.press('Escape');
        await expect(memberPage.getByRole('menu')).toHaveCount(0);
    }

    await rightClick(memberPage, row(memberPage, title), title);
    await menu(memberPage).getByRole('menuitem', { name: 'Open', exact: true }).click();
    await expect(issueDialog(memberPage)).toBeVisible();
});
