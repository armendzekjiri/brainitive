import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 comments, watchers, files and history of an issue (v1.49.0, BRAI-405), as the desktop / mobile
 * app calls them: a Markdown comment posted through the API is on the web's issue page (ItemComments,
 * shared with the issue dialog), a file uploaded through it is listed there, and the history has both.
 */

test.describe('app api: issue comments and files', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('comment, watch, attach a file and read the history through the API', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
        const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);
        const issue = await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: auth, data: { title: uid('API talk') } }), 201);
        const id = issue.data.id;

        const text = uid('Reviewed in the app');
        const comment = await ok(await page.request.post(`/api/v1/items/${id}/comments`, { headers: auth, data: { body: `${text} **twice**` } }), 201);
        expect(comment.data.body).toBe(`${text} **twice**`);

        // the reporter already watches; unwatch and watch again
        expect((await ok(await page.request.delete(`/api/v1/items/${id}/watch`, { headers: auth }))).data.watching).toBe(false);
        expect((await ok(await page.request.post(`/api/v1/items/${id}/watch`, { headers: auth }))).data.watching).toBe(true);

        const file = await ok(await page.request.post(`/api/v1/items/${id}/attachments`, {
            headers: auth,
            multipart: { file: { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello from the app') } },
        }), 201);
        expect(file.data).toMatchObject({ name: 'notes.txt', size: 18 });

        // the web's issue page shows the comment and the file
        await page.goto(`${projectPath(SEED.project)}/boards/${SEED.board}/items/${id}`);
        await expect(page.getByText(text).first()).toBeVisible();
        await expect(page.getByText('notes.txt').first()).toBeVisible();

        const history = await ok(await page.request.get(`/api/v1/items/${id}/activity`, { headers: auth }));
        const actions = history.data.map((entry: { action: string }) => entry.action);
        expect(actions).toContain('commented');
        expect(actions).toContain('attachment_added');

        expect((await page.request.delete(`/api/v1/items/${id}/attachments/${file.data.id}`, { headers: auth })).status()).toBe(204);
        await page.request.delete(`/api/v1/items/${id}`, { headers: auth });
        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
