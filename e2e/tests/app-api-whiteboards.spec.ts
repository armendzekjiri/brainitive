import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath, request } from '../helpers/workload';

/*
 * App API v1 whiteboards, read-only (v1.49.0, BRAI-414): a whiteboard created through the API and drawn on in the
 * web editor reads back as its scene; an unchanged board answers 304 to its ETag.
 */

test.describe('app api: whiteboards', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('the scene drawn on the web reads back through the API', async ({ page }) => {
        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
        const board = (await ok(await page.request.post(`/api/v1/projects/${projectId}/pages`, { headers: auth, data: { name: uid('API board'), kind: 'whiteboard' } }), 201)).data;

        const elements = [{ id: 'sticky-1', type: 'text', x: 10, y: 20, width: 100, height: 30, text: 'Drawn on the web', isDeleted: false }];
        const saved = await request(page, 'POST', `${projectPath(SEED.project)}/pages/${board.uuid}/excalidraw/save`, {
            diagram_data: JSON.stringify({ elements, appState: { viewBackgroundColor: '#ffffff' }, files: {} }), force: true,
        });
        expect(saved.ok(), await saved.text()).toBeTruthy();

        const res = await page.request.get(`/api/v1/whiteboards/${board.uuid}`, { headers: auth });
        const scene = (await ok(res)).data;
        expect(scene).toMatchObject({ kind: 'whiteboard', scene: { elements: [{ id: 'sticky-1', text: 'Drawn on the web' }], app_state: { viewBackgroundColor: '#ffffff' } } });
        const again = await page.request.get(`/api/v1/whiteboards/${board.uuid}`, { headers: { ...auth, 'If-None-Match': res.headers().etag } });
        expect(again.status()).toBe(304);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
