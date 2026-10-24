import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { SEED } from '../env';
import { card, column, createBoard, createCard, closeIssue, openCard, openDeepLink, openIssueId, postJson, sidebarField } from '../helpers/boards';

/**
 * Boards and issues (BRAI-96): cards, the issue dialog, filters, layouts, saved views and board
 * settings. Tests make their own cards and boards (uid names); the seeded WEB-1..3 are only read.
 */

/** Pick an option of a Radix select. */
async function choose(page: Page, trigger: Locator, option: string | RegExp) {
    await trigger.click();
    await page.getByRole('option', { name: option }).click();
}

/** Wait for an Inertia write to the issue (PATCH …/items/{id}[/labels|/move]). */
const itemWrite = (page: Page, method: 'PATCH' | 'POST' = 'PATCH') =>
    page.waitForResponse((r) => r.request().method() === method && /\/boards\/[^/]+\/items\/\d+/.test(r.url()));

const searchParam = (page: Page, key: string) => new URL(page.url()).searchParams.get(key);

/*
 * Journeys that create cards in the seeded project run one after another: creating two issues in
 * the same project at the same time fails (see the fixme'd race test below).
 */
test.describe('cards and the issue dialog', () => {
    test.describe.configure({ mode: 'default' });

    test('create a card in To Do from the column', async ({ page }) => {
        const title = uid('card');
        await page.goto(urls.board());

        const created = await createCard(page, title);
        await expect(created).toContainText(/WEB-\d+/);

        await page.reload();
        await expect(card(page, title, 'To Do')).toBeVisible();
    });

    test('the board has one Create button (top bar); it creates the card on this board', async ({ page }) => {
        // the board header used to have a second Create next to the settings gear
        const title = uid('topbar-card');
        await page.goto(urls.board());
        // only the top bar's Create; the board title row (settings gear) has none. The columns keep their "+ Create".
        const titleRow = page.getByRole('heading', { level: 1, name: 'Main Board' }).locator('..');
        await expect(titleRow.getByRole('link', { name: /board settings/i }).or(titleRow.getByTitle('Board settings'))).toBeVisible();
        await expect(titleRow.getByRole('button', { name: 'Create', exact: true })).toHaveCount(0);
        const create = page.locator('header').getByRole('button', { name: 'Create', exact: true });
        await expect(create).toHaveCount(1);

        await create.click();
        const dialog = page.getByRole('dialog', { name: /^Create/ });
        await dialog.getByPlaceholder('What needs to be done?').fill(title);
        await dialog.getByRole('button', { name: /^Create (Task|Issue)/ }).click();
        await expect(dialog).toBeHidden();

        await page.reload();
        await expect(card(page, title, 'To Do')).toBeVisible();
    });

    test('a #item link to a card created after the page loaded opens it in the dialog', async ({ page }) => {
        // regression BRAI-97: the hash handler only knew the cards of the first load
        const title = uid('fresh-link');
        await page.goto(urls.board());
        await createCard(page, title);
        const id = await openIssueId(await openCard(page, title));
        await closeIssue(page);

        await page.evaluate((issue) => { window.location.hash = `item-${issue}`; }, id);
        await expect(page.getByRole('dialog', { name: new RegExp(`^Edit [A-Z]+-\\d+: ${title}`) })).toBeVisible();
        expect(new URL(page.url()).pathname).toBe(urls.board()); // stayed on the board
    });

    test('edit the title and the description; both persist', async ({ page }) => {
        const title = uid('edit');
        const renamed = `${title} renamed`;
        const description = `Description ${uid('desc')}`;
        await page.goto(urls.board());
        await createCard(page, title);

        let dialog = await openCard(page, title);
        const id = await openIssueId(dialog);

        await dialog.getByRole('heading', { level: 1, name: title }).click();
        const titleInput = dialog.getByPlaceholder('Issue title');
        await titleInput.fill(renamed);
        const titleSaved = itemWrite(page);
        await titleInput.press('Enter');
        await titleSaved;
        await expect(dialog.getByRole('heading', { level: 1, name: renamed })).toBeVisible();

        await dialog.getByText('Add a description…').dblclick();
        const editor = dialog.locator('[contenteditable="true"][aria-placeholder="Add a description…"]');
        await editor.click();
        await page.keyboard.type(description);
        const descriptionSaved = itemWrite(page);
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        await descriptionSaved;
        await expect(dialog.getByText(description)).toBeVisible();

        await closeIssue(page);
        await expect(card(page, renamed)).toBeVisible();

        // persisted: a fresh load of the board's deep link shows both
        dialog = await openDeepLink(page, urls.board(), id);
        await expect(dialog.getByRole('heading', { level: 1, name: renamed })).toBeVisible();
        await expect(dialog.getByText(description)).toBeVisible();
    });

    test('drag a card to In Progress; it stays there after a reload', async ({ page }) => {
        const title = uid('drag');
        await page.goto(urls.board());
        const created = await createCard(page, title);

        const moved = itemWrite(page);
        await created.dragTo(column(page, 'In Progress'));
        await moved;
        await expect(card(page, title, 'In Progress')).toBeVisible();
        await expect(card(page, title, 'To Do')).toHaveCount(0);

        await page.reload();
        await expect(card(page, title, 'In Progress')).toBeVisible();
    });

    test('move a card to Done with the status control in the issue dialog', async ({ page }) => {
        const title = uid('status');
        await page.goto(urls.board());
        await createCard(page, title);

        const dialog = await openCard(page, title);
        const status = dialog.getByRole('combobox', { name: 'Status' });
        await expect(status).toHaveText('To Do');
        const moved = itemWrite(page);
        await choose(page, status, 'Done');
        await moved;
        await expect(status).toHaveText('Done');
        await closeIssue(page);

        await page.reload();
        await expect(card(page, title, 'Done')).toBeVisible();
        await expect(card(page, title, 'To Do')).toHaveCount(0);
    });

    test('comment on an issue; the comment is listed after a reload', async ({ page }) => {
        const title = uid('comment');
        const text = `Looks good ${uid('c')}`;
        await page.goto(urls.board());
        await createCard(page, title);

        let dialog = await openCard(page, title);
        const id = await openIssueId(dialog);
        await dialog.locator('[contenteditable="true"][aria-placeholder="Add a comment…"]').click();
        await page.keyboard.type(text);
        const posted = itemWrite(page, 'POST');
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        await posted;
        await expect(dialog.getByText(text)).toBeVisible();

        dialog = await openDeepLink(page, urls.board(), id);
        await expect(dialog.getByText(text)).toBeVisible();
        await expect(dialog.getByText('Erin Owner', { exact: true }).first()).toBeVisible();
        await closeIssue(page);
        await expect(card(page, title).getByTitle('1 comment(s)')).toBeVisible();
    });

    test('assign to Max Member and set the priority; both persist', async ({ page }) => {
        const title = uid('props');
        await page.goto(urls.board());
        await createCard(page, title);

        let dialog = await openCard(page, title);
        const id = await openIssueId(dialog);

        const assignee = sidebarField(dialog, 'Assignee').getByRole('combobox');
        let saved = itemWrite(page);
        await choose(page, assignee, 'Max Member');
        await saved;
        await expect(assignee).toHaveText(/Max Member/);

        const priority = sidebarField(dialog, 'Priority').getByRole('combobox');
        saved = itemWrite(page);
        await choose(page, priority, 'Highest');
        await saved;
        await expect(priority).toHaveText(/Highest/);
        await closeIssue(page);

        await expect(card(page, title).getByTitle(/^Assignee: Max Member/)).toBeVisible();

        dialog = await openDeepLink(page, urls.board(), id);
        await expect(sidebarField(dialog, 'Assignee').getByRole('combobox')).toHaveText(/Max Member/);
        await expect(sidebarField(dialog, 'Priority').getByRole('combobox')).toHaveText(/Highest/);
    });

    test('put a label on an issue, then filter the board by it', async ({ page }) => {
        const title = uid('labelled');
        const label = uid('label');
        await page.goto(urls.board());
        // the label exists already (both UI ways to create one are broken, see the fixme tests below)
        const { id: labelId } = await postJson<{ id: number }>(page, `${urls.project()}/labels`, { name: label, color: '#22c55e' });
        await page.reload();
        await createCard(page, title);

        const dialog = await openCard(page, title);
        await sidebarField(dialog, 'Labels').getByRole('button').click();
        await page.getByPlaceholder('Search labels…').fill(label);
        const saved = itemWrite(page);
        await page.getByRole('button', { name: label, exact: true }).click();
        await saved;
        await expect(sidebarField(dialog, 'Labels').getByRole('button').first()).toContainText(label);
        await closeIssue(page);
        await expect(card(page, title)).toContainText(label);

        // the Filter popover's label chip shows only that card
        await page.getByRole('button', { name: 'Filter', exact: true }).click();
        await page.getByRole('button', { name: label, exact: true }).click();
        await page.keyboard.press('Escape');
        await expect(page).toHaveURL(new RegExp(`[?&]labels=${labelId}(&|$)`));
        await expect(card(page, title)).toBeVisible();
        await expect(card(page, 'Homepage hero')).toHaveCount(0);

        await page.reload();
        await expect(card(page, title)).toContainText(label);
        await expect(card(page, 'Homepage hero')).toHaveCount(0);
    });

    test('the member (project editor) sees the board and creates a card', async ({ memberPage }) => {
        const title = uid('member');
        await memberPage.goto(urls.board());
        await expect(card(memberPage, 'Pricing page copy')).toBeVisible();

        const created = await createCard(memberPage, title);
        await expect(created).toContainText(/WEB-\d+/);
        await memberPage.reload();
        await expect(card(memberPage, title, 'To Do')).toBeVisible();
    });
});

