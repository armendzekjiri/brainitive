import { expect, test, uid } from '../fixtures';
import { addProjectContent, docEditor, docReader, draftSaved, isoDay, publish, workspaceUrl } from '../helpers/content';

test.describe('documents', () => {
    test('a new page autosaves typed text and keeps it after a reload', async ({ page }) => {
        const name = uid('Doc');
        const text = `Release notes ${uid('body')}`;
        await addProjectContent(page, 'Page', name);
        await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}$/);

        const editor = docEditor(page);
        await editor.click();
        const saved = draftSaved(page);
        await page.keyboard.type(text);
        await saved;
        await expect(page.getByText('Saved', { exact: true })).toBeVisible();

        await page.reload();
        await expect(docEditor(page)).toContainText(text);
    });

    test('a published page can be shared with a public link that works logged out', async ({ page, browser }) => {
        const name = uid('Shared doc');
        const text = `Public content ${uid('body')}`;
        await addProjectContent(page, 'Page', name);

        await docEditor(page).click();
        const saved = draftSaved(page);
        await page.keyboard.type(text);
        await saved;

        await publish(page);
        await expect(docReader(page)).toContainText(text);

        await page.getByRole('button', { name: 'Share' }).click();
        const dialog = page.getByRole('dialog', { name: `Share ${name}` });
        await expect(dialog.getByText('Not shared yet.')).toBeVisible();
        await dialog.getByRole('button', { name: 'Create link' }).click();
        const link = dialog.locator('code');
        await expect(link).toHaveText(/\/s\/[A-Za-z0-9]{20,64}$/);
        const shareUrl = (await link.textContent())!.trim();

        const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        try {
            const guestPage = await guest.newPage();
            await guestPage.goto(shareUrl);
            await expect(guestPage.getByRole('heading', { level: 1 })).toContainText(name);
            await expect(guestPage.getByText(text)).toBeVisible();
        } finally {
            await guest.close();
        }
    });

    test('watchers are notified when a published page is updated', async ({ page, memberPage }) => {
        const name = uid('Watched doc');
        await addProjectContent(page, 'Page', name);
        await docEditor(page).click();
        const saved = draftSaved(page);
        await page.keyboard.type('First version');
        await saved;
        await publish(page);
        const pageUrl = page.url();

        // the member starts watching the page
        await memberPage.goto(pageUrl);
        await expect(docReader(memberPage)).toContainText('First version');
        const watched = memberPage.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/watch'));
        await memberPage.getByRole('button', { name: 'Watch', exact: true }).click();
        expect((await watched).status()).toBeLessThan(400);
        await expect(memberPage.getByRole('button', { name: 'Watching', exact: true })).toBeVisible();

        // the owner edits and publishes the change
        await page.reload();
        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        await docEditor(page).click();
        await page.keyboard.press('End');
        const draft = draftSaved(page);
        await page.keyboard.type(' and an update');
        await draft;
        const updated = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().endsWith('/publish-changes'));
        await page.getByRole('button', { name: 'Update', exact: true }).click();
        await updated;
        await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
        await expect(docReader(page)).toContainText('First version and an update');

        await memberPage.goto('/notifications');
        await expect(memberPage.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible();
        await expect(memberPage.getByRole('main').getByRole('link', { name, exact: true })).toBeVisible();
    });
});

