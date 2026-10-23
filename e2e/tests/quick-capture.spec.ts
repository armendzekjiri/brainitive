import { expect, test, uid, urls } from '../fixtures';
import { addProjectContent, docEditor } from '../helpers/content';
import { pageProps } from '../helpers/helpdesk';
import { postJson } from '../helpers/boards';
import {
    captureCard, captureDialog, createBookmarkCollection, createCapture, createSnippetCollection, openCaptures, organize, saveInDialog,
} from '../helpers/quick-capture';

/** Quick capture (BRAI-70): a private inbox of ideas, organized later into content. */

const WS = 'E2E Workspace';
const PROJECT_GROUP = 'E2E Workspace / Website';

test.describe('quick capture', () => {
    test('capture from the top-bar button lands in my private inbox', async ({ page }) => {
        const idea = uid('Idea button');
        await page.goto(urls.home);
        await page.getByRole('button', { name: 'Capture', exact: true }).click();
        await saveInDialog(page, `${idea}\nsecond line`, { url: 'https://example.com/source' });

        await openCaptures(page, idea);
        const card = captureCard(page, idea);
        await expect(card).toBeVisible();
        await expect(card.getByRole('link', { name: 'https://example.com/source' })).toBeVisible();
    });

    test('the C shortcut opens capture, but never while typing', async ({ page }) => {
        const idea = uid('Idea shortcut');
        await page.goto('/my-work');
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
        await page.keyboard.press('c');
        await saveInDialog(page, idea, { viaKeyboard: true });

        // typing "c" into an input doesn't open it
        await openCaptures(page, idea);
        await expect(captureCard(page, idea)).toBeVisible();
        const search = page.getByLabel('Search captures');
        await search.fill('');
        await search.pressSequentially('cc');
        await expect(search).toHaveValue('cc');
        await expect(captureDialog(page)).toBeHidden();

        // …nor inside the doc editor: the letters stay in the text
        const word = `${uid('doc')}cact`;
        await addProjectContent(page, 'Page', uid('Capture doc'));
        await docEditor(page).click();
        await page.keyboard.type(`c ${word}`);
        await expect(docEditor(page)).toContainText(`c ${word}`);
        await expect(captureDialog(page)).toBeHidden();
    });

    test('captures are private: another member sees nothing and cannot change them', async ({ page, memberPage }) => {
        const idea = uid('Secret idea');
        await createCapture(page, idea);
        await openCaptures(page, idea);
        await expect(captureCard(page, idea)).toBeVisible();
        const { captures } = await pageProps<{ captures: { id: string }[] }>(page);
        const id = captures[0].id;

        await openCaptures(memberPage, idea);
        await expect(captureCard(memberPage, idea)).toHaveCount(0);
        await expect(memberPage.getByText('No captures match your search.')).toBeVisible();

        const xsrf = (await memberPage.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
        const headers = { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' };
        expect((await memberPage.request.patch(`/captures/${id}`, { headers, data: { text: 'hijacked' } })).status()).toBe(404);
        expect((await memberPage.request.get(`/captures/${id}/preview?type=issue`, { headers })).status()).toBe(404);
        expect((await memberPage.request.post(`/captures/${id}/convert`, { headers, data: { type: 'issue', destination_id: 1, fields: { title: 'x' } } })).status()).toBe(404);

        await page.reload();
        await expect(captureCard(page, idea)).toBeVisible();
    });

    test('archive and restore', async ({ page }) => {
        const idea = uid('Idea archive');
        await createCapture(page, idea);
        await openCaptures(page, idea);
        await captureCard(page, idea).getByRole('button', { name: 'Archive' }).click();
        await expect(page.getByText('Capture archived')).toBeVisible();
        await expect(captureCard(page, idea)).toHaveCount(0);

        await openCaptures(page, idea, 'archived');
        await captureCard(page, idea).getByRole('button', { name: 'Restore' }).click();
        await expect(page.getByText('Capture restored')).toBeVisible();
        await expect(captureCard(page, idea)).toHaveCount(0);

        await openCaptures(page, idea);
        await expect(captureCard(page, idea)).toBeVisible();
    });

    test('edit a capture', async ({ page }) => {
        const idea = uid('Idea edit');
        const better = uid('Idea edited');
        await createCapture(page, idea);
        await openCaptures(page, idea);
        await captureCard(page, idea).getByRole('button', { name: 'Edit capture' }).click();
        const dialog = page.getByRole('dialog', { name: 'Edit capture' });
        await dialog.getByLabel('Idea').fill(better);
        await dialog.getByRole('button', { name: 'Save' }).click();
        await expect(dialog).toBeHidden();
        await openCaptures(page, better);
        await expect(captureCard(page, better)).toBeVisible();
    });
});

test.describe('organize a capture', () => {
    test('into a board card — double click and a retry still create one card', async ({ page }) => {
        const title = uid('Card from capture');
        await createCapture(page, `${title}\n\`\`\`js\nconst a = 1 < 2;\n\`\`\``, 'https://example.com/bug');
        await openCaptures(page, title);
        const { captures } = await pageProps<{ captures: { id: string }[] }>(page);

        const dialog = await organize(page, title, 'Board card', PROJECT_GROUP, 'Main Board');
        const created = page.waitForResponse((r) => r.url().endsWith('/convert') && r.status() === 201);
        await dialog.getByRole('button', { name: 'Create issue' }).dblclick();
        await created;
        await expect(dialog.getByRole('status')).toContainText(title);

        // a retry of the same conversion returns the same card
        const retry = await postJson<{ replayed: boolean; location: { url: string } }>(page, `/captures/${captures[0].id}/convert`, {
            type: 'issue', destination_id: 1, fields: { title },
        });
        expect(retry.replayed).toBe(true);

        await dialog.getByRole('link', { name: 'Open board item' }).click();
        await expect(page).toHaveURL(new RegExp(`${urls.board()}(\\?[^#]*)?#item-\\d+$`));
        await expect(page.getByRole('dialog', { name: new RegExp(`^Edit [A-Z]+-\\d+: ${title}`) })).toBeVisible();
        expect(new URL(page.url()).hash).toBe(new URL(retry.location.url).hash);

        await page.goto(urls.board());
        await expect(page.getByText(title, { exact: true })).toHaveCount(1);

        await openCaptures(page, title, 'processed');
        await expect(captureCard(page, title).getByRole('link', { name: `Board card: ${title}` })).toBeVisible();
    });

    test('into a task-list task', async ({ page }) => {
        const list = uid('Capture list');
        const title = uid('Task from capture');
        await addProjectContent(page, 'List', list);
        await createCapture(page, title);
        await openCaptures(page, title);

        const dialog = await organize(page, title, 'Task', PROJECT_GROUP, list);
        await dialog.getByRole('button', { name: 'Create task' }).click();
        await dialog.getByRole('link', { name: 'Open task' }).click();
        await expect(page).toHaveURL(/\/task-lists\//);
        await expect(page.getByText(title)).toBeVisible();
    });

    test('into a project doc', async ({ page }) => {
        const title = uid('Doc from capture');
        const body = uid('doc body');
        await createCapture(page, `# ${title}\n\n${body}`);
        await openCaptures(page, title);

        const dialog = await organize(page, `# ${title}`, 'Doc', WS, 'Website');
        await expect(dialog.getByLabel('Title')).toHaveValue(title);
        await dialog.getByRole('button', { name: 'Create doc' }).click();
        await dialog.getByRole('link', { name: 'Open page' }).click();
        await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}$/);
        await expect(page.getByText(body)).toBeVisible();
    });

    test('into a bookmark collection', async ({ page }) => {
        const collection = uid('Capture links');
        const title = uid('Bookmark from capture');
        const url = `https://${uid('site')}.invalid/read`;
        await createBookmarkCollection(page, collection);
        await createCapture(page, title, url);
        await openCaptures(page, title);

        const dialog = await organize(page, title, 'Bookmark', WS, collection);
        await expect(dialog.getByLabel('URL')).toHaveValue(url);
        await dialog.getByRole('button', { name: 'Create bookmark' }).click();
        await dialog.getByRole('link', { name: 'Open bookmark' }).click();
        await expect(page.getByRole('heading', { name: collection, level: 1 })).toBeVisible();
        await expect(page.getByText(title).first()).toBeVisible();
    });

    test('into a snippet collection, keeping the code', async ({ page }) => {
        const collection = uid('Capture snippets');
        const title = uid('Snippet from capture');
        const code = `echo "${uid('code')}" && exit 0`;
        await createSnippetCollection(page, collection);
        await createCapture(page, `${title}\n\`\`\`bash\n${code}\n\`\`\``);
        await openCaptures(page, title);

        const dialog = await organize(page, title, 'Snippet', WS, collection);
        await expect(dialog.getByLabel('Code')).toHaveValue(code);
        await expect(dialog.getByLabel('Language')).toHaveValue('bash');
        await dialog.getByRole('button', { name: 'Create snippet' }).click();
        await dialog.getByRole('link', { name: 'Open snippet' }).click();
        await page.getByRole('button', { name: title }).click();
        await expect(page.getByRole('cell', { name: code })).toBeVisible();
    });

    test('a destination the member may not write to is refused and the capture stays', async ({ page, memberPage }) => {
        const collection = uid('Owner snippets');
        await createSnippetCollection(page, collection);
        const destinations = await (await page.request.get('/captures/destinations', { headers: { Accept: 'application/json' } })).json();
        const collectionId = destinations.workspaces
            .flatMap((w: { snippet_collections: { id: number; name: string }[] }) => w.snippet_collections)
            .find((c: { name: string }) => c.name === collection).id;

        const title = uid('Member idea');
        await createCapture(memberPage, title);
        await openCaptures(memberPage, title);
        const { captures } = await pageProps<{ captures: { id: string }[] }>(memberPage);

        // a workspace member (not editor) can't add snippets: the dialog offers nothing…
        await captureCard(memberPage, title).getByRole('button', { name: 'Organize' }).click();
        const dialog = memberPage.getByRole('dialog', { name: 'Organize capture' });
        await dialog.getByRole('button', { name: 'Snippet', exact: true }).click();
        await expect(dialog.getByRole('combobox', { name: 'Destination' })).toContainText('Nowhere you can add this');
        await memberPage.keyboard.press('Escape');

        // …and the endpoint refuses the collection directly
        const xsrf = (await memberPage.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
        const response = await memberPage.request.post(`/captures/${captures[0].id}/convert`, {
            headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' },
            data: { type: 'snippet', destination_id: collectionId, fields: { title, content: 'echo 1' } },
        });
        expect(response.status()).toBe(403);

        await memberPage.reload();
        await expect(captureCard(memberPage, title).getByRole('button', { name: 'Organize' })).toBeVisible();
    });
});

test.describe('quick capture on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true });

    test('the floating button captures', async ({ page }) => {
        const idea = uid('Idea phone');
        await page.goto('/my-work');
        await page.getByRole('button', { name: 'Quick capture' }).tap();
        await saveInDialog(page, idea);
        await openCaptures(page, idea);
        await expect(captureCard(page, idea)).toBeVisible();
    });
});
