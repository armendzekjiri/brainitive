import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { card } from '../helpers/boards';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 project config, filters and saved views (v1.49.0, BRAI-407), as the desktop / mobile app calls
 * them: the board URL's filter params give the API the same cards the web board shows with them, and the
 * project config lists the labels an issue form offers.
 */

test.describe('app api: board filters and config', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('the same filter params pick the same cards in the API and on the board', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
        const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);

        const config = await ok(await page.request.get(`/api/v1/projects/${projectId}/config`, { headers: auth }));
        expect(config.data.types.length).toBeGreaterThan(0);
        expect(config.data.priorities.length).toBeGreaterThan(0);

        const word = uid('Zebra').replace(/\W/g, '');
        const match = (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: auth, data: { title: `${word} match` } }), 201)).data;
        const other = (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: auth, data: { title: uid('Unrelated') } }), 201)).data;

        const filtered = await ok(await page.request.get(`/api/v1/boards/${board.id}/items?q=${encodeURIComponent(word)}&assignee=unassigned`, { headers: auth }));
        expect(filtered.data.map((c: { id: number }) => c.id)).toEqual([match.id]);

        await page.goto(`${projectPath(SEED.project)}/boards/${SEED.board}?q=${encodeURIComponent(word)}&assignee=unassigned`);
        await expect(card(page, match.title)).toBeVisible();
        await expect(card(page, other.title)).toHaveCount(0);

        const views = await ok(await page.request.get(`/api/v1/boards/${board.id}/views`, { headers: auth }));
        expect(Array.isArray(views.data)).toBe(true);

        for (const id of [match.id, other.id]) await page.request.delete(`/api/v1/items/${id}`, { headers: auth });
        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