test.describe('whiteboards', () => {
    test('a new whiteboard opens the Excalidraw editor and saves a drawing', async ({ page }) => {
        const name = uid('Board sketch');
        await addProjectContent(page, 'Whiteboard', name);

        await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}\/excalidraw/);
        await expect(page).toHaveTitle(`Whiteboard – ${name}`);
        await expect(page.locator('.excalidraw')).toBeVisible();
        await expect(page.locator('#loading')).toBeHidden();

        // a new whiteboard offers templates first: start blank
        const templates = page.locator('#templatesModal');
        await expect(templates.getByRole('heading', { name: 'Start from a template' })).toBeVisible();
        await templates.getByRole('button', { name: 'Close' }).click();
        await expect(templates).toBeHidden();

        // draw a rectangle (R, then drag on the canvas)
        const canvas = page.locator('canvas.excalidraw__canvas.interactive');
        const box = (await canvas.boundingBox())!;
        const cx = box.x + box.width / 2;
        const cy = box.y + box.height / 2;
        await page.mouse.click(cx, cy);
        await page.keyboard.press('r');
        await page.mouse.move(cx - 120, cy - 60);
        await page.mouse.down();
        await page.mouse.move(cx + 120, cy + 60, { steps: 10 });
        await page.mouse.up();

        // the drawing autosaves; "Save & exit" then stores the preview and returns to the page
        await expect(page.locator('#saveStatus')).toHaveText('All changes saved');
        const saveAndExit = page.getByRole('button', { name: 'Save & exit' });
        await expect(saveAndExit).toBeEnabled();
        const alerts: string[] = [];
        page.on('dialog', (d) => alerts.push(d.message()));
        await saveAndExit.click();

        // back on the page: read-only preview, and the editor loads the saved rectangle
        await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}\?from=excalidraw$/);
        expect(alerts, 'save errors shown by the editor').toEqual([]);
        await expect(page.getByText('View mode · pan and zoom freely')).toBeVisible();
        await page.getByRole('link', { name: 'Open whiteboard' }).click();
        await expect(page).toHaveURL(/\/excalidraw/);
        expect(await page.locator('#excalidraw-config').textContent()).toContain('"type":"rectangle"');
        await expect(page.locator('#loading')).toBeHidden();
    });
});

test.describe('task lists', () => {
    test('tasks can be added and checked off, and stay done after a reload', async ({ page }) => {
        const name = uid('List');
        const first = uid('Buy milk');
        const second = uid('Call Bob');
        await addProjectContent(page, 'List', name);
        await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
        await expect(page.getByText('No tasks yet')).toBeVisible();

        await page.getByRole('button', { name: 'Add task' }).click();
        const input = page.getByLabel('Task name');
        await input.fill(first);
        await input.press('Enter');
        await expect(page.getByRole('button', { name: `Complete “${first}”` })).toBeVisible();
        await expect(input).toHaveValue('');
        await input.fill(second);
        await input.press('Enter');
        await expect(page.getByRole('button', { name: `Complete “${second}”` })).toBeVisible();

        const done = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().includes('/items/'));
        await page.getByRole('button', { name: `Complete “${first}”` }).click();
        await done;
        await expect(page.getByText('1 of 2 done')).toBeVisible();

        await page.reload();
        await expect(page.getByText('1 of 2 done')).toBeVisible();
        await expect(page.getByRole('button', { name: `Complete “${second}”` })).toBeVisible();
        await page.getByRole('button', { name: /Completed · 1/ }).click();
        await expect(page.getByRole('button', { name: `Mark “${first}” as not done` })).toBeVisible();
    });
});

test.describe('my work', () => {
    test('shows the issues assigned to the signed-in user only', async ({ page, memberPage }) => {
        await page.goto('/my-work');
        await expect(page.getByText('Checkout bug')).toBeVisible();
        await expect(page.getByText('Pricing page copy')).toHaveCount(0);

        await page.getByRole('button', { name: /Created by me/ }).click();
        await expect(page).toHaveURL(/scope=created/);
        await expect(page.getByText('Pricing page copy')).toBeVisible();
        await expect(page.getByText('Homepage hero')).toBeVisible();

        await memberPage.goto('/my-work');
        await expect(memberPage.getByText('Pricing page copy')).toBeVisible();
        await expect(memberPage.getByText('Checkout bug')).toHaveCount(0);
    });
});

