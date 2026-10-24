import type { Page } from '@playwright/test';
import { expect, test, urls } from '../fixtures';
import { send } from '../helpers/backlinks';
import { createWorkspace } from '../helpers/workspaces-projects';

/**
 * Billing → Activities (BRAI-99): a workspace admin defines what the team bills (per hour or a
 * fixed price), picks the default activity per issue type and archives one. Members get 403 and
 * never see the Billing link.
 */

const activitiesUrl = (workspace: string) => `${urls.workspace(workspace)}/billing/activities`;

async function createActivity(page: Page, name: string, price: string, pricing: 'Per hour' | 'Fixed price') {
    await page.getByRole('button', { name: 'New activity' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New activity' });
    await dialog.getByLabel('Name').fill(name);
    await dialog.getByRole('radio', { name: new RegExp(pricing) }).click();
    await dialog.getByLabel(pricing === 'Per hour' ? 'Price per hour' : 'Fixed price').fill(price);
    await dialog.getByRole('button', { name: 'Create activity' }).click();
    await expect(dialog).toBeHidden();
}

test.describe('billing activities', () => {
    test('the owner prices activities, sets a default per issue type and archives one', async ({ page }) => {
        // an own workspace (its creator is the admin), so the activities are only ours
        const workspace = await createWorkspace(page);

        // the sidebar's Billing link opens the first billing tab (Reports once BRAI-101 is in); then Activities
        await page.getByRole('link', { name: 'Billing', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/workspaces/${workspace.slug}/billing/\\w+$`));
        await page.getByRole('link', { name: 'Activities', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/workspaces/${workspace.slug}/billing/activities$`));
        await expect(page.getByRole('link', { name: 'Activities', exact: true })).toHaveAttribute('aria-current', 'page');

        // no templates: the empty state explains what to create
        await expect(page.getByText('No activities yet')).toBeVisible();
        await expect(page.getByText(/Create what your team bills, like Programming/)).toBeVisible();

        await createActivity(page, 'Programming', '120', 'Per hour');
        await createActivity(page, 'SSM setup', '450', 'Fixed price');

        const list = page.getByRole('list', { name: 'Activities', exact: true });
        const programming = list.getByRole('listitem').filter({ hasText: 'Programming' });
        const ssm = list.getByRole('listitem').filter({ hasText: 'SSM setup' });
        await expect(programming).toContainText('Per hour');
        await expect(programming).toContainText(/120\.00 \/ h/);
        await expect(ssm).toContainText('Fixed price');
        await expect(ssm).toContainText(/450\.00 flat/);

        // names are unique per workspace (case-insensitive): a message, not an error page
        await page.getByRole('button', { name: 'New activity' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'New activity' });
        await dialog.getByLabel('Name').fill('programming');
        await dialog.getByLabel('Price per hour').fill('99');
        await dialog.getByRole('button', { name: 'Create activity' }).click();
        await expect(dialog.getByText('Another activity already uses this name.')).toBeVisible();
        await dialog.getByRole('button', { name: 'Cancel' }).click();

        // reorder: SSM setup moves to the top
        await page.getByRole('button', { name: 'Move SSM setup up' }).click();
        await expect(list.getByRole('listitem').first()).toContainText('SSM setup');

        // Bug issues → Programming, kept after a reload
        await page.getByRole('combobox', { name: 'Default activity for Bug' }).click();
        await page.getByRole('option', { name: 'Programming' }).click();
        await expect(page.getByText('Default activity saved.')).toBeVisible();
        await page.reload();
        await expect(page.getByRole('combobox', { name: 'Default activity for Bug' })).toHaveText('Programming');

        // archive SSM setup: it moves to Archived and can't be picked as a default any more
        await page.getByRole('button', { name: 'Archive SSM setup' }).click();
        const archived = page.getByRole('list', { name: 'Archived activities' });
        await expect(archived.getByRole('listitem').filter({ hasText: 'SSM setup' })).toBeVisible();
        await expect(list.getByRole('listitem').filter({ hasText: 'SSM setup' })).toHaveCount(0);
        await page.getByRole('combobox', { name: 'Default activity for Task' }).click();
        await expect(page.getByRole('option', { name: 'Programming' })).toBeVisible();
        await expect(page.getByRole('option', { name: 'SSM setup' })).toHaveCount(0);
        await page.keyboard.press('Escape');

        // and restore it
        await archived.getByRole('button', { name: 'Restore SSM setup' }).click();
        await expect(list.getByRole('listitem').filter({ hasText: 'SSM setup' })).toBeVisible();
    });

    test('a member gets 403 and has no Billing link; the owner has one', async ({ page, memberPage }) => {
        const response = await memberPage.goto(activitiesUrl('e2e-workspace'));
        expect(response?.status()).toBe(403);
        const write = await send(memberPage, 'POST', activitiesUrl('e2e-workspace'), { name: 'Sneaky', pricing_type: 'hourly', price: '1', billable: true });
        expect(write.status()).toBe(403);

        await memberPage.goto(urls.workspace());
        await expect(memberPage.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(memberPage.getByRole('link', { name: 'Billing', exact: true })).toHaveCount(0);

        // control: the owner (workspace admin) sees it in the same workspace
        await page.goto(urls.workspace());
        await expect(page.getByRole('link', { name: 'Billing', exact: true })).toBeVisible();
    });
});
