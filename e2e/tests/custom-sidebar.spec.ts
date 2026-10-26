import { expect, test, uid, urls } from '../fixtures';
import { createBlankProject } from '../helpers/workspaces-projects';
import {
    createDocPage, createTaskList, mainNav, openCustomize, pinnedGroup, resetSidebarPreferences, sidebar, sidebarSaved, toggle,
} from '../helpers/custom-sidebar';

/*
 * Customisable sidebar + project page order (BRAI-106). Hiding top-level entries is per user, so
 * that journey runs as the member and resets in `finally`; everything per project runs in a fresh
 * project, so parallel specs keep seeing the default sidebar of the seeded one.
 */

test.describe('customisable sidebar', () => {
    test('hide Calendar: it is gone from the sidebar after a reload, still in ⌘K, and reset brings it back', async ({ memberPage: page }) => {
        await page.goto(urls.workspace());
        await resetSidebarPreferences(page);
        try {
            await page.reload();
            await expect(mainNav(page).getByRole('link', { name: 'Calendar', exact: true })).toBeVisible();

            const panel = await openCustomize(page);
            // Home and Inbox are always shown
            await expect(panel.getByRole('switch', { name: 'Home', exact: true })).toHaveCount(0);
            await expect(panel.getByRole('switch', { name: 'Inbox', exact: true })).toHaveCount(0);
            await expect(panel.getByText('Always shown')).toHaveCount(2);
            await toggle(page, panel, 'Calendar', false);
            await panel.getByRole('button', { name: 'Done' }).click();
            await expect(panel).toBeHidden();

            await expect(mainNav(page).getByRole('link', { name: 'Calendar', exact: true })).toHaveCount(0);
            await page.reload();
            await expect(mainNav(page).getByRole('link', { name: 'Calendar', exact: true })).toHaveCount(0);
            await expect(mainNav(page).getByRole('link', { name: 'Home', exact: true })).toBeVisible();
            await expect(mainNav(page).getByRole('link', { name: 'Inbox', exact: true })).toBeVisible();

            // still reachable from the command palette
            await page.keyboard.press('Control+k');
            await page.getByRole('combobox', { name: 'Search or type a command' }).fill('calendar');
            await page.getByRole('option', { name: /Go to Calendar/ }).click();
            await expect(page).toHaveURL(/\/calendar/);

            // reset to default, from the account menu this time
            await page.getByRole('button', { name: /Max Member/ }).last().click();
            await page.getByRole('menuitem', { name: 'Customize sidebar' }).click();
            const again = page.getByRole('dialog', { name: 'Customize sidebar' });
            const saved = sidebarSaved(page);
            await again.getByRole('button', { name: 'Reset to default' }).click();
            await saved;
            await expect(again.getByRole('switch', { name: 'Calendar', exact: true })).toHaveAttribute('aria-checked', 'true');
            await again.getByRole('button', { name: 'Done' }).click();
            await expect(mainNav(page).getByRole('link', { name: 'Calendar', exact: true })).toBeVisible();
            await page.reload();
            await expect(mainNav(page).getByRole('link', { name: 'Calendar', exact: true })).toBeVisible();
        } finally {
            await resetSidebarPreferences(page);
        }
    });

    test('hide the Lists group of a project: the sidebar links to the project page instead', async ({ page }) => {
        const project = await createBlankProject(page, 'e2e-workspace');
        const list = await createTaskList(page, project.slug, uid('Errands'));

        await page.goto(urls.project(project.slug));
        await expect(sidebar(page).getByRole('link', { name: list, exact: true })).toBeVisible();
        await expect(sidebar(page).getByRole('link', { name: 'Show all in project' })).toHaveCount(0);

        const panel = await openCustomize(page);
        await expect(panel.getByText(project.name)).toBeVisible();
        await toggle(page, panel, 'Lists', false);
        await page.keyboard.press('Escape');

        await expect(sidebar(page).getByRole('button', { name: 'Lists', exact: true })).toHaveCount(0);
        await expect(sidebar(page).getByRole('link', { name: list, exact: true })).toHaveCount(0);
        await expect(sidebar(page).getByRole('link', { name: 'Main Board', exact: true })).toBeVisible();
        await page.reload();
        await expect(sidebar(page).getByRole('link', { name: list, exact: true })).toHaveCount(0);

        // the project page still has everything
        await sidebar(page).getByRole('link', { name: 'Show all in project' }).click();
        await expect(page).toHaveURL(new RegExp(`${urls.project(project.slug)}$`));
        await expect(page.getByRole('link').filter({ has: page.getByRole('heading', { level: 3, name: list, exact: true }) })).toBeVisible();
    });

    test('pin a page: it shows under Pinned in the sidebar and on the project page, then unpin it', async ({ page }) => {
        const project = await createBlankProject(page, 'e2e-workspace');
        const doc = await createDocPage(page, project.slug, uid('Runbook'));

        await page.goto(urls.project(project.slug));
        await expect(pinnedGroup(page)).toHaveCount(0);
        await expect(sidebar(page).getByRole('button', { name: 'Boards', exact: true })).toBeVisible();

        // pin it from the project page (Docs → Pages)
        const saved = sidebarSaved(page);
        await page.getByRole('main').getByRole('button', { name: `Pin ${doc}`, exact: true }).click();
        await saved;

        const pinnedItems = page.getByRole('list', { name: 'Pinned items' });
        await expect(pinnedItems.getByRole('link', { name: new RegExp(doc) })).toBeVisible();
        await expect(pinnedGroup(page).getByRole('link', { name: doc, exact: true })).toBeVisible();
        // with pins, the other groups collapse into a link to the project page
        await expect(sidebar(page).getByRole('button', { name: 'Boards', exact: true })).toHaveCount(0);
        await expect(sidebar(page).getByRole('link', { name: 'Show all in project' })).toBeVisible();

        await page.reload();
        await expect(pinnedGroup(page).getByRole('link', { name: doc, exact: true })).toBeVisible();

        // unpin from the sidebar row
        await pinnedGroup(page).getByRole('link', { name: doc, exact: true }).hover();
        const unpinned = sidebarSaved(page);
        await pinnedGroup(page).getByRole('button', { name: `Unpin ${doc}`, exact: true }).click();
        await unpinned;
        await expect(pinnedGroup(page)).toHaveCount(0);
        await expect(sidebar(page).getByRole('button', { name: 'Boards', exact: true })).toBeVisible();
        await expect(pinnedItems).toHaveCount(0);
    });

    test('"Show only pinned" collapses the project groups', async ({ page }) => {
        const project = await createBlankProject(page, 'e2e-workspace');
        await page.goto(urls.project(project.slug));

        const panel = await openCustomize(page);
        await toggle(page, panel, 'Show only pinned', true);
        await page.keyboard.press('Escape');

        await expect(pinnedGroup(page)).toContainText('Nothing pinned yet');
        await expect(sidebar(page).getByRole('link', { name: 'Main Board', exact: true })).toHaveCount(0);
        await expect(sidebar(page).getByRole('button', { name: 'Add content' })).toBeVisible();

        // pin the board from its card on the project page
        await page.getByRole('main').getByRole('button', { name: 'Pin Main Board', exact: true }).click();
        await expect(pinnedGroup(page).getByRole('link', { name: 'Main Board', exact: true })).toBeVisible();
    });
});

