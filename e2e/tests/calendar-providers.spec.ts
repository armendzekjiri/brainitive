import type { Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { isoDay } from '../helpers/content';

/**
 * Calendar provider layer (BRAI-140): the "Add calendar" menu comes from the provider registry,
 * old "google" filter links keep working, and 'form' / 'url' providers connect through one
 * generic dialog. The made-up "E2E calendar link" provider exists only with APP_ENV=e2e (no
 * network, read-only), so those journeys skip against dev.brainitive.app.
 */

/** The 20th of this month: the e2e provider puts its events there (clear of other journeys). */
function day20(): Date {
    const d = new Date();
    d.setDate(20);
    return d;
}

async function openConnectDialog(page: Page): Promise<boolean> {
    await page.goto(`/calendar?view=month&date=${isoDay(day20())}`);
    await page.getByRole('button', { name: 'Add calendar' }).click();
    const item = page.getByRole('menuitem', { name: 'E2E calendar link' });
    if ((await item.count()) === 0) {
        await page.keyboard.press('Escape');
        return false;
    }
    await item.click();
    return true;
}

async function connectLink(page: Page, url: string) {
    const dialog = page.getByRole('dialog', { name: 'Connect E2E calendar link' });
    await dialog.getByLabel('Calendar link').fill(url);
    await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
    await expect(dialog).toBeHidden();
}

async function disconnect(page: Page, name: string) {
    await page.getByRole('button', { name: 'Add calendar' }).click();
    await page.getByRole('menuitem', { name: /Manage connected/ }).click();
    const manage = page.getByRole('dialog', { name: 'Connected calendars' });
    page.once('dialog', (d) => d.accept());
    await manage.getByRole('button', { name: `Disconnect ${name}` }).click();
    await expect(manage.getByText(name, { exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');
}

/** Always remove the journey's connection, also after a failure (CI retries on the same user). */
async function cleanUp(page: Page, name: string) {
    await page.goto(`/calendar?view=month&date=${isoDay(day20())}`);
    await page.getByRole('button', { name: 'Add calendar' }).click();
    const manageItem = page.getByRole('menuitem', { name: /Manage connected/ });
    if ((await manageItem.count()) === 0) return;
    await manageItem.click();
    const button = page.getByRole('dialog', { name: 'Connected calendars' }).getByRole('button', { name: `Disconnect ${name}` });
    if ((await button.count()) === 0) return;
    page.once('dialog', (d) => d.accept());
    await button.click();
}

test.describe('calendar providers', () => {
    test('the connect menu lists the providers and an old "google" filter link still works', async ({ page }) => {
        await page.goto(`/calendar?view=month&date=${isoDay(day20())}&sources[]=board&sources[]=google`);

        // 'google' now means every connected calendar ('external'): one source is off (Lists).
        await page.getByRole('button', { name: 'Filter (1)' }).click();
        await expect(page.getByRole('button', { name: 'Calendars', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Lists', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Filter', exact: true })).toBeVisible();
        await expect(page).not.toHaveURL(/sources/);
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: 'Add calendar' }).click();
        await expect(page.getByRole('menuitem', { name: 'Google Calendar' })).toBeVisible();
        await page.keyboard.press('Escape');
    });

    test('connect a read-only calendar link through the generic dialog', async ({ memberPage: page }) => {
        test.skip(!(await openConnectDialog(page)), 'the e2e provider exists only with APP_ENV=e2e');
        const dialog = page.getByRole('dialog', { name: 'Connect E2E calendar link' });
        await expect(dialog.getByText('This connection is read-only.')).toBeVisible();

        // The server validates the declared field (only http(s) / webcal links).
        await dialog.getByLabel('Calendar link').fill('ftp://example.test/calendar.ics');
        await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
        await expect(dialog.getByText(/must be a valid URL/i)).toBeVisible();

        const host = `${uid('cal')}.example.test`;
        try {
            await connectLink(page, `https://${host}/calendar.ics`);
            await expect(page.getByText('Calendar connected.')).toBeVisible();
            // The e2e provider puts the calendar's name (the host) into its titles.
            await expect(page.getByText(`E2E offsite ${host}`)).toBeVisible();

            // Read-only events open in a view-only dialog.
            await page.getByRole('button', { name: `E2E standup ${host}` }).click();
            const event = page.getByRole('dialog', { name: 'Event' });
            await expect(event.getByText(/This calendar is read-only/)).toBeVisible();
            await expect(event.getByLabel('Title')).toHaveValue(`E2E standup ${host}`);
            await expect(event.getByLabel('Title')).toBeDisabled();
            await expect(event.getByRole('button', { name: 'Save' })).toHaveCount(0);
            await page.keyboard.press('Escape');

            // Quick create offers no read-only calendar.
            await page.getByText('21', { exact: true }).first().click();
            const quick = page.getByRole('dialog', { name: 'Quick create' });
            await expect(quick.getByRole('button', { name: 'Calendar', exact: true })).toBeDisabled();
            await page.keyboard.press('Escape');

            await disconnect(page, host);
            await expect(page.getByText(`E2E offsite ${host}`)).toHaveCount(0);
        } finally {
            await cleanUp(page, host);
        }
    });

    test('a calendar that fails to load shows a warning and the page still works', async ({ memberPage: page }) => {
        test.skip(!(await openConnectDialog(page)), 'the e2e provider exists only with APP_ENV=e2e');
        const host = `${uid('down')}.example.test`;
        try {
            await connectLink(page, `https://${host}/fail.ics`);

            const warnings = page.getByTestId('calendar-sync-warnings');
            await expect(warnings).toContainText(host);
            await expect(warnings).toContainText('The calendar link did not answer.');
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

            await disconnect(page, host);
            await page.reload();
            await expect(page.getByText(host)).toHaveCount(0);
        } finally {
            await cleanUp(page, host);
        }
    });
});