test.describe('board filters, layouts and views', () => {
    test('search narrows the cards and is kept in the URL', async ({ page }) => {
        await page.goto(urls.board());
        await expect(card(page, 'Checkout bug')).toBeVisible();

        const search = page.getByPlaceholder('Search board');
        await search.fill('Homepage');
        await expect(card(page, 'Homepage hero')).toBeVisible();
        await expect(card(page, 'Checkout bug')).toHaveCount(0);
        await expect(page).toHaveURL(/[?&]q=Homepage/);

        // a shared link keeps the filter
        await page.reload();
        await expect(search).toHaveValue('Homepage');
        await expect(card(page, 'Checkout bug')).toHaveCount(0);

        await search.fill('');
        await expect(card(page, 'Checkout bug')).toBeVisible();
        expect(searchParam(page, 'q')).toBeNull();
    });

    test('assignee filters: only my issues, a person, clear all', async ({ page }) => {
        await page.goto(urls.board());
        await expect(card(page, 'Pricing page copy')).toBeVisible();

        await page.getByRole('button', { name: 'Only my issues' }).click();
        await expect(page).toHaveURL(/[?&]assignee=me/);
        await expect(card(page, 'Checkout bug')).toBeVisible(); // Erin's
        await expect(card(page, 'Pricing page copy')).toHaveCount(0); // Max's
        await expect(card(page, 'Homepage hero')).toHaveCount(0); // nobody's
        await page.getByRole('button', { name: 'Only my issues' }).click();
        await expect(card(page, 'Pricing page copy')).toBeVisible();

        // the Filter popover: Assignee → Max Member
        await page.getByRole('button', { name: 'Filter', exact: true }).click();
        await page.getByRole('button', { name: 'Any user' }).click();
        await page.getByRole('button', { name: 'Max Member' }).click();
        await expect(page).toHaveURL(/[?&]assignee=user-\d+/);
        await expect(card(page, 'Pricing page copy')).toBeVisible();
        await expect(card(page, 'Checkout bug')).toHaveCount(0);

        await page.getByRole('button', { name: 'Clear all' }).click();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('button', { name: 'Filter', exact: true })).toBeVisible();
        await expect(card(page, 'Checkout bug')).toBeVisible();
        expect(searchParam(page, 'assignee')).toBeNull();
    });

    test('quick filters: Blocked, Recently updated + Due soon', async ({ page }) => {
        await page.goto(urls.board());
        await expect(card(page, 'Homepage hero')).toBeVisible();

        const blocked = page.getByRole('button', { name: 'Blocked', exact: true });
        await blocked.click();
        await expect(blocked).toHaveAttribute('aria-pressed', 'true');
        await expect(page).toHaveURL(/[?&]quick=blocked/);
        await expect(card(page, 'Homepage hero')).toHaveCount(0);
        await blocked.click();
        await expect(card(page, 'Homepage hero')).toBeVisible();

        // no due date on the seeded issues: Due soon hides them; quick filters add up (fixed order in the URL)
        await page.getByRole('button', { name: 'Due soon' }).click();
        await expect(card(page, 'Homepage hero')).toHaveCount(0);
        await page.getByRole('button', { name: 'Recently updated' }).click();
        await expect(page.getByRole('button', { name: 'Recently updated' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page).toHaveURL(/[?&]quick=recent(%2C|,)due(&|$)/);
        await expect(card(page, 'Homepage hero')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Filter (2)' })).toBeVisible();

        await page.getByRole('button', { name: 'Filter (2)' }).click();
        await page.getByRole('button', { name: 'Clear all' }).click();
        await page.keyboard.press('Escape');
        await expect(card(page, 'Homepage hero')).toBeVisible();
        expect(searchParam(page, 'quick')).toBeNull();
    });

    test('switch layouts: Board, List, Timeline, Calendar', async ({ page }) => {
        await page.goto(urls.board());
        const tab = (name: string) => page.getByRole('tab', { name, exact: true });
        await expect(tab('Board')).toHaveAttribute('aria-selected', 'true');

        await tab('List').click();
        await expect(tab('List')).toHaveAttribute('aria-selected', 'true');
        await expect(page).toHaveURL(/[?&]view=list/);
        await expect(page.getByRole('row').filter({ hasText: 'Homepage hero' })).toBeVisible();

        await tab('Timeline').click();
        await expect(page).toHaveURL(/[?&]view=gantt/);
        await expect(page.getByText('Homepage hero').first()).toBeVisible();

        await tab('Calendar').click();
        await expect(page).toHaveURL(/[?&]view=calendar/);
        const month = new Date().toLocaleDateString('en', { month: 'long', year: 'numeric' });
        await expect(page.getByRole('heading', { level: 2, name: month })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Today' })).toBeVisible();

        // the layout is remembered for this board
        await page.goto(urls.board());
        await expect(tab('Calendar')).toHaveAttribute('aria-selected', 'true');

        await tab('Board').click();
        await expect(card(page, 'Homepage hero')).toBeVisible();
        expect(searchParam(page, 'view')).toBeNull();
    });

    test('list view: sort by key, ascending → descending → off', async ({ page }) => {
        await page.goto(`${urls.board()}?view=list`);
        const rows = page.getByRole('row').filter({ has: page.getByRole('cell') });
        // the first seeded issue has the lowest key (don't rely on its number)
        await expect(rows.filter({ hasText: 'Homepage hero' })).toBeVisible();

        const keyHeader = page.getByRole('columnheader', { name: 'Key' });
        await keyHeader.getByRole('button').click();
        await expect(keyHeader).toHaveAttribute('aria-sort', 'ascending');
        await expect(page).toHaveURL(/[?&]sort=key/);
        await expect(rows.first()).toContainText('Homepage hero');

        await keyHeader.getByRole('button').click();
        await expect(keyHeader).toHaveAttribute('aria-sort', 'descending');
        await expect(page).toHaveURL(/[?&]sort=-key/);
        await expect(rows.last()).toContainText('Homepage hero');
        await expect(rows.first()).not.toContainText('Homepage hero');

        await keyHeader.getByRole('button').click();
        await expect(keyHeader).toHaveAttribute('aria-sort', 'none');
        expect(searchParam(page, 'sort')).toBeNull();
    });

    test('saved views: save, change, show all, open by default, delete', async ({ page }) => {
        const boardName = uid('Views board');
        const viewName = uid('Hero view');
        const boardUrl = await createBoard(page, boardName);

        const viewsButton = page.getByRole('button', { name: /^Views/ });
        await page.getByPlaceholder('Search board').fill('hero');
        await expect(page).toHaveURL(/[?&]q=hero/);

        // save the current setup as a named view
        await viewsButton.click();
        await page.getByRole('button', { name: 'Save current view as…' }).click();
        await page.getByPlaceholder('View name, e.g. My open bugs').fill(viewName);
        await page.getByRole('button', { name: 'Save view' }).click();
        await expect(page).toHaveURL(/[?&]bv=\d+/);
        const viewId = searchParam(page, 'bv');
        const activeView = page.getByRole('button', { name: new RegExp(`^${viewName}`) });
        await expect(activeView).toBeVisible();
        await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);

        // change a filter: the view has unsaved changes; save them into the view
        await page.getByRole('button', { name: 'Due soon' }).click();
        await expect(page.getByRole('button', { name: 'Save changes' })).toBeVisible();
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText(`“${viewName}” updated`)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Save changes' })).toHaveCount(0);

        // All issues: no view, no filters
        await activeView.click();
        await page.getByRole('button', { name: 'All issues' }).click();
        await expect(page).toHaveURL(/[?&]bv=0/);
        await expect(page.getByPlaceholder('Search board')).toHaveValue('');
        await expect(viewsButton).toBeVisible();

        // open by default: the plain board URL applies the view
        await viewsButton.click();
        await page.getByRole('button', { name: `Options for ${viewName}` }).click();
        await page.getByRole('menuitem', { name: 'Open by default' }).click();
        await expect(page.getByText(`“${viewName}” opens by default`)).toBeVisible();

        await page.goto(boardUrl);
        await expect(page).toHaveURL(new RegExp(`[?&]bv=${viewId}`));
        await expect(page).toHaveURL(/[?&]q=hero/);
        await expect(page).toHaveURL(/[?&]quick=due/);
        await expect(page.getByPlaceholder('Search board')).toHaveValue('hero');
        await expect(page.getByRole('button', { name: 'Due soon' })).toHaveAttribute('aria-pressed', 'true');

        // delete it (confirm dialog); the plain board is plain again
        await activeView.click();
        await page.getByRole('button', { name: `Options for ${viewName}` }).click();
        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('menuitem', { name: 'Delete' }).click();
        await expect(page.getByText('View deleted')).toBeVisible();

        await page.goto(boardUrl);
        await expect(page.getByPlaceholder('Search board')).toHaveValue('');
        expect(searchParam(page, 'bv')).toBeNull();
        await viewsButton.click();
        await expect(page.getByRole('button', { name: viewName })).toHaveCount(0);
    });

    test('board columns: add and rename on the board, add and rename in settings', async ({ page }) => {
        const boardName = uid('Columns board');
        const boardUrl = await createBoard(page, boardName);
        for (const name of ['To Do', 'In Progress', 'Done']) {
            await expect(page.getByRole('heading', { level: 3, name, exact: true })).toBeVisible();
        }

        // on the board: the "New column" tile
        await page.getByRole('button', { name: 'New column' }).click();
        await page.getByPlaceholder('Column name, e.g. Review').fill('Review');
        await page.getByRole('button', { name: 'Add column' }).click();
        await expect(column(page, 'Review')).toBeVisible();
        await page.getByRole('button', { name: 'Done', exact: true }).click();

        // rename it from the column menu
        await column(page, 'Review').locator('header').getByRole('button').nth(1).click();
        await page.getByRole('menuitem', { name: 'Rename column' }).click();
        const renameDialog = page.getByRole('dialog', { name: 'Rename column' });
        await renameDialog.getByLabel('Name').fill('Code review');
        await renameDialog.getByRole('button', { name: 'Save' }).click();
        await expect(renameDialog).toBeHidden();
        await expect(column(page, 'Code review')).toBeVisible();
        await expect(column(page, 'Review')).toHaveCount(0);

        // board settings: add another column, then rename it
        await page.getByRole('button', { name: 'Board settings' }).click();
        await expect(page).toHaveURL(/\/edit$/);
        await page.getByRole('button', { name: 'Add Column' }).first().click();
        const addDialog = page.getByRole('dialog', { name: 'Add Column' });
        await addDialog.getByLabel('Column Name').fill('QA');
        await addDialog.getByRole('button', { name: 'Create Column' }).click();
        await expect(addDialog).toBeHidden();
        const qaCard = page.locator('div.border', { has: page.getByRole('heading', { level: 4, name: 'QA', exact: true }) }).last();
        await expect(qaCard).toBeVisible();
        await qaCard.getByRole('button').first().click();
        const editDialog = page.getByRole('dialog', { name: 'Edit Column' });
        await editDialog.getByLabel('Column Name').fill('Quality check');
        await editDialog.getByRole('button', { name: 'Save Changes' }).click();
        await expect(editDialog).toBeHidden();
        await expect(page.getByRole('heading', { level: 4, name: 'Quality check', exact: true })).toBeVisible();

        await page.goto(boardUrl);
        for (const name of ['To Do', 'In Progress', 'Done', 'Code review', 'Quality check']) {
            await expect(page.getByRole('heading', { level: 3, name, exact: true })).toBeVisible();
        }
    });
});

/*
 * BUG: "New label name…" in the issue dialog's Labels picker creates the label, but
 * ProjectController::storeLabel answers Inertia's XHR (X-Requested-With) with plain JSON 201, so
 * Inertia shows its error modal ("All Inertia requests must receive a valid Inertia response,
 * however a plain JSON response was received") and the picker never lists the new label.
 */
test('create a label from the issue dialog', async ({ page }) => {
    const title = uid('newlabel');
    const label = uid('label');
    await page.goto(urls.board());
    await createCard(page, title);

    const dialog = await openCard(page, title);
    await sidebarField(dialog, 'Labels').getByRole('button').click();
    const newLabel = page.getByPlaceholder('New label name…');
    await newLabel.fill(label);
    await newLabel.press('Enter');
    await expect(newLabel).toHaveValue('');
    await expect(page.getByRole('button', { name: label, exact: true })).toBeVisible();
});

/* BUG (same cause): Project settings → Labels → Add Label posts through Inertia's useForm too. */
test('create a label in the project settings', async ({ page }) => {
    const label = uid('label');
    await page.goto(`${urls.project()}/edit?tab=labels`);
    await page.getByRole('button', { name: 'Add Label' }).click();
    const dialog = page.getByRole('dialog', { name: 'Create Label' });
    await dialog.getByLabel('Label Name').fill(label);
    await dialog.getByRole('button', { name: /^Create/ }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText(label, { exact: true })).toBeVisible();
});

/*
 * BUG: two issues created at the same time in one project get the same key → the second request
 * fails with a 500 (Postgres: SQLSTATE 23505 duplicate key … "items_project_id_key_unique";
 * SQLite: UNIQUE constraint failed: items.project_id, items.key).
 * Project::getNextItemKey() does `$this->increment('next_item_number')` and then reads the
 * in-memory counter, so two requests that loaded the project with the same number both return it.
 */
test('cards created at the same time all get saved', async ({ page }) => {
    await page.goto(urls.board());
    // what the "Create card" form posts, sent a few times at once (same session, CSRF header)
    const props = await page.evaluate(() => JSON.parse(document.getElementById('app')!.dataset.page!).props);
    const columnId: number = props.board.columns[0].id;
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value);
    const url = `${urls.board()}/items`;
    const responses = await Promise.all(Array.from({ length: 4 }, (_, i) => page.request.post(url, {
        headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
        data: { title: uid(`race-${i}`), column_id: columnId },
        maxRedirects: 0,
    })));
    expect(responses.map((r) => r.status())).toEqual([302, 302, 302, 302]);
});
