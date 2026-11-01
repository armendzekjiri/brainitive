import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 subtasks and linked issues (v1.49.0, BRAI-406), as the desktop / mobile app calls them: a
 * subtask created with parent_id and a "blocks" link added through the API (ItemLinks, shared with the web's
 * issue view) show on the web's issue page.
 */

test.describe('app api: subtasks and links', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a subtask and a link made through the API are on the web issue', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
        const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);
        const create = async (data: Record<string, unknown>) => (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: auth, data }), 201)).data;

        const parent = await create({ title: uid('API parent') });
        const child = await create({ title: uid('API child'), parent_id: parent.id });
        const blocker = await create({ title: uid('API blocker') });

        const children = await ok(await page.request.get(`/api/v1/items/${parent.id}/children`, { headers: auth }));
        expect(children.data.map((c: { id: number }) => c.id)).toEqual([child.id]);

        const link = await ok(await page.request.post(`/api/v1/items/${blocker.id}/links`, { headers: auth, data: { target_id: parent.id, type: 'blocks' } }), 201);
        const fromParent = await ok(await page.request.get(`/api/v1/items/${parent.id}/links`, { headers: auth }));
        expect(fromParent.data[0]).toMatchObject({ id: link.data.id, type: 'is_blocked_by', issue: { key: blocker.key } });

        await page.goto(`${projectPath(SEED.project)}/boards/${SEED.board}/items/${parent.id}`);
        await expect(page.getByText(child.title).first()).toBeVisible();
        await expect(page.getByText(blocker.key).first()).toBeVisible();

        for (const id of [blocker.id, parent.id]) await page.request.delete(`/api/v1/items/${id}`, { headers: auth });
        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
