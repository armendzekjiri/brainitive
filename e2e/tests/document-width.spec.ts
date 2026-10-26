import { expect, test, uid, urls } from '../fixtures';
import { createDocPage } from '../helpers/custom-sidebar';
import { createBlankProject } from '../helpers/workspaces-projects';

/* Documents open full width by default (v1.42.0); "Exit full width" is remembered per document. */
test('a document opens full width and remembers "Exit full width"', async ({ page }) => {
    const project = await createBlankProject(page, 'e2e-workspace');
    const name = await createDocPage(page, project.slug, uid('Wide doc'));

    await page.goto(urls.project(project.slug));
    await page.getByRole('link', { name: new RegExp(name) }).first().click();
    await expect(page).toHaveURL(/\/pages\//);

    const exit = page.getByTitle('Exit full width');
    await expect(exit).toBeVisible();

    await exit.click();
    await expect(page.getByTitle('Full width', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByTitle('Full width', { exact: true })).toBeVisible();
});
