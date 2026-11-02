import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 task lists (v1.49.0, BRAI-397), as the desktop / mobile app calls them: a list created
 * through the API is the web's list, tasks added there show on its page, a repeating task completed
 * through the API rolls on to its next date (WorkCompletion, shared with the web), deletes disappear.
 */

test.describe('app api: task lists', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a list and its tasks made through the API are the web list', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);

        const name = uid('API list');
        const list = await ok(await page.request.post(`/api/v1/projects/${projectId}/task-lists`, { headers: auth, data: { name, request_id: crypto.randomUUID() } }), 201);
        expect(list.data).toMatchObject({ name, counts: { todo: 0, done: 0 }, can: { manage_items: true } });

        const title = uid('Water plants');
        const task = await ok(await page.request.post(`/api/v1/task-lists/${list.data.id}/items`, {
            headers: auth,
            data: { title, notes: 'Use the **green** can', due_date: '2026-11-10', recurrence: 'weekly' },
        }), 201);
        const other = await ok(await page.request.post(`/api/v1/task-lists/${list.data.id}/items`, { headers: auth, data: { title: uid('Throw out') } }), 201);

        // the web's list page shows both
        await page.goto(`${projectPath(SEED.project)}/task-lists/${list.data.slug}`);
        await expect(page.getByRole('heading', { name })).toBeVisible();
        await expect(page.getByText(title)).toBeVisible();
        await expect(page.getByText(other.data.title)).toBeVisible();

        // completing the repeating task keeps it open on its next date
        const done = await ok(await page.request.patch(`/api/v1/task-lists/${list.data.id}/items/${task.data.id}`, { headers: auth, data: { status: 'done' } }));
        expect(done.data).toMatchObject({ status: 'todo', due_date: '2026-11-17', notes: 'Use the **green** can' });

        // reorder, then delete one: the API and the web agree
        await ok(await page.request.post(`/api/v1/task-lists/${list.data.id}/items/reorder`, { headers: auth, data: { ids: [other.data.id, task.data.id] } }));
        const items = await ok(await page.request.get(`/api/v1/task-lists/${list.data.id}/items`, { headers: auth }));
        expect(items.data.map((row: { id: number }) => row.id)).toEqual([other.data.id, task.data.id]);

        expect((await page.request.delete(`/api/v1/task-lists/${list.data.id}/items/${other.data.id}`, { headers: auth })).status()).toBe(204);
        await page.reload();
        await expect(page.getByText(title)).toBeVisible();
        await expect(page.getByText(other.data.title)).toHaveCount(0);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
