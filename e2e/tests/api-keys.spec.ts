import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { ok, seedAppToken } from '../helpers/app-api';
import { createBlankProject } from '../helpers/workspaces-projects';

/*
 * Developer API (v1.52.0): a personal API key made in Profile → API keys, limited to one board with Issues & boards
 * read + write, works on that board through /api/v1 (list, create, move an issue), is refused for another project
 * (403 key_scope_denied) and for deleting (403 key_ability_denied: no Delete permission), and stops at once when
 * it's revoked (401 api_key_revoked).
 */

test.describe('api keys', () => {
    test.skip(REMOTE, 'needs the e2e-only device seed route to look up ids (dev has no such route)');

    test('a key limited to one board works there and nowhere else, and dies when revoked', async ({ page }) => {
        // ids and names through a device token (the apps' token)
        const other = await createBlankProject(page, SEED.workspace, uid('Other project'));
        const device = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const workspace = (await ok(await page.request.get(`/api/v1/workspaces/${SEED.workspace}`, { headers: device }))).data;
        const projects = (await ok(await page.request.get(`/api/v1/workspaces/${SEED.workspace}/projects?limit=100`, { headers: device }))).data;
        const project = projects.find((p: { slug: string }) => p.slug === SEED.project);
        const otherProject = projects.find((p: { slug: string }) => p.slug === other.slug);
        const boards = (await ok(await page.request.get(`/api/v1/projects/${project.id}/boards`, { headers: device }))).data;
        const board = boards.find((b: { slug: string }) => b.slug === SEED.board);
        const detail = (await ok(await page.request.get(`/api/v1/boards/${board.id}`, { headers: device }))).data;
        const inProgress = detail.columns.find((c: { name: string }) => c.name === 'In Progress');

        // create the key in the UI
        const name = uid('CI key');
        await page.goto('/profile/api-keys');
        await page.getByTestId('create-api-key').click();
        await page.getByTestId('api-key-name').fill(name);
        await page.getByLabel('Chosen boards').check();
        await page.getByRole('group', { name: `${workspace.name} · ${project.name}` }).getByLabel(board.name, { exact: true }).check();
        await page.getByLabel('Issues & boards: Read & write', { exact: true }).check();
        await expect(page.getByLabel('Allow deleting')).not.toBeChecked();
        await page.getByRole('button', { name: 'Create key' }).click();

        const secretField = page.getByTestId('api-key-secret');
        await expect(secretField).toBeVisible();
        const secret = await secretField.inputValue();
        expect(secret).toMatch(/^brai_[A-Za-z0-9]{43}$/);
        await expect(page.getByTestId('api-key-row').filter({ hasText: name })).toBeVisible();

        const key = { Authorization: `Bearer ${secret}` };

        // the board: list, create, move
        const listed = await ok(await page.request.get(`/api/v1/boards/${board.id}/items?limit=10`, { headers: key }));
        expect(Array.isArray(listed.data)).toBe(true);
        const title = uid('Made with an API key');
        const created = (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: key, data: { title } }), 201)).data;
        expect(created).toMatchObject({ title, key: expect.stringMatching(new RegExp(`^${SEED.key}-\\d+$`)) });
        const moved = (await ok(await page.request.post(`/api/v1/items/${created.id}/move`, { headers: key, data: { column_id: inProgress.id } }))).data;
        expect(moved).toMatchObject({ id: created.id, status: 'In Progress' });

        // another project: out of the key's scope (the user can open it, so it's named)
        const denied = await page.request.get(`/api/v1/projects/${otherProject.id}`, { headers: key });
        expect(denied.status()).toBe(403);
        expect(await denied.json()).toMatchObject({ code: 'key_scope_denied', message: `This key has no access to project ${other.name}.` });

        // deleting needs the Delete permission
        const del = await page.request.delete(`/api/v1/items/${created.id}`, { headers: key });
        expect(del.status()).toBe(403);
        expect((await del.json()).code).toBe('key_ability_denied');

        // revoke in the UI → 401 at once
        await page.goto('/profile/api-keys');
        const row = page.getByTestId('api-key-row').filter({ hasText: name });
        await row.getByRole('button', { name: `Revoke ${name}` }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Revoke key' }).click();
        await expect(row.getByText('Revoked', { exact: true })).toBeVisible();

        const after = await page.request.get('/api/v1/me', { headers: key });
        expect(after.status()).toBe(401);
        expect((await after.json()).code).toBe('api_key_revoked');

        await page.request.post('/api/v1/auth/logout', { headers: device });
    });
});
