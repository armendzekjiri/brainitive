import { type Locator, type Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createBlankProject } from '../helpers/workspaces-projects';
import { sidebar } from '../helpers/custom-sidebar';
import { memberId } from '../helpers/time-overview';
import { request } from '../helpers/workload';

/*
 * The real menu on docs, whiteboards and folders in the sidebar (GitHub #155): on the row's ⋯ and on
 * right-click — Open, Open in new tab, Copy link, Rename…, Duplicate, Move…, Delete — with the page
 * menu's confirmation and permissions. Every journey runs in a fresh project.
 */

const WS = 'e2e-workspace';

interface Created { id: number; uuid: string; name: string }

async function create(page: Page, projectSlug: string, name: string, type: 'page' | 'folder', parent?: Created): Promise<Created> {
    const res = await request(page, 'POST', `${urls.project(projectSlug, WS)}/pages`, { name, type, parent_id: parent?.id ?? null });
    expect(res.ok(), `create ${type}: ${res.status()}`).toBeTruthy();
    const json = await res.json();
    return { id: json.id, uuid: json.uuid, name };
}

/** The header line of a page / folder in the sidebar tree. */
const row = (page: Page, name: string): Locator =>
    sidebar(page).locator('[data-page-row]').filter({ hasText: name }).first();

const menu = (page: Page): Locator => page.getByRole('menu');

async function rightClick(page: Page, name: string): Promise<Locator> {
    await row(page, name).click({ button: 'right' });
    await expect(menu(page)).toBeVisible();
    return menu(page);
}

async function moreActions(page: Page, name: string): Promise<Locator> {
    await row(page, name).hover();
    await row(page, name).getByRole('button', { name: `More actions for ${name}`, exact: true }).click();
    await expect(menu(page)).toBeVisible();
    return menu(page);
}

async function entries(menuLocator: Locator): Promise<string[]> {
    return (await menuLocator.getByRole('menuitem').allInnerTexts()).map((text) => text.trim());
}

