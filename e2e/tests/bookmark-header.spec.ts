import { type Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { memberId } from '../helpers/time-overview';
import { request } from '../helpers/workload';

/*
 * Bookmarks (GitHub #152): the description and the collaborators live in the page header — a muted
 * subtitle under the title (click to edit) and an avatar stack next to Private / Shared that opens the
 * people popover. The left column only holds "Add to bookmark".
 */

const WS = 'e2e-workspace';
const OWNER = 'Erin Owner';
const MEMBER = 'Max Member';

async function createCollection(page: Page): Promise<{ name: string; url: string }> {
    const name = uid('Offsite reading');
    await page.goto(`${urls.workspace(WS)}/bookmarks`);
    await page.getByRole('button', { name: 'Add Bookmark' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New bookmark collection' });
    await dialog.getByLabel('Name').fill(name);
    await dialog.getByRole('button', { name: 'Create' }).click();
    await expect(page).toHaveURL(/\/bookmarks\/[^/]+$/);
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
    return { name, url: new URL(page.url()).pathname };
}

// the page's own header (inside the app's <main>, so not a banner landmark)
const header = (page: Page) => page.locator('header').filter({ has: page.getByRole('heading', { level: 1 }) });

test.describe('bookmark header details', () => {
    test('description and collaborators are managed from the header', async ({ page }) => {
        await createCollection(page);

        // the left column only has "Add to bookmark"; no Collaborators / Description cards
        const aside = page.locator('aside').filter({ has: page.getByRole('heading', { name: 'Add to bookmark' }) });
        await expect(aside.getByRole('heading')).toHaveText(['Add to bookmark']);
        await expect(page.getByRole('heading', { name: 'Collaborators', exact: true })).toHaveCount(0);
        await expect(page.getByRole('heading', { name: 'Description', exact: true })).toHaveCount(0);

        // description: placeholder → edit → muted subtitle
        const description = `Read before the offsite ${uid('d')}`;
        await header(page).getByRole('button', { name: 'Add a description', exact: true }).click();
        await header(page).getByRole('textbox', { name: 'Description' }).fill(description);
        await header(page).getByRole('button', { name: 'Save', exact: true }).click();
        await expect(header(page).getByRole('button', { name: 'Edit description', exact: true })).toHaveText(description);

        // the owner is alone: "Invite collaborators" opens the people popover
        await header(page).getByRole('button', { name: 'Invite collaborators', exact: true }).click();
        const people = page.getByRole('dialog');
        await expect(people.getByRole('list', { name: 'Collaborators' })).toContainText(`${OWNER}`);
        await expect(people.getByRole('list', { name: 'Collaborators' })).toContainText('Owner');
        await people.getByRole('combobox', { name: 'Person to invite' }).click();
        await page.getByRole('option', { name: MEMBER, exact: true }).click();
        await people.getByRole('combobox', { name: 'Role for the invite' }).click();
        await page.getByRole('option', { name: 'Editor', exact: true }).click();
        await people.getByRole('button', { name: 'Invite', exact: true }).click();

        // the stack shows both, the role is listed and can change
        await expect(people.getByRole('combobox', { name: `Role of ${MEMBER}` })).toHaveText('Editor');
        await page.keyboard.press('Escape');
        const stack = header(page).getByRole('button', { name: `Collaborators: ${OWNER}, ${MEMBER}`, exact: true });
        await expect(stack).toBeVisible();

        await page.reload();
        await stack.click();
        await page.getByRole('dialog').getByRole('combobox', { name: `Role of ${MEMBER}` }).click();
        await page.getByRole('option', { name: 'Viewer', exact: true }).click();
        await expect(page.getByRole('dialog').getByRole('combobox', { name: `Role of ${MEMBER}` })).toHaveText('Viewer');

        // remove asks first
        await page.getByRole('dialog').getByRole('button', { name: `Remove ${MEMBER}`, exact: true }).click();
        const confirm = page.getByRole('alertdialog');
        await expect(confirm.getByRole('heading', { name: 'Remove collaborator?' })).toBeVisible();
        await confirm.getByRole('button', { name: 'Remove', exact: true }).click();
        await expect(confirm).toBeHidden();
        await expect(header(page).getByRole('button', { name: 'Invite collaborators', exact: true })).toBeVisible();
        await expect(stack).toHaveCount(0);
    });

    test('a viewer reads the description and the people, without controls', async ({ page, memberPage }) => {
        const collection = await createCollection(page);
        const description = `Shared links ${uid('d')}`;
        await header(page).getByRole('button', { name: 'Add a description', exact: true }).click();
        await header(page).getByRole('textbox', { name: 'Description' }).fill(description);
        await header(page).getByRole('button', { name: 'Save', exact: true }).click();
        await expect(header(page).getByRole('button', { name: 'Edit description', exact: true })).toHaveText(description);
        const added = await request(page, 'POST', `${collection.url}/collaborators`, { user_id: await memberId(page), role: 'viewer' });
        expect(added.status(), 'invite the member as viewer').toBeLessThan(400);

        await memberPage.goto(collection.url);
        await expect(header(memberPage).getByTestId('bookmark-description')).toHaveText(description);
        await expect(header(memberPage).getByRole('button', { name: 'Edit description' })).toHaveCount(0);
        await expect(memberPage.getByRole('heading', { name: 'Add to bookmark' })).toHaveCount(0);

        await header(memberPage).getByRole('button', { name: `Collaborators: ${OWNER}, ${MEMBER}`, exact: true }).click();
        const list = memberPage.getByRole('dialog').getByRole('list', { name: 'Collaborators' });
        await expect(list.getByRole('listitem')).toHaveCount(2);
        await expect(list.getByRole('listitem').nth(0)).toContainText('Owner');
        await expect(list.getByRole('listitem').nth(1)).toContainText('Viewer');
        await expect(memberPage.getByRole('dialog').getByRole('combobox')).toHaveCount(0);
        await expect(memberPage.getByRole('dialog').getByRole('button', { name: /^Remove / })).toHaveCount(0);
    });

    test('phone width: no horizontal scroll, header keeps title, description and controls', async ({ page }) => {
        await createCollection(page);
        await header(page).getByRole('button', { name: 'Add a description', exact: true }).click();
        await header(page).getByRole('textbox', { name: 'Description' }).fill('A fairly long description that has to wrap on a phone screen without pushing anything sideways');
        await header(page).getByRole('button', { name: 'Save', exact: true }).click();
        await expect(header(page).getByRole('button', { name: 'Edit description', exact: true })).toBeVisible();

        await page.setViewportSize({ width: 390, height: 844 });
        await page.reload();
        await expect(header(page).getByRole('heading', { level: 1 })).toBeVisible();
        await expect(header(page).getByRole('button', { name: 'Edit description', exact: true })).toBeVisible();
        await expect(header(page).getByRole('button', { name: 'Invite collaborators', exact: true })).toBeVisible();
        await expect(header(page).getByRole('combobox')).toBeVisible();
        // the app scrolls inside <main>: check the document and every scroll container on the way
        const overflow = await page.evaluate(() => Math.max(
            ...[document.documentElement, ...Array.from(document.querySelectorAll('main'))].map((el) => el.scrollWidth - el.clientWidth),
        ));
        expect(overflow, 'horizontal overflow in px').toBeLessThanOrEqual(0);
    });
});
