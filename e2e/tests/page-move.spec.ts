import { type Locator, type Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createBlankProject } from '../helpers/workspaces-projects';
import { sidebar } from '../helpers/custom-sidebar';

/*
 * Moving pages, whiteboards and folders (BRAI-431, GitHub #130): sidebar drag & drop with drop
 * zones and a confirmation when the parent changes, and the "Move…" dialog from the sidebar row
 * menu and the page header. Every journey runs in a fresh project.
 *
 * HTML5 drag: Playwright's dragTo doesn't start it — mouse.down on the grip, move with steps, up.
 */

const WS = 'e2e-workspace';

interface Created { id: number; uuid: string; name: string }

async function create(page: Page, projectSlug: string, name: string, type: 'page' | 'folder', parent?: Created): Promise<Created> {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    const res = await page.request.post(`${urls.project(projectSlug, WS)}/pages`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value ?? ''), Accept: 'application/json' },
        data: { name, type, parent_id: parent?.id ?? null },
    });
    expect(res.ok(), `create ${type}: ${res.status()}`).toBeTruthy();
    const json = await res.json();
    return { id: json.id, uuid: json.uuid, name };
}

/** The header line of a page / folder in the sidebar tree. */
const row = (page: Page, name: string): Locator =>
    sidebar(page).locator('[data-page-row]').filter({ hasText: name }).first();

async function expand(page: Page, folder: string) {
    // the collapsible trigger is the first button of a folder row (then pin, ⋯, +)
    const trigger = row(page, folder).locator('button').first();
    if ((await trigger.getAttribute('data-state')) !== 'open') await trigger.click();
    await expect(trigger).toHaveAttribute('data-state', 'open');
}

/** Grab a row by its grip and move the pointer to a fraction of the target row's height (not released). */
async function dragOver(page: Page, source: Locator, target: Locator, fraction: number) {
    await source.hover();
    const grip = await source.locator('[data-drag-handle]').boundingBox();
    const box = await target.boundingBox();
    if (!grip || !box) throw new Error('rows not laid out');
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height * fraction, { steps: 12 });
}

async function setup(page: Page) {
    const project = await createBlankProject(page, WS);
    const meetings = await create(page, project.slug, uid('Meetings'), 'folder');
    const log = await create(page, project.slug, uid('Decision log'), 'page', meetings);
    const newFolder = await create(page, project.slug, uid('New Folder'), 'folder', meetings);
    await page.goto(urls.project(project.slug, WS));
    await expand(page, meetings.name);
    return { project, meetings, log, newFolder };
}

async function breadcrumbs(page: Page, projectSlug: string, item: Created) {
    await page.goto(`${urls.project(projectSlug, WS)}/pages/${item.uuid}`);
    return page.getByRole('navigation', { name: 'breadcrumb' });
}

