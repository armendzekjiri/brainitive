import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 docs (v1.49.0, BRAI-411), as the desktop / mobile app calls them: a doc created in a folder with
 * Markdown, edited with its revision (a stale one → 409), published — and the web's page shows the text, while
 * the tree lists it under its folder.
 */

test.describe('app api: docs', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a doc written through the API is the web page readers see', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const create = async (data: Record<string, unknown>) =>
            (await ok(await page.request.post(`/api/v1/projects/${projectId}/pages`, { headers: auth, data }), 201)).data;

        const folder = await create({ name: uid('API folder'), kind: 'folder' });
        const name = uid('API doc');
        const doc = await create({ name, parent_uuid: folder.uuid, markdown: '## From the app\n\n- first point' });
        expect(doc).toMatchObject({ kind: 'doc', status: 'draft', parent_uuid: folder.uuid, version: 0 });

        const edited = await ok(await page.request.patch(`/api/v1/pages/${doc.uuid}`, { headers: auth, data: { markdown: '## From the app\n\n- second point', revision: doc.revision } }));
        expect(edited.data.markdown).toContain('second point');
        const stale = await page.request.patch(`/api/v1/pages/${doc.uuid}`, { headers: auth, data: { markdown: 'lost', revision: doc.revision } });
        expect(stale.status()).toBe(409);

        const published = await ok(await page.request.post(`/api/v1/pages/${doc.uuid}/publish`, { headers: auth, data: { message: 'From the app' } }));
        expect(published.data).toMatchObject({ status: 'published', version: 1 });

        const level = await ok(await page.request.get(`/api/v1/projects/${projectId}/pages?parent=${folder.uuid}`, { headers: auth }));
        expect(level.data.map((p: { uuid: string }) => p.uuid)).toEqual([doc.uuid]);

        await page.goto(`${projectPath(SEED.project)}/pages/${doc.uuid}`);
        await expect(page.getByText('second point').first()).toBeVisible();
        await expect(page.getByText('first point')).toHaveCount(0);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