test.describe('sidebar item menu', () => {
    // tall enough that the tree isn't under the sidebar footer
    test.use({ viewport: { width: 1280, height: 1100 } });

    test('rename a doc from its ⋯ menu', async ({ page }) => {
        const project = await createBlankProject(page, WS);
        const doc = await create(page, project.slug, uid('Kickoff notes'), 'page');
        await page.goto(urls.project(project.slug, WS));

        const actions = await moreActions(page, doc.name);
        expect(await entries(actions)).toEqual(['Open', 'Open in new tab', 'Copy link', 'Rename…', 'Duplicate', 'Move…', 'Delete']);
        await actions.getByRole('menuitem', { name: 'Rename…', exact: true }).click();

        const dialog = page.getByRole('dialog', { name: 'Rename page' });
        const renamed = uid('Kickoff summary');
        await dialog.getByLabel('Name').fill(renamed);
        await dialog.getByRole('button', { name: 'Rename', exact: true }).click();

        await expect(dialog).toBeHidden();
        await expect(page.getByText(`Renamed to "${renamed}"`)).toBeVisible();
        await expect(row(page, renamed)).toBeVisible();
        await expect(sidebar(page).locator('[data-page-row]').filter({ hasText: doc.name })).toHaveCount(0);
        // the user stays on the project page
        await expect(page).toHaveURL(new RegExp(`/projects/${project.slug}$`));
    });

    test('right-click: open, copy link and duplicate a doc', async ({ page, browserName }) => {
        const project = await createBlankProject(page, WS);
        const doc = await create(page, project.slug, uid('Roadmap'), 'page');
        await page.goto(urls.project(project.slug, WS));
        const docUrl = `${urls.project(project.slug, WS)}/pages/${doc.uuid}`;

        if (browserName === 'chromium') await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
        await (await rightClick(page, doc.name)).getByRole('menuitem', { name: 'Copy link', exact: true }).click();
        await expect(page.getByText('Link copied')).toBeVisible();
        if (browserName === 'chromium') {
            expect(await page.evaluate(() => navigator.clipboard.readText())).toContain(`/pages/${doc.uuid}`);
        }

        await (await rightClick(page, doc.name)).getByRole('menuitem', { name: 'Open', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/pages/${doc.uuid}$`));
        expect(new URL(page.url()).pathname).toBe(new URL(docUrl, page.url()).pathname);

        // like the page's own menu, the copy opens once it is made
        await (await rightClick(page, doc.name)).getByRole('menuitem', { name: 'Duplicate', exact: true }).click();
        await expect(page).not.toHaveURL(new RegExp(`/pages/${doc.uuid}$`));
        await expect(row(page, `Copy of ${doc.name}`)).toBeVisible();
        await expect(row(page, doc.name)).toBeVisible();
    });

    test('delete from right-click asks first and stays on the current page', async ({ page }) => {
        const project = await createBlankProject(page, WS);
        const keep = await create(page, project.slug, uid('Keep me'), 'page');
        const folder = await create(page, project.slug, uid('Old stuff'), 'folder');
        await create(page, project.slug, uid('Old notes'), 'page', folder);
        await page.goto(`${urls.project(project.slug, WS)}/pages/${keep.uuid}`);

        await (await rightClick(page, folder.name)).getByRole('menuitem', { name: 'Delete', exact: true }).click();
        const confirm = page.getByRole('alertdialog');
        await expect(confirm.getByRole('heading', { name: 'Delete folder?' })).toBeVisible();
        await expect(confirm).toContainText(`Are you sure you want to delete “${folder.name}”?`);
        await expect(confirm).toContainText('This will also delete all 1 subpage(s) inside.');

        // Cancel keeps it
        await confirm.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(confirm).toBeHidden();
        await expect(row(page, folder.name)).toBeVisible();

        await (await rightClick(page, folder.name)).getByRole('menuitem', { name: 'Delete', exact: true }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
        await expect(page.getByText(`Deleted "${folder.name}"`)).toBeVisible();
        await expect(sidebar(page).locator('[data-page-row]').filter({ hasText: folder.name })).toHaveCount(0);
        await expect(page).toHaveURL(new RegExp(`/pages/${keep.uuid}$`));
    });

    test('deleting the open doc goes to the project', async ({ page }) => {
        const project = await createBlankProject(page, WS);
        const doc = await create(page, project.slug, uid('Scratch'), 'page');
        await page.goto(`${urls.project(project.slug, WS)}/pages/${doc.uuid}`);

        await (await moreActions(page, doc.name)).getByRole('menuitem', { name: 'Delete', exact: true }).click();
        await expect(page.getByRole('alertdialog').getByRole('heading', { name: 'Delete page?' })).toBeVisible();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();

        await expect(page).toHaveURL(new RegExp(`/projects/${project.slug}$`));
        await expect(sidebar(page).locator('[data-page-row]').filter({ hasText: doc.name })).toHaveCount(0);
    });

    test('a viewer gets Open and Copy link only', async ({ page, memberPage }) => {
        const project = await createBlankProject(page, WS);
        const doc = await create(page, project.slug, uid('Handbook'), 'page');
        const published = await request(page, 'POST', `${urls.project(project.slug, WS)}/pages/${doc.uuid}/publish`, {});
        expect(published.status(), 'publish').toBeLessThan(400);
        const added = await request(page, 'POST', `${urls.project(project.slug, WS)}/members`, { user_id: await memberId(page), role: 'viewer' });
        expect(added.status(), 'add the member as viewer').toBeLessThan(400);

        await memberPage.setViewportSize({ width: 1280, height: 1100 });
        await memberPage.goto(urls.project(project.slug, WS));
        expect(await entries(await rightClick(memberPage, doc.name))).toEqual(['Open', 'Open in new tab', 'Copy link']);
        await memberPage.keyboard.press('Escape');
        await expect(menu(memberPage)).toBeHidden();
        expect(await entries(await moreActions(memberPage, doc.name))).toEqual(['Open', 'Open in new tab', 'Copy link']);
    });
});
