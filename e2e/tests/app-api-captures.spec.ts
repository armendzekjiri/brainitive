import { expect, test, uid } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { ok, seedAppToken } from '../helpers/app-api';
import { captureCard, openCaptures } from '../helpers/quick-capture';

/*
 * App API v1 captures (v1.49.0, BRAI-398), as the desktop quick-capture window and the mobile share sheet
 * call them: an idea saved through the API is in the web's private inbox, organized through the API
 * into an issue on the seeded board (the web's convert logic) it moves to Processed there too.
 */

test.describe('app api: captures', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('an idea captured and organized through the API is the web inbox', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        const idea = uid('Tray idea');
        const capture = await ok(await page.request.post('/api/v1/captures', { headers: auth, data: { text: `${idea}\nfrom the desktop`, url: 'https://example.com/tray' } }), 201);
        expect(capture.data).toMatchObject({ state: 'inbox', source: 'Brainitive Desktop' });

        await openCaptures(page, idea);
        await expect(captureCard(page, idea)).toBeVisible();

        // the destinations picker has the seeded project's board
        const destinations = await ok(await page.request.get('/api/v1/capture-destinations', { headers: auth }));
        const workspace = destinations.data.find((w: { slug: string }) => w.slug === SEED.workspace);
        const board = workspace.projects.flatMap((p: { boards: { id: number; name: string }[] }) => p.boards).find((b: { name: string }) => b.name === 'Main Board');
        expect(board, 'the seeded main board').toBeTruthy();

        const preview = await ok(await page.request.get(`/api/v1/captures/${capture.data.id}/preview?type=issue`, { headers: auth }));
        expect(preview.data.fields.title).toBe(idea);
        const converted = await ok(await page.request.post(`/api/v1/captures/${capture.data.id}/convert`, {
            headers: auth, data: { type: 'issue', destination_id: board.id, fields: preview.data.fields },
        }), 201);
        expect(converted.data).toMatchObject({ replayed: false, capture: { state: 'processed', target: { type: 'issue', available: true } } });

        // gone from the web inbox, listed under Processed, and the issue opens
        await openCaptures(page, idea);
        await expect(captureCard(page, idea)).toHaveCount(0);
        await openCaptures(page, idea, 'processed');
        await expect(captureCard(page, idea)).toBeVisible();
        await page.goto(converted.data.location.url);
        await expect(page.getByText(idea).first()).toBeVisible();

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
