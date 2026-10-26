import type { Page } from '@playwright/test';
import { REMOTE } from '../env';
import { expect, test, uid } from '../fixtures';
import { isoDay } from '../helpers/content';
import { DAV_PASSWORD, DAV_USER, startCalendarServer } from '../helpers/calendar-server';

/**
 * CalDAV / iCloud and ICS links (BRAI-141). A Node server in the worker plays both the ICS feed
 * and a small CalDAV server on 127.0.0.1; the e2e app may reach it (CALENDAR_LINKS_ALLOW_PRIVATE,
 * honoured only with APP_ENV=e2e — everywhere else private addresses are refused). Skipped against
 * dev.brainitive.app: the deployed app can't reach this machine.
 */

/** Day `day` of this month (the server puts its events on the 8th, 9th, 15th, 22nd and 23rd). */
function day(n: number): string {
    const d = new Date();
    d.setDate(n);
    return isoDay(d);
}

async function openConnectDialog(page: Page, label: string) {
    await page.goto(`/calendar?view=month&date=${day(15)}`);
    await page.getByRole('button', { name: 'Add calendar' }).click();
    await page.getByRole('menuitem', { name: label }).click();
    return page.getByRole('dialog', { name: `Connect ${label}` });
}

async function openManage(page: Page) {
    await page.getByRole('button', { name: 'Add calendar' }).click();
    await page.getByRole('menuitem', { name: /Manage connected/ }).click();
    return page.getByRole('dialog', { name: 'Connected calendars' });
}

/** Remove the journey's connection, also after a failure (CI retries on the same user). */
async function cleanUp(page: Page, name: string) {
    await page.goto(`/calendar?view=month&date=${day(15)}`);
    await page.getByRole('button', { name: 'Add calendar' }).click();
    const manageItem = page.getByRole('menuitem', { name: /Manage connected/ });
    if ((await manageItem.count()) === 0) return;
    await manageItem.click();
    const button = page.getByRole('dialog', { name: 'Connected calendars' }).getByRole('button', { name: `Disconnect ${name}` });
    if ((await button.count()) === 0) return;
    page.once('dialog', (d) => d.accept());
    await button.click();
}

test.describe('calendar links and CalDAV', () => {
    test.skip(REMOTE, 'the calendar server runs on this machine, the deployed app cannot reach it');

    test('an ICS link shows its events read-only and never shows the secret link', async ({ page }) => {
        const tag = uid('ics');
        const name = `School ${tag}`;
        const server = await startCalendarServer('unused', tag);
        const link = server.icsUrl(name, tag);
        try {
            const dialog = await openConnectDialog(page, 'Calendar link (ICS)');
            await expect(dialog.getByText('This connection is read-only.')).toBeVisible();
            await expect(dialog.getByText(/Secret address in iCal format/)).toBeVisible();
            await dialog.getByLabel('Calendar link').fill(link);
            await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
            await expect(dialog).toBeHidden();
            await expect(page.getByText('Calendar connected.')).toBeVisible();

            // all-day, a weekly series (8th + 15th) and a timed event
            await expect(page.getByText(`Link offsite ${tag}`).first()).toBeVisible();
            await expect(page.getByText(`Link weekly ${tag}`)).toHaveCount(2);
            await expect(page.getByText(`Link call ${tag}`).first()).toBeVisible();

            // read-only: the event opens view-only
            await page.getByRole('button', { name: `Link call ${tag}` }).first().click();
            const event = page.getByRole('dialog', { name: 'Event' });
            await expect(event.getByText(/This calendar is read-only/)).toBeVisible();
            await expect(event.getByLabel('Title')).toHaveValue(`Link call ${tag}`);
            await expect(event.getByRole('button', { name: 'Save' })).toHaveCount(0);
            await page.keyboard.press('Escape');

            // the link's secret path never reaches the page; the calendar shows by its own name + host
            expect(await page.content()).not.toContain(`private-${tag}`);
            const manage = await openManage(page);
            await expect(manage.getByText(name, { exact: true })).toBeVisible();
            await expect(manage.getByText('Calendar link (ICS) · 127.0.0.1')).toBeVisible();
            page.once('dialog', (d) => d.accept());
            await manage.getByRole('button', { name: `Disconnect ${name}` }).click();
            await expect(manage.getByText(name, { exact: true })).toHaveCount(0);
            await page.keyboard.press('Escape');
            await expect(page.getByText(`Link offsite ${tag}`)).toHaveCount(0);
        } finally {
            await cleanUp(page, name);
            await server.close();
        }
    });

    test('connect a CalDAV calendar with an app password, pick it and edit an event', async ({ page }) => {
        const tag = uid('dav');
        const name = `Work ${tag}`;
        const server = await startCalendarServer(name, tag);
        try {
            const dialog = await openConnectDialog(page, 'Apple iCloud / CalDAV');
            await expect(dialog.getByText(/appleid\.apple\.com → Sign-In and Security/)).toBeVisible();
            await expect(dialog.getByLabel('Server URL')).toHaveAttribute('placeholder', 'https://caldav.icloud.com');
            await expect(dialog.getByText('Events show on this calendar, and you can add and move them here.')).toBeVisible();

            // the server validates the fields
            await dialog.getByLabel('Server URL').fill('ftp://example.test/dav');
            await dialog.getByLabel('Username or Apple ID').fill(DAV_USER);
            await dialog.getByLabel('App password').fill('wrong-password');
            await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
            await expect(dialog.getByText(/Server URL field must be a valid URL/i)).toBeVisible();

            // a wrong app password is a field error
            await dialog.getByLabel('Server URL').fill(server.davUrl);
            await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
            await expect(dialog.getByText('Check your username and app password.')).toBeVisible();

            // the right one: the picker lists the event calendars (not the reminders list)
            await dialog.getByLabel('App password').fill(DAV_PASSWORD);
            await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
            const picker = page.getByRole('dialog', { name: 'Choose what to add' });
            await expect(picker.getByRole('button', { name: new RegExp(name) })).toBeVisible();
            await expect(picker.getByText('Reminders')).toHaveCount(0);
            await picker.getByRole('button', { name: 'Add 1' }).click();
            await expect(picker).toBeHidden();

            // its event shows and can be changed: the change is PUT back to the server
            await page.goto(`/calendar?view=month&date=${day(9)}`);
            await page.getByRole('button', { name: `CalDAV review ${tag}` }).first().click();
            const event = page.getByRole('dialog', { name: 'Event' });
            await expect(event.getByLabel('Title')).toHaveValue(`CalDAV review ${tag}`);
            await event.getByLabel('Title').fill(`CalDAV review ${tag} (edited)`);
            await event.getByRole('button', { name: 'Save' }).click();
            await expect(event).toBeHidden();
            await expect.poll(() => server.objects.get('/dav/calendars/e2e/work/review.ics') ?? '').toContain(`SUMMARY:CalDAV review ${tag} (edited)`);
            await expect(page.getByText(`CalDAV review ${tag} (edited)`).first()).toBeVisible();
        } finally {
            await cleanUp(page, name);
            await server.close();
        }
    });
});
