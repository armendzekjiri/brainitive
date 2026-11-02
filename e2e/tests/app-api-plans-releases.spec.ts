import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { createPlan, savePlanRow } from '../helpers/backlinks';
import { projectPath, request } from '../helpers/workload';

/*
 * App API v1 Gantt plans and releases, read-only (v1.49.0, BRAI-415): a plan whose row is linked to an issue and a
 * release made on the web read back through the API.
 */

test.describe('app api: plans and releases', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a plan and a release made on the web read back through the API', async ({ page }) => {
        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
        const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);
        const issue = (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: auth, data: { title: uid('Planned issue') } }), 201)).data;

        const planName = uid('API plan');
        const plan = await createPlan(page, planName);
        await savePlanRow(page, plan.url, issue.id, 'Linked row');
        const plans = await ok(await page.request.get(`/api/v1/projects/${projectId}/plans?limit=100`, { headers: auth }));
        const listed = plans.data.find((p: { slug: string }) => p.slug === plan.slug);
        expect(listed).toMatchObject({ name: planName, rows_count: 1 });
        const detail = (await ok(await page.request.get(`/api/v1/plans/${listed.id}`, { headers: auth }))).data;
        expect(detail.rows[0]).toMatchObject({ name: 'Linked row', issue: { id: issue.id, key: issue.key } });

        const releaseName = uid('v');
        const created = await request(page, 'POST', `${projectPath(SEED.project)}/releases`, { name: releaseName, description: 'From the web' });
        expect(created.status()).toBeLessThan(400);
        const releases = await ok(await page.request.get(`/api/v1/projects/${projectId}/releases?status=unreleased`, { headers: auth }));
        const release = releases.data.find((r: { name: string }) => r.name === releaseName);
        expect(release).toMatchObject({ status: 'unreleased', progress: { total: 0 } });
        expect((await ok(await page.request.get(`/api/v1/releases/${release.id}`, { headers: auth }))).data.description).toBe('From the web');

        await page.request.delete(`/api/v1/items/${issue.id}`, { headers: auth });
        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
