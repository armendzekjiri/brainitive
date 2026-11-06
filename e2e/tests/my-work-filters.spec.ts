import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createBoard, postJson } from '../helpers/boards';
import { createTaskList } from '../helpers/custom-sidebar';
import { pageProps } from '../helpers/helpdesk';
import { SEED } from '../env';

/**
 * My Work's filter bar (v1.51.0): search, Source, Priority, Labels, Status (Open by default) and Sort
 * narrow the list in the browser and live in the URL, so a reload keeps them. Every task here carries
 * one unique token (parallel specs add work for the same user), and the search box narrows to it.
 */
const xsrf = async (page: Page) => decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value);

async function setup(page: Page, token: string) {
    const labelName = uid('mwf-label');
    const { id: labelId } = await postJson<{ id: number }>(page, `${urls.project()}/labels`, { name: labelName, color: '#ef4444' });
    const boardUrl = await createBoard(page, uid('My work filters'));
    await page.goto(boardUrl); // fresh props (the board was opened client-side)
    const { board, priorities } = await pageProps<{
        board: { columns: { id: number; is_done_column: boolean }[] };
        priorities: { id: number; name: string }[];
    }>(page);
    const open = board.columns.find((c) => !c.is_done_column)!.id;
    const done = board.columns.find((c) => c.is_done_column)!.id;
    const high = priorities.find((p) => p.name === 'High')!.id;

    const issue = async (title: string, data: Record<string, unknown>) => {
        const response = await page.request.post(`${boardUrl}/items`, {
            headers: { 'X-XSRF-TOKEN': await xsrf(page), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
            data: { title, ...data },
            maxRedirects: 0,
        });
        expect(response.status(), `create ${title}`).toBe(302);
    };
    await issue(`${token} alpha`, { column_id: open, priority_id: high, label_ids: [labelId] });
    await issue(`${token} beta`, { column_id: open });
    await issue(`${token} gamma`, { column_id: done });

    const list = uid('mwf-list');
    await createTaskList(page, SEED.project, list);
    const task = await page.request.post(`${urls.project()}/task-lists/${list}/items`, {
        headers: { 'X-XSRF-TOKEN': await xsrf(page), Accept: 'text/html' },
        form: { title: `${token} delta` },
    });
    expect(task.ok(), `create task: ${task.status()}`).toBeTruthy();

    return { labelName };
}

const rowTitles = (page: Page) => page.locator('section a[href]');

test.describe('my work filters', () => {
    test('search, source, priority, labels, status and sort narrow the list and survive a reload', async ({ page }) => {
        test.slow(); // a board, a label, a task list and four tasks first
        const token = uid('mwf');
        const { labelName } = await setup(page, token);
        const titles = async () => (await rowTitles(page).allInnerTexts()).map((t) => t.trim()).filter((t) => t.startsWith(token));

        await page.goto('/my-work?scope=created');
        const bar = page.getByRole('toolbar', { name: 'Filter my work' });
        await bar.getByRole('searchbox', { name: 'Search my work' }).fill(token);

        // Open by default: the done issue is hidden
        await expect.poll(titles).toEqual(expect.arrayContaining([`${token} alpha`, `${token} beta`, `${token} delta`]));
        expect(await titles()).not.toContain(`${token} gamma`);
        await expect(page.getByTestId('my-work-result-count')).toHaveText(/^3 of \d+ tasks$/);

        // Status: Done → only the done one; All → all four
        await bar.getByRole('button', { name: /^Status:/ }).click();
        await page.getByRole('menuitemradio', { name: 'Done' }).click();
        await expect.poll(titles).toEqual([`${token} gamma`]);
        await bar.getByRole('button', { name: /^Status:/ }).click();
        await page.getByRole('menuitemradio', { name: 'All' }).click();
        await expect.poll(async () => (await titles()).length).toBe(4);
        await bar.getByRole('button', { name: /^Status:/ }).click();
        await page.getByRole('menuitemradio', { name: 'Open' }).click();

        // Source: task lists only
        await bar.getByRole('button', { name: /^Source:/ }).click();
        await page.getByRole('menuitemradio', { name: 'Task lists' }).click();
        await expect.poll(titles).toEqual([`${token} delta`]);
        await bar.getByRole('button', { name: /^Source:/ }).click();
        await page.getByRole('menuitemradio', { name: 'All sources' }).click();

        // Priority High → only alpha; kept in the URL and after a reload
        await bar.getByRole('button', { name: /^Priority:/ }).click();
        await page.getByRole('menuitemcheckbox', { name: 'High', exact: true }).click();
        await page.keyboard.press('Escape');
        await expect.poll(titles).toEqual([`${token} alpha`]);
        await expect(page).toHaveURL(/priority=high/);
        await expect(page).toHaveURL(/scope=created/);
        await page.reload();
        await expect(page.getByRole('searchbox', { name: 'Search my work' })).toHaveValue(token);
        await expect.poll(titles).toEqual([`${token} alpha`]);

        // Clear filters resets everything (search included)
        await page.getByRole('button', { name: 'Clear filters' }).click();
        await expect(page.getByRole('searchbox', { name: 'Search my work' })).toHaveValue('');
        await expect(page).not.toHaveURL(/priority=|q=/);

        // Labels
        await bar.getByRole('searchbox', { name: 'Search my work' }).fill(token);
        await bar.getByRole('button', { name: /^Labels:/ }).click();
        await page.getByRole('menuitemcheckbox', { name: labelName }).click();
        await page.keyboard.press('Escape');
        await expect.poll(titles).toEqual([`${token} alpha`]);
        await page.getByRole('button', { name: 'Clear filters' }).click();

        // Sort by priority: the High one first
        await bar.getByRole('searchbox', { name: 'Search my work' }).fill(token);
        await bar.getByRole('button', { name: /^Sort by:/ }).click();
        await page.getByRole('menuitemradio', { name: 'Priority' }).click();
        await expect(page).toHaveURL(/sort=priority/);
        await expect.poll(async () => (await titles())[0]).toBe(`${token} alpha`);

        // nothing matches → the empty state
        await bar.getByRole('searchbox', { name: 'Search my work' }).fill(`${token} nothing-like-this`);
        await expect(page.getByText('No tasks match these filters')).toBeVisible();
    });

    test('on a phone the filters live in one popover', async ({ page }) => {
        test.slow();
        const token = uid('mwf');
        await setup(page, token);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`/my-work?scope=created&q=${token}`);

        const titles = async () => (await rowTitles(page).allInnerTexts()).map((t) => t.trim()).filter((t) => t.startsWith(token));
        await expect.poll(async () => (await titles()).length).toBe(3);
        await page.getByRole('button', { name: 'Filters', exact: true }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Done', exact: true }).click();
        await expect.poll(titles).toEqual([`${token} gamma`]);
        await expect(page.getByRole('button', { name: 'Filters (1)' })).toBeVisible();
        // no horizontal scroll at phone width
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });
});
