import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { projectPath } from '../helpers/workload';

/*
 * App API v1 files (v1.49.0, BRAI-416): an image uploaded through the API (the web editor's rules and storage) goes
 * into a doc's Markdown; the published doc shows it on the web. An SVG is refused like on the web.
 */

// 1×1 transparent PNG
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');

test.describe('app api: uploads', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('an uploaded image goes into a doc and shows on the web', async ({ page }) => {
        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);

        const upload = (await ok(await page.request.post('/api/v1/uploads', {
            headers: auth, multipart: { workspace: SEED.workspace, file: { name: 'pixel.png', mimeType: 'image/png', buffer: PNG } },
        }), 201)).data;
        expect(upload).toMatchObject({ kind: 'image', name: 'pixel.png', mime_type: 'image/png' });
        expect(upload.url).toMatch(/\/storage\/uploads\/images\/[0-9a-f-]{36}\.png$/);
        expect((await page.request.get(upload.url)).status()).toBe(200);

        const svg = await page.request.post('/api/v1/uploads', {
            headers: { ...auth, Accept: 'application/json' },
            multipart: { workspace: SEED.workspace, file: { name: 'x.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>') } },
        });
        expect(svg.status()).toBe(422);

        const doc = (await ok(await page.request.post(`/api/v1/projects/${projectId}/pages`, {
            headers: auth, data: { name: uid('Doc with image'), markdown: `Before the picture\n\n${upload.markdown}`, publish: true },
        }), 201)).data;

        await page.goto(`${projectPath(SEED.project)}/pages/${doc.uuid}`);
        await expect(page.getByText('Before the picture').first()).toBeVisible();
        await expect(page.locator(`img[src$="${new URL(upload.url).pathname}"]`).first()).toBeAttached();

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
