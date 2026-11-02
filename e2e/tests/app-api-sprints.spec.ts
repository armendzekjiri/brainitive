import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath, request } from '../helpers/workload';

/*
 * App API v1 Scrum (v1.49.0, BRAI-408), as the desktop / mobile app calls it: on a Scrum board set up on the
 * web, an issue planned into a sprint through the API (SprintLifecycle::moveIssues, shared with the MCP) is
 * in that sprint on the web's backlog page, and back in the backlog after the reverse move.
 */

test.describe('app api: sprints', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('plan an issue into a sprint and back through the API', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        // a Scrum board with one planned sprint, set up on the web
        const name = uid('API scrum');
        expect((await request(page, 'POST', `${projectPath(SEED.project)}/boards`, { name })).status()).toBeLessThan(400);
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards?limit=100`, { headers: auth }));
        const board = boards.data.find((b: { name: string }) => b.name === name);
        expect((await request(page, 'PUT', `${projectPath(SEED.project)}/boards/${board.slug}`, { name, type: 'scrum' })).status()).toBeLessThan(400);
        expect((await request(page, 'POST', `${projectPath(SEED.project)}/boards/${board.slug}/sprints`, {})).status()).toBeLessThan(400);

        const sprints = await ok(await page.request.get(`/api/v1/boards/${board.id}/sprints`, { headers: auth }));
        expect(sprints.data).toHaveLength(1);
        const sprint = sprints.data[0];
        expect(sprint).toMatchObject({ status: 'planned', counts: { issues: 0 } });

        const title = uid('Sprint work');
        const issue = (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: auth, data: { title, story_points: 2 } }), 201)).data;
        const backlog = await ok(await page.request.get(`/api/v1/boards/${board.id}/backlog`, { headers: auth }));
        expect(backlog.data.map((i: { id: number }) => i.id)).toContain(issue.id);

        const moved = await ok(await page.request.post(`/api/v1/sprints/${sprint.id}/items`, { headers: auth, data: { item_ids: [issue.id] } }));
        expect(moved.data).toEqual({ moved: 1, sprint_id: sprint.id });
        const detail = await ok(await page.request.get(`/api/v1/sprints/${sprint.id}`, { headers: auth }));
        expect(detail.data).toMatchObject({ counts: { issues: 1 }, points: { total: 2 } });

        // the web backlog page shows it inside the sprint
        await page.goto(`${projectPath(SEED.project)}/boards/${board.slug}/backlog`);
        const section = page.locator('section').filter({ has: page.getByRole('button', { name: sprint.name }) });
        await expect(section.getByRole('listitem').filter({ hasText: title })).toBeVisible();

        await ok(await page.request.post(`/api/v1/boards/${board.id}/backlog/items`, { headers: auth, data: { item_ids: [issue.id] } }));
        await page.reload();
        await expect(section.getByRole('listitem').filter({ hasText: title })).toHaveCount(0);
        await expect(page.getByRole('listitem').filter({ hasText: title })).toBeVisible();

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
