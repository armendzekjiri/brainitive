import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createCard, issueDialog, openCard } from '../helpers/boards';
import { addProjectContent, docReader, isoDay } from '../helpers/content';

/**
 * Online meetings from cards, docs and the calendar (BRAI-147). Google / Microsoft are never
 * called: the made-up "E2E calendar link" provider (APP_ENV=e2e only) turns a link containing
 * "meet" into a writable calendar that offers Google Meet and Teams with a fake join link
 * (https://meet.e2e.test/…). Against dev.brainitive.app the provider doesn't exist → skipped.
 */

/** Connect a writable e2e calendar named `host`; false when the e2e provider isn't there. */
async function connectMeetCalendar(page: Page, host: string): Promise<boolean> {
    await page.goto(`/calendar?view=month&date=${isoDay(new Date())}`);
    await page.getByRole('button', { name: 'Add calendar' }).click();
    const item = page.getByRole('menuitem', { name: 'E2E calendar link' });
    if ((await item.count()) === 0) {
        await page.keyboard.press('Escape');
        return false;
    }
    await item.click();
    const dialog = page.getByRole('dialog', { name: 'Connect E2E calendar link' });
    await dialog.getByLabel('Calendar link').fill(`https://${host}/meet.ics`);
    await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Calendar connected.')).toBeVisible();
    return true;
}

/** Always remove the journey's calendar, also after a failure (CI retries on the same user). */
async function disconnect(page: Page, host: string) {
    await page.goto(`/calendar?view=month&date=${isoDay(new Date())}`);
    await page.getByRole('button', { name: 'Add calendar' }).click();
    const manageItem = page.getByRole('menuitem', { name: /Manage connected/ });
    if ((await manageItem.count()) === 0) return;
    await manageItem.click();
    const button = page.getByRole('dialog', { name: 'Connected calendars' }).getByRole('button', { name: `Disconnect ${host}` });
    if ((await button.count()) === 0) return;
    page.once('dialog', (d) => d.accept());
    await button.click();
}