test.describe('move pages and folders', () => {
    // tall enough that the tree isn't under the sidebar footer (drop targets must be hit-testable)
    test.use({ viewport: { width: 1280, height: 1100 } });

    test('reporter case: drop a doc onto a sibling folder, confirm — Cancel keeps it where it was', async ({ page }) => {
        const { project, meetings, log, newFolder } = await setup(page);
        const path = `${project.name} / ${meetings.name} / ${newFolder.name}`;

        // the middle of a folder row means "inside": highlighted folder + destination label
        await dragOver(page, row(page, log.name), row(page, newFolder.name), 0.5);
        await expect(row(page, newFolder.name)).toHaveAttribute('data-drop-zone', 'inside');
        await expect(sidebar(page).getByTestId('page-drop-label')).toHaveText(`Move into ${path}`);
        await page.mouse.up();

        const confirm = page.getByRole('alertdialog');
        await expect(confirm).toContainText(`Move “${log.name}” into ${path}?`);
        await confirm.getByRole('button', { name: 'Cancel' }).click();
        await expect(confirm).toBeHidden();
        // nothing moved: still directly under Meetings
        await expect(row(page, log.name)).toBeVisible();
        let crumbs = await breadcrumbs(page, project.slug, log);
        await expect(crumbs).toHaveText(new RegExp(`${meetings.name}\\s*${log.name}`));
        await expect(crumbs).not.toContainText(newFolder.name);

        // again, and confirm this time
        await page.goto(urls.project(project.slug, WS));
        await expand(page, meetings.name);
        await dragOver(page, row(page, log.name), row(page, newFolder.name), 0.5);
        await expect(sidebar(page).getByTestId('page-drop-label')).toBeVisible();
        await page.mouse.up();
        const moved = page.waitForResponse((r) => r.url().endsWith(`/pages/${log.uuid}/move`) && r.request().method() === 'POST');
        await page.getByRole('alertdialog').getByRole('button', { name: 'Move' }).click();
        expect((await moved).status()).toBe(200);
        await expect(page.getByText(`Moved "${log.name}" to ${path}`)).toBeVisible();

        crumbs = await breadcrumbs(page, project.slug, log);
        await expect(crumbs).toHaveText(new RegExp(`${meetings.name}\\s*${newFolder.name}\\s*${log.name}`));
    });

    test('reorder within a folder needs no confirmation', async ({ page }) => {
        const project = await createBlankProject(page, WS);
        const docs = await create(page, project.slug, uid('Docs'), 'folder');
        const one = await create(page, project.slug, uid('One'), 'page', docs);
        const two = await create(page, project.slug, uid('Two'), 'page', docs);
        const three = await create(page, project.slug, uid('Three'), 'page', docs);
        await page.goto(urls.project(project.slug, WS));
        await expand(page, docs.name);

        const docRows = sidebar(page).locator('[data-page-row]').filter({ hasText: /(One|Two|Three)/ });
        await expect(docRows).toHaveText([new RegExp(one.name), new RegExp(two.name), new RegExp(three.name)]);

        // top half of a doc row = before it: an insertion line, no path label (same parent)
        await dragOver(page, row(page, three.name), row(page, one.name), 0.2);
        await expect(row(page, one.name)).toHaveAttribute('data-drop-zone', 'before');
        await expect(row(page, one.name).getByTestId('page-drop-line')).toBeVisible();
        await expect(sidebar(page).getByTestId('page-drop-label')).toHaveCount(0);
        const saved = page.waitForResponse((r) => r.url().endsWith(`/pages/${three.uuid}/move`));
        await page.mouse.up();
        expect((await saved).status()).toBe(200);
        await expect(page.getByRole('alertdialog')).toHaveCount(0);
        await expect(docRows).toHaveText([new RegExp(three.name), new RegExp(one.name), new RegExp(two.name)]);

        await page.reload();
        await expand(page, docs.name);
        await expect(docRows).toHaveText([new RegExp(three.name), new RegExp(one.name), new RegExp(two.name)]);
    });

    test('Move… from the sidebar menu, with the keyboard', async ({ page }) => {
        const { project, meetings, newFolder } = await setup(page);
        const agenda = await create(page, project.slug, uid('Agenda'), 'page');
        await page.reload();

        await row(page, agenda.name).hover();
        await row(page, agenda.name).getByRole('button', { name: `More actions for ${agenda.name}` }).click();
        await page.getByRole('menuitem', { name: 'Move…' }).click();

        const dialog = page.getByRole('dialog', { name: `Move “${agenda.name}”` });
        await expect(dialog.getByRole('option', { name: /^Top level/ })).toHaveAttribute('aria-disabled', 'true');
        await expect(dialog.getByRole('option', { name: /^Top level/ })).toContainText('Current location');
        await expect(dialog.getByRole('button', { name: 'Move' })).toBeDisabled();

        const search = dialog.getByRole('combobox', { name: 'Search folders' });
        await search.fill(newFolder.name);
        await search.press('ArrowDown');
        await expect(dialog.getByTestId('move-destination-path')).toHaveText(`${project.name} / ${meetings.name} / ${newFolder.name}`);
        const moved = page.waitForResponse((r) => r.url().endsWith(`/pages/${agenda.uuid}/move`));
        await search.press('Enter');
        expect((await moved).status()).toBe(200);
        await expect(dialog).toBeHidden();

        const crumbs = await breadcrumbs(page, project.slug, agenda);
        await expect(crumbs).toHaveText(new RegExp(`${meetings.name}\\s*${newFolder.name}\\s*${agenda.name}`));
    });

    test('Move… from the page header back to the top level', async ({ page }) => {
        const { project, meetings, log } = await setup(page);
        await page.goto(`${urls.project(project.slug, WS)}/pages/${log.uuid}`);
        await page.getByRole('button', { name: 'More actions', exact: true }).click();
        await page.getByRole('menuitem', { name: 'Move…' }).click();

        const dialog = page.getByRole('dialog', { name: `Move “${log.name}”` });
        await expect(dialog.getByRole('option', { name: new RegExp(meetings.name) })).toContainText('Current location');
        await dialog.getByRole('option', { name: /^Top level/ }).click();
        await expect(dialog.getByTestId('move-destination-path')).toHaveText(project.name);
        await dialog.getByRole('button', { name: 'Move' }).click();
        await expect(dialog).toBeHidden();

        const crumbs = page.getByRole('navigation', { name: 'breadcrumb' });
        await expect(crumbs).not.toContainText(meetings.name);
        await expect(crumbs).toContainText(log.name);
    });

    test('a folder can not go into its own subfolder', async ({ page }) => {
        const { meetings, newFolder } = await setup(page);

        // drag: no drop zone on its own subfolder
        await dragOver(page, row(page, meetings.name), row(page, newFolder.name), 0.5);
        await expect(sidebar(page).getByTestId('page-drop-label')).toHaveCount(0);
        await expect(row(page, newFolder.name)).not.toHaveAttribute('data-drop-zone', /.+/);
        await page.mouse.up();
        await expect(page.getByRole('alertdialog')).toHaveCount(0);

        // dialog: the subfolder is disabled, the folder itself too (fresh page: WebKit's emulated
        // drag can leave the pointer state behind and swallow the next menu open)
        await page.reload();
        await expand(page, meetings.name);
        await row(page, meetings.name).hover();
        await row(page, meetings.name).getByRole('button', { name: `More actions for ${meetings.name}` }).click();
        await page.getByRole('menuitem', { name: 'Move…' }).click();
        const dialog = page.getByRole('dialog', { name: `Move “${meetings.name}”` });
        const sub = dialog.getByRole('option', { name: new RegExp(newFolder.name) });
        await expect(sub).toHaveAttribute('aria-disabled', 'true');
        await expect(sub).toContainText('Inside this folder');
        await expect(dialog.getByRole('option', { name: new RegExp(meetings.name) })).toContainText('This item');
        // disabled options can't be chosen, with the mouse (force: Playwright waits for aria-disabled) or the keyboard
        await sub.click({ force: true });
        await expect(dialog.getByTestId('move-destination-path')).toHaveText('Choose a folder');
        await expect(dialog.getByRole('button', { name: 'Move' })).toBeDisabled();
        await dialog.getByRole('combobox', { name: 'Search folders' }).press('ArrowDown');
        await expect(dialog.getByTestId('move-destination-path')).toHaveText('Choose a folder');
    });
});
