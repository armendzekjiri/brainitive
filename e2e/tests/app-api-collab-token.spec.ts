import { expect, test, uid } from '../fixtures';
import { LIVE, REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 live co-editing for native clients (v1.49.0, BRAI-412): the token names the same live document the
 * browser opens, and a native client's bootstrap + live draft save (the browser's own calls, PageCollab) is the
 * draft that goes live on publish. Opted in to live editing like live-docs (`X-E2E-Collab`), on the run's realtime
 * server (LIVE.port).
 */

test.describe('app api: live editing token', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a native client joins the live document and its save goes live on publish', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}`, 'X-E2E-Collab': '1', 'X-E2E-Collab-Port': String(LIVE.port) };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);

        const doc = (await ok(await page.request.post(`/api/v1/projects/${projectId}/pages`, { headers: auth, data: { name: uid('Live API doc'), markdown: 'Start text', publish: true } }), 201)).data;
        expect((await ok(await page.request.get(`/api/v1/pages/${doc.uuid}`, { headers: auth }))).data.live).toBe('edit');

        const session = (await ok(await page.request.post(`/api/v1/pages/${doc.uuid}/collab-token`, { headers: auth }))).data;
        expect(session).toMatchObject({ document: `page.${doc.uuid}.${session.epoch}`, edit: true, url: `ws://127.0.0.1:${LIVE.port}` });
        expect(session.token).toContain('.');

        const claim = (await ok(await page.request.post(`/api/v1/pages/${doc.uuid}/collab/bootstrap`, { headers: auth, data: { epoch: session.epoch, claim: `e2e-${Date.now()}-claim` } }))).data;
        expect(claim.status).toBe('granted');
        expect(claim.html).toContain('Start text');

        const base = { epoch: session.epoch, name: doc.name, icon: doc.icon };
        await ok(await page.request.put(`/api/v1/pages/${doc.uuid}/collab/draft`, { headers: auth, data: { ...base, html: claim.html, vector: { 7: 1 }, baseline: true } }));
        const saved = (await ok(await page.request.put(`/api/v1/pages/${doc.uuid}/collab/draft`, { headers: auth, data: { ...base, html: '<p>Typed in the native editor</p>', vector: { 7: 4 } } }))).data;
        expect(saved).toMatchObject({ status: 'saved', has_unpublished_changes: true });

        // the live draft is the doc's unpublished changes: published, the web page shows it
        const published = (await ok(await page.request.post(`/api/v1/pages/${doc.uuid}/publish`, { headers: auth, data: { revision: (await ok(await page.request.get(`/api/v1/pages/${doc.uuid}`, { headers: auth }))).data.revision } }))).data;
        expect(published).toMatchObject({ version: 2, markdown: 'Typed in the native editor' });
        await page.goto(`${projectPath(SEED.project)}/pages/${doc.uuid}`);
        await expect(page.getByText('Typed in the native editor').first()).toBeVisible();

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
