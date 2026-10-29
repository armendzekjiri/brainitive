import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { REMOTE } from '../env';
import { card, column, createBoard, createCard } from '../helpers/boards';
import { pageProps } from '../helpers/helpdesk';
import { liveServerAvailable, liveTab, optInLive } from '../helpers/live';
import { projectSectionAdd } from '../helpers/leads-plans-search';

/**
 * Live boards + plans (BRAI-152): Erin (`page`) changes cards and a Gantt plan, Max (`memberPage`)
 * keeps the same board / plan open — his view follows without a reload (a marker on `window`
 * survives), his search filter holds, and a pending Gantt edit of his is never overwritten.
 */

const LIVE = { timeout: 10_000 };

async function xsrf(page: Page): Promise<string> {
    return decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
}

/** A board write as the signed-in user, like the board's own forms (setup shortcut for Erin). */
async function write(page: Page, method: 'POST' | 'PATCH' | 'DELETE', url: string, data?: Record<string, unknown>) {
    const response = await page.request.fetch(url, {
        method,
        headers: { 'X-XSRF-TOKEN': await xsrf(page), 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
        data,
        maxRedirects: 0,
    });
    expect(response.status(), `${method} ${url}`).toBe(302);
}

async function issueId(page: Page, boardUrl: string, title: string): Promise<number> {
    const items = (await (await page.request.get(`${boardUrl}/items`)).json()) as { id: number; title: string }[];
    const found = items.find((i) => i.title === title);
    expect(found, `issue ${title}`).toBeTruthy();
    return found!.id;
}

/** Open a page that subscribes `channel`, wait until it is live, and mark the window (no reloads). */
async function openLive(page: Page, url: string, channel: (props: Record<string, any>) => string): Promise<void> {
    await page.goto(url);
    const name = channel(await pageProps(page));
    await expect.poll(() => liveTab(page).status(name), { message: `${name} is live`, timeout: 15_000 }).toBe('live');
    await page.evaluate(() => { (window as unknown as { __liveMarker: number }).__liveMarker = 1; });
}

const notReloaded = (page: Page) => page.evaluate(() => (window as unknown as { __liveMarker?: number }).__liveMarker === 1);

const listRow = (page: Page, title: string): Locator => page.getByRole('row').filter({ hasText: title });

test.describe('live boards + plans', () => {
    // the Gantt journey holds Max's save back with route(); in WebKit a controlling service worker
    // sends requests past Playwright's routing. Nothing here needs the worker.
    test.use({ serviceWorkers: 'block' });

    test.skip(REMOTE, 'needs the local realtime server');
    test.skip(!liveServerAvailable(), 'run `npm run collab:install` first');

    test('kanban: Erin creates, moves, edits and deletes cards — Max’s open board follows, his search filter holds', async ({ page, memberPage }) => {
        await optInLive(page);
        await optInLive(memberPage);
        const boardUrl = await createBoard(page, uid('Live board'));
        await openLive(page, boardUrl, (props) => `board.${props.board.id}`);
        await openLive(memberPage, boardUrl, (props) => `board.${props.board.id}`);
        const { board } = await pageProps<{ board: { columns: { id: number; name: string }[] } }>(page);
        const columnId = (name: string) => board.columns.find((c) => c.name === name)!.id;

        // created through the UI → appears in Max's To Do
        const title = uid('live-card');
        await createCard(page, title);
        await expect(card(memberPage, title, 'To Do')).toBeVisible(LIVE);

        // dragged to In Progress → moves there for Max too
        await card(page, title).dragTo(column(page, 'In Progress'));
        await expect(card(page, title, 'In Progress')).toBeVisible();
        await expect(card(memberPage, title, 'In Progress')).toBeVisible(LIVE);
        await expect(card(memberPage, title, 'To Do')).toHaveCount(0);

        // edited → the new title shows
        const id = await issueId(page, boardUrl, title);
        await write(page, 'PATCH', `${boardUrl}/items/${id}`, { title: `${title} edited` });
        await expect(card(memberPage, `${title} edited`, 'In Progress')).toBeVisible(LIVE);

        // Max searches; Erin's matching card appears, the other one only once it matches too
        const keyword = uid('kw');
        await memberPage.getByPlaceholder('Search board').fill(keyword);
        await expect(card(memberPage, `${title} edited`)).toHaveCount(0);
        const otherTitle = uid('other');
        const fetched = memberPage.waitForResponse((r) => new URL(r.url()).pathname.endsWith('/cards') && r.ok());
        await write(page, 'POST', `${boardUrl}/items`, { title: otherTitle, column_id: columnId('To Do') });
        await fetched; // Max's board has it — filtered out
        await write(page, 'POST', `${boardUrl}/items`, { title: `${keyword} match`, column_id: columnId('To Do') });
        await expect(card(memberPage, `${keyword} match`, 'To Do')).toBeVisible(LIVE);
        await expect(card(memberPage, otherTitle)).toHaveCount(0);
        const otherId = await issueId(page, boardUrl, otherTitle);
        await write(page, 'PATCH', `${boardUrl}/items/${otherId}`, { title: `${otherTitle} ${keyword}` });
        await expect(card(memberPage, `${otherTitle} ${keyword}`, 'To Do')).toBeVisible(LIVE);

        // deleted → gone (the filter cleared, everything else is still there)
        await memberPage.getByPlaceholder('Search board').fill('');
        await expect(card(memberPage, `${title} edited`, 'In Progress')).toBeVisible();
        await write(page, 'DELETE', `${boardUrl}/items/${id}`);
        await expect(card(memberPage, `${title} edited`)).toHaveCount(0, LIVE);
        await expect(card(memberPage, `${keyword} match`, 'To Do')).toBeVisible();

        // a new column (board structure) reloads the columns in place
        await write(page, 'POST', `${boardUrl}/columns`, { name: 'Review' });
        await expect(column(memberPage, 'Review')).toBeVisible(LIVE);

        expect(await notReloaded(memberPage), 'Max’s board never reloaded').toBe(true);
    });

    test('list view: edits, status changes and deletes show up in the open list', async ({ page, memberPage }) => {
        await optInLive(memberPage);
        const boardUrl = await createBoard(page, uid('Live list'));
        await page.goto(boardUrl); // a full load: data-page holds this board
        const { board } = await pageProps<{ board: { id: number; columns: { id: number; name: string }[] } }>(page);
        const title = uid('live-row');
        await write(page, 'POST', `${boardUrl}/items`, { title, column_id: board.columns[0].id });

        await openLive(memberPage, `${boardUrl}?view=list`, (props) => `board.${props.board.id}`);
        await expect(listRow(memberPage, title)).toBeVisible();

        const id = await issueId(page, boardUrl, title);
        await write(page, 'PATCH', `${boardUrl}/items/${id}`, { title: `${title} renamed` });
        await expect(listRow(memberPage, `${title} renamed`)).toBeVisible(LIVE);

        const done = board.columns.find((c) => c.name === 'Done')!.id;
        await write(page, 'PATCH', `${boardUrl}/items/${id}/move`, { column_id: done, position: 0 });
        await expect(listRow(memberPage, `${title} renamed`)).toContainText('Done', LIVE);

        await write(page, 'DELETE', `${boardUrl}/items/${id}`);
        await expect(listRow(memberPage, `${title} renamed`)).toHaveCount(0, LIVE);
        expect(await notReloaded(memberPage)).toBe(true);
    });

    test('Gantt: Erin’s new row and moved bar reach Max; his pending edit gets the conflict, never overwritten', async ({ page, memberPage }) => {
        await optInLive(page);
        await optInLive(memberPage);
        const planName = uid('live-plan');
        await page.goto(urls.project());
        await projectSectionAdd(page, 'Plans').click();
        const dialog = page.getByRole('dialog', { name: 'Create Plan' });
        await dialog.getByPlaceholder('e.g., Roadmap 2027').fill(planName);
        await dialog.getByRole('button', { name: 'Create', exact: true }).click();
        await expect(page).toHaveURL(/\/plans\/[^/]+$/);
        const planUrl = new URL(page.url()).pathname;
        await openLive(page, planUrl, (props) => `plan.${props.plan.id}`);
        await openLive(memberPage, planUrl, (props) => `plan.${props.plan.id}`);

        // Erin: a row with a bar
        const rowName = uid('row');
        await page.getByRole('toolbar', { name: 'Plan tools' }).getByRole('button', { name: 'Row', exact: true }).click();
        const nameInput = page.getByRole('textbox', { name: 'name', exact: true });
        await nameInput.fill(rowName);
        await nameInput.press('Enter');
        const row = page.getByText(rowName, { exact: true });
        await expect(row).toBeVisible();
        const grid = (await row.boundingBox())!;
        const addRow = (await page.getByRole('button', { name: /^Add row/ }).boundingBox())!;
        const gridRight = addRow.x + addRow.width;
        const y = grid.y + grid.height / 2;
        let saved = page.waitForResponse((r) => r.url().includes('/document') && r.request().method() === 'PUT' && r.ok());
        await page.mouse.move(gridRight + 60, y);
        await page.mouse.down();
        await page.mouse.move(gridRight + 160, y, { steps: 8 });
        await page.mouse.move(gridRight + 260, y, { steps: 8 });
        await page.mouse.up();
        const bar = (p: Page) => p.locator(`[data-bar-id][title^="${rowName}:"]`);
        await expect(bar(page)).toBeVisible();
        await saved;

        await expect(memberPage.getByText(rowName, { exact: true })).toBeVisible(LIVE);
        await expect(bar(memberPage)).toHaveAttribute('title', (await bar(page).getAttribute('title'))!, LIVE);
        await expect(memberPage.getByText(/^Updated by /)).toBeVisible();

        // Erin moves the bar → Max's bar moves too
        const moveBar = async () => {
            const box = (await bar(page).boundingBox())!;
            const before = await bar(page).getAttribute('title');
            const done = page.waitForResponse((r) => r.url().includes('/document') && r.request().method() === 'PUT' && r.ok());
            // to the right while there's room, else to the left: a pointer dragged out of the window
            // ends the gesture differently per browser (Firefox lost the bar off-screen)
            const x = box.x + box.width / 2;
            const dir = x + 200 < page.viewportSize()!.width ? 1 : -1;
            await page.mouse.move(x, box.y + box.height / 2);
            await page.mouse.down();
            await page.mouse.move(x + dir * 80, box.y + box.height / 2, { steps: 10 });
            await page.mouse.move(x + dir * 160, box.y + box.height / 2, { steps: 10 });
            await page.mouse.up();
            await expect(bar(page)).not.toHaveAttribute('title', before!);
            await done;
            return (await bar(page).getAttribute('title'))!;
        };
        const moved = await moveBar();
        await expect(bar(memberPage)).toHaveAttribute('title', moved, LIVE);

        // Max starts an edit whose save is still on its way when Erin moves the bar again
        let release!: () => void;
        const held = new Promise<void>((resolve) => { release = resolve; });
        await memberPage.route('**/plans/*/document', async (route) => {
            if (route.request().method() === 'PUT') await held;
            await route.continue();
        });
        const maxSaving = memberPage.waitForRequest((r) => r.url().includes('/document') && r.method() === 'PUT');
        const maxRow = uid('max-row');
        await memberPage.getByRole('toolbar', { name: 'Plan tools' }).getByRole('button', { name: 'Row', exact: true }).click();
        const maxInput = memberPage.getByRole('textbox', { name: 'name', exact: true });
        await maxInput.fill(maxRow);
        await maxInput.press('Enter');
        await maxSaving;

        saved = page.waitForResponse((r) => r.url().includes('/document') && r.request().method() === 'PUT' && r.ok());
        const movedAgain = await moveBar();
        await saved;
        release();

        const conflict = memberPage.getByRole('alert').filter({ hasText: 'changed this plan while you were editing' });
        await expect(conflict).toBeVisible(LIVE);
        await expect(memberPage.getByText(maxRow, { exact: true }), 'Max’s edit is still there').toBeVisible();
        await expect(bar(memberPage)).not.toHaveAttribute('title', movedAgain);

        // "Load latest" takes Erin's version
        await memberPage.unroute('**/plans/*/document');
        await conflict.getByRole('button', { name: 'Load latest' }).click();
        await expect(bar(memberPage)).toHaveAttribute('title', movedAgain);
        await expect(memberPage.getByText(maxRow, { exact: true })).toHaveCount(0);
        expect(await notReloaded(memberPage)).toBe(true);
    });
});