/** Fill the shared "Schedule meeting" dialog: our calendar, a video call, one guest. */
async function schedule(page: Page, host: string, call: 'Google Meet' | 'Microsoft Teams', guest: string) {
    const dialog = page.getByRole('dialog', { name: 'Schedule meeting' });
    await expect(dialog.getByLabel('Title')).not.toHaveValue('');
    await dialog.getByRole('combobox', { name: 'Calendar' }).click();
    await page.getByRole('option', { name: new RegExp(host.replace(/\./g, '\\.')) }).click();
    await dialog.getByRole('radio', { name: call }).click();
    const guests = dialog.getByPlaceholder(/Other guests/);
    await guests.fill(guest);
    await guests.press('Enter');
    await expect(dialog.getByText(guest)).toBeVisible();
    await dialog.getByRole('button', { name: 'Schedule', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Meeting scheduled')).toBeVisible();
}

test.describe('online meetings', () => {
    test('schedule a Google Meet from a card, join it, then cancel it', async ({ page }) => {
        const host = `${uid('meet')}.example.test`;
        test.skip(!(await connectMeetCalendar(page, host)), 'the e2e provider exists only with APP_ENV=e2e');
        try {
            const title = uid('Checkout sync');
            await page.goto(urls.board());
            await createCard(page, title);
            const issue = await openCard(page, title);

            await issue.getByRole('button', { name: 'Schedule meeting' }).click();
            await schedule(page, host, 'Google Meet', 'client@partner.example.test');

            const meetings = issueDialog(page).getByTestId('meetings-section');
            const row = meetings.getByTestId('meeting-row').filter({ hasText: title });
            await expect(row).toBeVisible();
            await expect(row).toContainText('Google Meet');
            await expect(row.getByRole('link', { name: 'Join' })).toHaveAttribute('href', /^https:\/\/meet\.e2e\.test\//);

            // Remove → cancel the event in the calendar.
            await row.getByRole('button', { name: /Meeting actions/ }).click();
            await page.getByRole('menuitem', { name: 'Remove…' }).click();
            const remove = page.getByRole('dialog', { name: 'Remove meeting' });
            await remove.getByRole('button', { name: 'Cancel event' }).click();
            await expect(remove).toBeHidden();
            await expect(meetings.getByTestId('meeting-row')).toHaveCount(0);
            await expect(meetings.getByText('No meetings yet.')).toBeVisible();

            // The issue's history tells both (reopen: the history loads when the issue opens).
            await page.keyboard.press('Escape');
            await expect(issueDialog(page)).toBeHidden();
            const again = await openCard(page, title);
            await again.getByRole('tab', { name: 'History' }).click();
            await expect(again.getByText(/scheduled a Google Meet/)).toBeVisible();
            await expect(again.getByText(/cancelled the meeting/)).toBeVisible();
        } finally {
            await disconnect(page, host);
        }
    });

    test('a doc shows its meeting under the title and creates the meeting notes', async ({ page }) => {
        const host = `${uid('meet')}.example.test`;
        test.skip(!(await connectMeetCalendar(page, host)), 'the e2e provider exists only with APP_ENV=e2e');
        try {
            const doc = uid('Launch plan');
            await addProjectContent(page, 'Page', doc);
            await expect(page).toHaveURL(/\/pages\//);

            await page.getByRole('button', { name: 'More actions' }).click();
            await page.getByRole('menuitem', { name: 'Schedule meeting' }).click();
            await expect(page.getByRole('dialog', { name: 'Schedule meeting' }).getByLabel('Title')).toHaveValue(doc);
            await schedule(page, host, 'Microsoft Teams', 'guest@partner.example.test');

            const strip = page.getByTestId('meeting-strip');
            await expect(strip).toContainText(doc);
            await expect(strip.getByRole('link', { name: 'Join' })).toHaveAttribute('href', /^https:\/\/meet\.e2e\.test\//);

            await strip.getByRole('button', { name: 'Create meeting notes' }).click();
            await expect(page.getByRole('heading', { level: 1, name: new RegExp(`^Notes — ${doc}`) })).toBeVisible();
            await expect(docReader(page).getByRole('link', { name: doc, exact: true })).toBeVisible(); // link back to the doc

            // Back on the doc the strip now opens the same notes.
            await page.goBack();
            await expect(page.getByTestId('meeting-strip').getByRole('button', { name: 'Notes', exact: true })).toBeVisible();
        } finally {
            await disconnect(page, host);
        }
    });

    test('calendar quick create adds a Google Meet and the event offers Join', async ({ page }) => {
        const host = `${uid('meet')}.example.test`;
        test.skip(!(await connectMeetCalendar(page, host)), 'the e2e provider exists only with APP_ENV=e2e');
        try {
            const title = uid('Coffee chat');
            const day = new Date();
            day.setDate(22);
            await page.goto(`/calendar?view=month&date=${isoDay(day)}`);
            await page.getByText('22', { exact: true }).first().click();

            const quick = page.getByRole('dialog', { name: 'Quick create' });
            await quick.getByRole('button', { name: 'Calendar', exact: true }).click();
            await quick.getByLabel('Title').fill(title);
            await quick.getByRole('combobox', { name: 'Calendar' }).click();
            await page.getByRole('option', { name: new RegExp(host.replace(/\./g, '\\.')) }).click();
            await quick.getByRole('switch', { name: 'Add Google Meet' }).click();
            await expect(quick.getByText('Pick a start time for the video call.')).toBeVisible();
            await expect(quick.getByRole('button', { name: 'Create' })).toBeDisabled();
            await quick.getByLabel('Time').fill('10:00');
            await quick.getByLabel('Guests').fill('friend@partner.example.test');
            await quick.getByLabel('Guests').press('Enter');
            await quick.getByRole('button', { name: 'Create' }).click();
            await expect(quick).toBeHidden();

            const chip = page.getByRole('button', { name: title });
            await expect(chip).toBeVisible();
            await expect(chip.getByTestId('event-video-icon')).toBeVisible();
            await chip.click();
            const event = page.getByRole('dialog', { name: 'Edit event' });
            await expect(event.getByTestId('event-conference')).toContainText('Google Meet');
            await expect(event.getByRole('link', { name: 'Join' })).toHaveAttribute('href', /^https:\/\/meet\.e2e\.test\//);
            await page.keyboard.press('Escape');
        } finally {
            await disconnect(page, host);
        }
    });

    test('without a meeting calendar the dialog explains how to connect one', async ({ memberPage: page }) => {
        // Max (the member) has no writable calendar of his own in the seed.
        await page.goto(urls.board());
        const issue = await openCard(page, 'Homepage hero');
        await issue.getByRole('button', { name: 'Schedule meeting' }).click();
        const dialog = page.getByRole('dialog', { name: 'Schedule meeting' });
        await expect(dialog.getByTestId('meeting-no-calendar')).toContainText('Connect a calendar first');
        await expect(dialog.getByRole('link', { name: 'Connect a calendar' })).toHaveAttribute('href', /\/calendar$/);
    });
});