test.describe('project page', () => {
    test('sections come in a fixed order, empty ones are one compact row', async ({ page }) => {
        const project = await createBlankProject(page, 'e2e-workspace');
        const doc = await createDocPage(page, project.slug, uid('Kickoff notes'));
        await page.goto(urls.project(project.slug));

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: project.name })).toBeVisible();
        await expect(main.getByRole('heading', { level: 2 })).toHaveText(['Pinned', 'Recently updated', 'Work', 'Docs', 'Customer-facing', 'Tools']);
        await expect(main.getByRole('heading', { level: 3 })).toContainText(['Boards', 'Main Board', 'Lists', 'Plans', 'Pages', 'Whiteboards', 'Helpdesks', 'Lead forms']);

        // quick links in the header
        await expect(main.getByRole('navigation', { name: 'Project tools' }).getByRole('link')).toHaveText(['Health', 'Workload', 'Releases', 'Client portal', 'Feedback']); // the owner is a project admin: Feedback (BRAI-60) shows
        await expect(main.getByRole('link', { name: 'Project health' })).toHaveAttribute('href', new RegExp(`/projects/${project.slug}/health$`));

        // recently updated: the new page and the board
        const recent = main.getByRole('list', { name: 'Recently updated' });
        await expect(recent.getByRole('link', { name: new RegExp(doc) })).toBeVisible();
        await expect(recent.getByRole('link', { name: /Main Board/ })).toBeVisible();

        // an empty section is one row with its Add button
        const lists = main.getByRole('heading', { name: 'Lists', exact: true }).locator('..');
        await expect(lists).toContainText('Simple task lists');
        await lists.getByRole('button', { name: 'Add' }).click();
        await expect(page.getByRole('dialog', { name: 'Create List' })).toBeVisible();
    });
});
