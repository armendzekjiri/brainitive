import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { card } from '../helpers/boards';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 issues (v1.49.0, BRAI-404), as the desktop / mobile app calls them: an issue created with a
 * Markdown description is the web's issue (Support\Boards\Issues, shared with the issue dialog), a change
 * through the API shows on the web, the key finds it, and a delete removes it from the board.
 */

test.describe('app api: issues', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('create, read by key, edit and delete an issue through the API', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
        const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);

        const title = uid('API issue');
        const created = await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, {
            headers: auth,
            data: { title, description: 'Made in the **app**', story_points: 3, request_id: crypto.randomUUID() },
        }), 201);
        expect(created.data).toMatchObject({ title, description: 'Made in the **app**', story_points: 3, status: 'To Do', can: { update: true } });

        // the web's issue page shows it, the description rendered from the Markdown
        await page.goto(`${projectPath(SEED.project)}/boards/${SEED.board}/items/${created.data.id}`);
        await expect(page.getByText(title).first()).toBeVisible();
        await expect(page.locator('strong', { hasText: 'app' }).first()).toBeVisible();

        const byKey = await ok(await page.request.get(`/api/v1/items/by-key/${created.data.key}`, { headers: auth }));
        expect(byKey.data.id).toBe(created.data.id);

        // a change through the API, with the updated_at it was read at
        const renamed = `${title} (renamed)`;
        const updated = await ok(await page.request.patch(`/api/v1/items/${created.data.id}`, {
            headers: auth,
            data: { title: renamed, updated_at: byKey.data.updated_at },
        }));
        expect(updated.data.title).toBe(renamed);
        await page.goto(`${projectPath(SEED.project)}/boards/${SEED.board}`);
        await expect(card(page, renamed)).toBeVisible();

        // delete: gone from the board and the API
        expect((await page.request.delete(`/api/v1/items/${created.data.id}`, { headers: auth })).status()).toBe(204);
        expect((await page.request.get(`/api/v1/items/${created.data.id}`, { headers: auth })).status()).toBe(404);
        await page.reload();
        await expect(card(page, renamed)).toHaveCount(0);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