test.describe('calendar', () => {
    test('switches between month, week and day views', async ({ page }) => {
        const day = new Date();
        day.setDate(15);
        await page.goto(`/calendar?view=month&date=${isoDay(day)}`);
        const heading = page.getByRole('heading', { level: 1 });
        await expect(heading).toHaveText(day.toLocaleDateString('en', { month: 'long', year: 'numeric' }));

        await page.getByRole('button', { name: 'Week', exact: true }).click();
        await expect(heading).toHaveText(/ – /);
        await page.getByRole('button', { name: 'Day', exact: true }).click();
        await expect(heading).toHaveText(day.toLocaleDateString('en', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }));
        await page.getByRole('button', { name: 'Month', exact: true }).click();
        await expect(heading).toHaveText(day.toLocaleDateString('en', { month: 'long', year: 'numeric' }));
    });

    test('quick create adds a dated task to a list and shows it on the calendar', async ({ page }) => {
        const list = uid('Cal list');
        const title = uid('Dentist');
        await addProjectContent(page, 'List', list);
        await expect(page.getByRole('heading', { level: 1, name: list })).toBeVisible();

        const day = new Date();
        day.setDate(15);
        await page.goto(`/calendar?view=month&date=${isoDay(day)}`);
        await page.getByText('15', { exact: true }).click();

        const dialog = page.getByRole('dialog', { name: 'Quick create' });
        await dialog.getByLabel('Title').fill(title);
        // comboboxes: project, then board/list
        await dialog.getByRole('combobox').nth(0).click();
        await page.getByRole('option', { name: /^Website\s*·/ }).click();
        await dialog.getByRole('button', { name: 'List', exact: true }).click();
        await dialog.getByRole('combobox').nth(1).click();
        await page.getByRole('option', { name: list, exact: true }).click();
        await expect(dialog.getByLabel('Date')).toHaveValue(isoDay(day));
        await dialog.getByRole('button', { name: 'Create' }).click();
        await expect(dialog).toBeHidden();

        await expect(page.getByText(title)).toBeVisible();
    });
});

test.describe('bookmarks', () => {
    test('create a collection and add a link and a to-do', async ({ page }) => {
        const name = uid('Reading');
        const url = `https://${uid('site')}.invalid/article`;
        const todo = uid('Read later');
        await page.goto(workspaceUrl('bookmarks'));
        await expect(page.getByRole('heading', { name: 'Bookmarks', level: 1 })).toBeVisible();

        await page.getByRole('button', { name: 'Add Bookmark' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'New bookmark collection' });
        await dialog.getByLabel('Name').fill(name);
        await dialog.getByRole('button', { name: 'Create' }).click();
        await expect(page).toHaveURL(/\/bookmarks\/[^/]+$/);
        await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();

        await page.getByPlaceholder('Paste URL…').fill(url);
        await page.getByPlaceholder('Paste URL…').press('Enter');
        await expect(page.getByText('Link added')).toBeVisible();

        await page.getByRole('button', { name: 'To-do', exact: true }).click();
        const todoInput = page.getByPlaceholder('To-do…', { exact: true });
        await todoInput.fill(todo);
        await todoInput.press('Enter');
        await expect(page.getByText('To-do added')).toBeVisible();

        await page.reload();
        await expect(page.getByText(url).first()).toBeVisible();
        await expect(page.getByText(todo)).toBeVisible();
    });
});

test.describe('snippets', () => {
    test('create a collection and add a code snippet', async ({ page }) => {
        const name = uid('Configs');
        const title = uid('Nginx');
        const code = `server { listen 80; } # ${uid('code')}`;
        await page.goto(workspaceUrl('snippets'));
        await expect(page.getByRole('heading', { name: 'Snippets', level: 1 })).toBeVisible();

        await page.getByRole('button', { name: 'New Collection' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'New Snippet Collection' });
        await dialog.getByLabel('Name').fill(name);
        await dialog.getByRole('button', { name: 'Create' }).click();
        await expect(page).toHaveURL(/\/snippets\/[^/]+$/);
        await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();

        await page.getByRole('button', { name: 'New Snippet' }).first().click();
        const form = page.getByRole('dialog', { name: 'New Snippet' });
        await form.getByPlaceholder('e.g. Nginx config').fill(title);
        await form.getByPlaceholder('Paste your code here...').fill(code);
        await form.getByRole('button', { name: 'Add Snippet' }).click();
        await expect(form).toBeHidden();

        await page.reload();
        await page.getByRole('button', { name: title }).click();
        await expect(page.getByRole('cell', { name: code })).toBeVisible();
    });
});

test.describe('notifications', () => {
    test('the inbox page renders', async ({ page }) => {
        await page.goto('/notifications');
        await expect(page.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible();
    });
});
