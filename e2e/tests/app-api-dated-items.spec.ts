import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 timeline / calendar issues (v1.49.0, BRAI-409), as the desktop / mobile app calls them: a dated
 * issue is in the project's range list, rescheduling it with PATCH /items/{id} moves it to its new days, and
 * the web board's own card data has the new dates.
 */

test.describe('app api: dated issues', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('list a range and reschedule an issue through the API', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
        const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);

        const issue = (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, {
            headers: auth, data: { title: uid('Dated'), start_date: '2031-03-02', due_date: '2031-03-04' },
        }), 201)).data;

        const range = async (from: string, to: string) => (await ok(await page.request.get(`/api/v1/projects/${projectId}/items?from=${from}&to=${to}&limit=100`, { headers: auth })))
            .data.map((i: { id: number }) => i.id);
        expect(await range('2031-03-03', '2031-03-03')).toContain(issue.id);

        await ok(await page.request.patch(`/api/v1/items/${issue.id}`, { headers: auth, data: { start_date: '2031-04-10', due_date: '2031-04-12' } }));
        expect(await range('2031-03-01', '2031-03-31')).not.toContain(issue.id);
        expect(await range('2031-04-11', '2031-04-11')).toContain(issue.id);

        // the web board's card data (what an open board refetches) has the new dates
        const cards = await page.request.get(`${projectPath(SEED.project)}/boards/${SEED.board}/cards?ids[]=${issue.id}`, { headers: { Accept: 'application/json' } });
        const card = (await cards.json()).items[0];
        expect(String(card.due_date)).toContain('2031-04-12');

        await page.request.delete(`/api/v1/items/${issue.id}`, { headers: auth });
        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
