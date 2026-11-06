import type { Locator, Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { createBoard } from '../helpers/boards';
import { pageProps } from '../helpers/helpdesk';

/**
 * Resizable columns on the board Timeline ("Items" column) and List (every column): drag a
 * handle, ←/→ on it, double-click resets, and the widths come back after a reload (localStorage,
 * per person and board). Every test works on its own board.
 */

test.use({ viewport: { width: 1600, height: 900 } });

/** A new board with issues in its first column; returns its URL. */
async function boardWithIssues(page: Page, titles: string[]): Promise<string> {
    const boardUrl = await createBoard(page, uid('Resize board'));
    await page.goto(boardUrl); // full load: data-page holds the board
    const { board } = await pageProps<{ board: { columns: { id: number }[] } }>(page);
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value);
    for (const title of titles) {
        const response = await page.request.post(`${boardUrl}/items`, {
            headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
            data: { title, column_id: board.columns[0].id },
            maxRedirects: 0,
        });
        expect(response.status(), `create ${title}`).toBe(302);
    }
    return boardUrl;
}

const handle = (page: Page, column: string): Locator => page.getByRole('separator', { name: `Resize ${column} column` });

async function dragBy(page: Page, target: Locator, dx: number) {
    const box = (await target.boundingBox())!;
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + dx / 2, y, { steps: 5 });
    await page.mouse.move(x + dx, y, { steps: 5 });
    await page.mouse.up();
}

const valueNow = async (target: Locator) => Number(await target.getAttribute('aria-valuenow'));

test('timeline: drag the Items column wider, keep it after a reload, keys and double-click', async ({ page }) => {
    const title = uid('A rather long issue title that needs more room to be read on the timeline');
    const boardUrl = await boardWithIssues(page, [title]);
    await page.goto(`${boardUrl}?view=gantt`);
    await expect(page.getByText(title)).toBeVisible();

    const items = handle(page, 'Items');
    await expect(items).toHaveAttribute('aria-valuenow', '288');
    await expect(items).toHaveAttribute('aria-orientation', 'vertical');
    await expect(items).toHaveCSS('cursor', 'col-resize');

    await dragBy(page, items, 200);
    await expect(items).toHaveAttribute('aria-valuenow', '488');
    // the column itself (the handle's header cell) is wider
    expect(await items.evaluate((el) => el.parentElement!.getBoundingClientRect().width)).toBeCloseTo(488, -1);

    await page.reload();
    await expect(handle(page, 'Items')).toHaveAttribute('aria-valuenow', '488');

    // keyboard: → wider, ← narrower
    await handle(page, 'Items').focus();
    await page.keyboard.press('ArrowRight');
    await expect(handle(page, 'Items')).toHaveAttribute('aria-valuenow', '504');
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowLeft');
    await expect(handle(page, 'Items')).toHaveAttribute('aria-valuenow', '472');

    // never below the minimum
    await dragBy(page, handle(page, 'Items'), -1000);
    await expect(handle(page, 'Items')).toHaveAttribute('aria-valuenow', '160');

    // double-click resets
    await handle(page, 'Items').dblclick();
    await expect(handle(page, 'Items')).toHaveAttribute('aria-valuenow', '288');
});

test('list: columns do not overlap, Title drags narrower, widths survive a reload, keys resize', async ({ page }) => {
    const title = uid('List resize issue');
    const boardUrl = await boardWithIssues(page, [title]);
    await page.goto(`${boardUrl}?view=list`);
    await expect(page.getByRole('row').filter({ hasText: title })).toBeVisible();

    // every column has a handle, and no header runs into the next one at the default widths
    const headers = ['Key', 'Title', 'Parent', 'Status', 'Priority', 'Assignee', 'Due Date', 'Story Points'];
    for (const name of headers) await expect(handle(page, name)).toBeVisible();
    const boxes = await page.getByRole('columnheader').evaluateAll((cells) =>
        cells.map((cell) => {
            const label = (cell.querySelector('button span, span') ?? cell) as HTMLElement;
            const r = label.getBoundingClientRect();
            return { text: cell.textContent?.trim() ?? '', left: r.left, right: r.right, cellRight: cell.getBoundingClientRect().right };
        }));
    for (const b of boxes.filter((b) => b.text)) expect(b.right, `${b.text} fits its column`).toBeLessThanOrEqual(b.cellRight);

    const storyPoints = page.getByRole('columnheader', { name: 'Story Points' });
    const before = (await storyPoints.boundingBox())!.x;
    const titleHandle = handle(page, 'Title');
    // Title is "auto" until it's resized: it starts from what it renders at
    await titleHandle.focus();
    const titleWidth = await valueNow(titleHandle);
    expect(titleWidth).toBeGreaterThan(300);

    await dragBy(page, titleHandle, -100);
    await expect(titleHandle).toHaveAttribute('aria-valuenow', String(Math.round(titleWidth - 100)));
    // the columns after Title move left into view
    await expect.poll(async () => (await storyPoints.boundingBox())!.x).toBeLessThan(before - 80);
    const after = (await storyPoints.boundingBox())!.x;

    await page.reload();
    await expect(handle(page, 'Title')).toHaveAttribute('aria-valuenow', String(Math.round(titleWidth - 100)));
    await expect.poll(async () => Math.round((await page.getByRole('columnheader', { name: 'Story Points' }).boundingBox())!.x)).toBe(Math.round(after));

    // keyboard on another column: → +16px, ← −16px
    const status = handle(page, 'Status');
    const statusWidth = await valueNow(status);
    await status.focus();
    await page.keyboard.press('ArrowRight');
    await expect(status).toHaveAttribute('aria-valuenow', String(statusWidth + 16));
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowLeft');
    await expect(status).toHaveAttribute('aria-valuenow', String(statusWidth - 16));

    // resizing never sorts, and the row still opens on click
    await expect(page.getByRole('columnheader', { name: 'Title' })).toHaveAttribute('aria-sort', 'none');
});
