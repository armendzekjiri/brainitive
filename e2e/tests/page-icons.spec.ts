import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createBlankProject } from '../helpers/workspaces-projects';

/*
 * Uploaded page icons (v1.41.2): a doc's icon can be an uploaded image, stored as a long data: URL.
 * The workspace home and the project page printed it as text — a base64 string running across the
 * page. They must show the image.
 */

// an SVG icon, padded so the base64 is as long as a real upload's
const SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><path d="M8 0L16 16H0Z" fill="#0E83DC"/>${'<!-- padding -->'.repeat(60)}</svg>`;
const ICON = `data:image/svg+xml;base64,${Buffer.from(SVG).toString('base64')}`;
const CHUNK = ICON.slice(40, 80); // a piece of the base64 that must never appear as text

async function createDocWithIcon(page: Page, projectSlug: string, name: string) {
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const res = await page.request.post(`${urls.project(projectSlug)}/pages`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
        form: { name, type: 'page', icon: ICON },
    });
    expect(res.ok(), `create page: ${res.status()}`).toBeTruthy();
}

test('an uploaded page icon shows as an image, never as base64 text', async ({ page }) => {
    const project = await createBlankProject(page, 'e2e-workspace');
    const name = uid('Iconic doc');
    await createDocWithIcon(page, project.slug, name);

    for (const url of [urls.workspace(), urls.project(project.slug)]) {
        await page.goto(url);
        const link = page.getByRole('link', { name: new RegExp(name) }).first();
        await link.scrollIntoViewIfNeeded();
        await expect(link.locator('img[src^="data:image/svg+xml;base64,"]')).toHaveCount(1);
        await expect(page.locator('body')).not.toContainText(CHUNK);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow, `no sideways scroll on ${url}`).toBeLessThanOrEqual(1);
    }
});
