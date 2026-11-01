import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { ok, seedAppToken } from '../helpers/app-api';

/*
 * App API v1 snippets + bookmark groups (v1.49.0, BRAI-399), as the desktop quick switcher ("copy
 * snippet") and the mobile app call them: a collection and snippet made through the API are on the web's
 * snippets page, a bookmark group with a link and a to-do made through the API is the web's group.
 */

test.describe('app api: snippets and bookmarks', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('snippets and bookmarks written through the API show on the web', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        // snippets
        const name = uid('API snippets');
        const collection = await ok(await page.request.post(`/api/v1/workspaces/${SEED.workspace}/snippet-collections`, { headers: auth, data: { name } }), 201);
        const title = uid('Restart queue');
        const snippet = await ok(await page.request.post(`/api/v1/snippet-collections/${collection.data.id}/snippets`, {
            headers: auth, data: { title, content: 'php artisan queue:restart', language: 'bash' },
        }), 201);
        const listed = await ok(await page.request.get(`/api/v1/snippet-collections/${collection.data.id}/snippets`, { headers: auth }));
        expect(listed.data.map((row: { id: number }) => row.id)).toEqual([snippet.data.id]);
        expect(listed.data[0].content).toBe('php artisan queue:restart');

        await page.goto(collection.data.url);
        await expect(page.getByText(title).first()).toBeVisible();

        // bookmarks
        const group = await ok(await page.request.post(`/api/v1/workspaces/${SEED.workspace}/bookmarks`, { headers: auth, data: { name: uid('API links'), icon: '🔧' } }), 201);
        const todo = uid('Read the docs');
        await ok(await page.request.post(`/api/v1/bookmarks/${group.data.id}/items`, { headers: auth, data: { type: 'todo', title: todo } }), 201);
        const items = await ok(await page.request.get(`/api/v1/bookmarks/${group.data.id}/items`, { headers: auth }));
        await ok(await page.request.patch(`/api/v1/bookmarks/${group.data.id}/items/${items.data[0].id}`, { headers: auth, data: { is_completed: true } }));

        await page.goto(group.data.url);
        await expect(page.getByText(todo).first()).toBeVisible();
        const groups = await ok(await page.request.get(`/api/v1/workspaces/${SEED.workspace}/bookmarks?limit=100`, { headers: auth }));
        expect(groups.data.find((g: { id: number }) => g.id === group.data.id).counts).toMatchObject({ todos: 1, todos_completed: 1 });

        // deleting through the API: gone on the web
        expect((await page.request.delete(`/api/v1/snippets/${snippet.data.id}`, { headers: auth })).status()).toBe(204);
        await page.goto(collection.data.url);
        await expect(page.getByText(title)).toHaveCount(0);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
