import { expect, test, uid } from '../fixtures';
import { SEED } from '../env';
import { projectWithIssue } from '../helpers/time-overview';

/** Two different 1×1 PNGs (red, blue). */
const RED = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC', 'base64');
const BLUE = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYPgPAAEDAQAIicLsAAAAAElFTkSuQmCC', 'base64');

/**
 * Replacing a custom project icon (GitHub #154): every upload gets its own file name, so the URL
 * changes (the settings form sees a change, browsers / CDNs don't keep the old image) and the
 * superseded file is gone.
 */
test.describe('project icon', () => {
    test('uploading a second icon gives a new URL and removes the old file', async ({ page }) => {
        const project = await projectWithIssue(page, uid('Icon issue'));
        const upload = async (name: string, buffer: Buffer): Promise<string> => {
            const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
            const response = await page.request.post(`/workspaces/${SEED.workspace}/projects/${project.slug}/icon`, {
                headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' },
                multipart: { icon: { name, mimeType: 'image/png', buffer } },
            });
            expect(response.status(), await response.text()).toBe(200);
            return (await response.json()).icon_display as string;
        };

        const first = await upload('red.png', RED);
        expect((await page.request.get(first)).status()).toBe(200);

        const second = await upload('blue.png', BLUE);
        expect(second).not.toBe(first);
        const served = await page.request.get(second);
        expect(served.status()).toBe(200);
        expect(Buffer.from(await served.body()).equals(BLUE)).toBe(true);
        expect((await page.request.get(first)).status()).toBeGreaterThanOrEqual(400); // the superseded file is gone (403/404 by server)

        // the settings page shows the new icon
        await page.goto(`/workspaces/${SEED.workspace}/projects/${project.slug}/edit`);
        await expect(page.locator(`img[src$="${new URL(second, page.url()).pathname}"]`).first()).toBeVisible();
    });
});
