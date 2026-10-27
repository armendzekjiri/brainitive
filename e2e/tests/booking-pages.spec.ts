import { expect, test, uid } from '../fixtures';

/**
 * Booking pages (v1.45.0, BRAI-158): the host creates a page from a template and gives a meeting type
 * its own length; a visitor (no account, phone width, another time zone) books a free time; the host
 * sees it under Bookings; the guest cancels with their link and the time is free again.
 * Busy calendars, double booking and reminders are covered by PHPUnit (BookingFlowTest).
 */
test.describe('booking pages', () => {
    test('a visitor books a time and cancels it with their link', async ({ page, browser }) => {
        const title = uid('Talk to us');

        // ── host: a page from the "Sales / intro call" template ────────────────────────────
        await page.goto('/booking-pages');
        await page.getByRole('button', { name: 'New booking page' }).click();
        const dialog = page.getByRole('dialog', { name: 'New booking page' });
        await dialog.getByRole('radio', { name: /Sales \/ intro call/ }).click();
        await dialog.getByLabel('Title (optional)').fill(title);
        await dialog.getByRole('button', { name: 'Create' }).click();
        await expect(page.getByRole('heading', { level: 1 })).toContainText(title);
        const types = page.getByRole('list', { name: 'Meeting types' });
        await expect(types).toContainText('Intro call');
        await expect(types).toContainText('Discovery call');

        // the user's own length: Intro call becomes 20 minutes, bookable every weekday 0:00–24:00 so a slot is always near
        await types.getByRole('listitem').filter({ hasText: 'Intro call' }).getByRole('button', { name: 'Edit' }).click();
        const editor = page.getByRole('dialog', { name: /Edit “Intro call”/ });
        await editor.getByLabel('Custom length in minutes').fill('20');
        for (const day of ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']) {
            const box = editor.getByRole('checkbox', { name: day });
            if (!(await box.isChecked())) await box.check();
            await editor.getByLabel(`${day} from`).first().fill('00:00');
            await editor.getByLabel(`${day} until`).first().fill('23:59');
        }
        await editor.getByLabel('Minimum notice (min)').fill('0');
        await editor.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(editor).toBeHidden();
        await expect(types).toContainText('20 min');

        // no Meet / Teams in the local app: plain "no location", and not added to a calendar
        const publicUrl = await page.getByRole('link', { name: 'Open' }).getAttribute('href');
        expect(publicUrl).toMatch(/\/book\/[a-z0-9-]+$/);

        // ── visitor: phone width, Tokyo time ─────────────────────────────────────────
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: { width: 375, height: 800 }, timezoneId: 'Asia/Tokyo' });
        const guest = await context.newPage();
        const errors: string[] = [];
        guest.on('pageerror', (e) => errors.push(e.message));
        await guest.goto(publicUrl!);
        await expect(guest.getByTestId('booking-title')).toHaveText(title);
        await expect(guest.getByTestId('operator-notice')).toBeVisible();
        await guest.getByRole('button', { name: /Intro call/ }).click();
        await expect(guest.getByLabel('Your time zone').or(guest.locator('#booking-timezone'))).toContainText('Asia/Tokyo');

        await guest.getByTestId('free-day').first().click();
        const firstTime = guest.getByTestId('slot-list').getByRole('button').first();
        const time = (await firstTime.textContent())!.trim();
        await firstTime.click();
        await guest.getByRole('button', { name: 'Next', exact: true }).click();

        const email = `${uid('guest')}@example.test`;
        await guest.getByLabel('Your name').fill('Gus Guest');
        await guest.getByLabel('Email').fill(email);
        await guest.getByRole('button', { name: `Book ${time}` }).click();
        const done = guest.getByTestId('booking-done');
        await expect(done).toContainText('You’re booked');
        await expect(done).toContainText(email);
        expect(await guest.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);

        // ── host: the booking is listed ───────────────────────────────────────────
        await page.reload();
        await page.getByRole('tab', { name: /Bookings/ }).click();
        const upcoming = page.getByRole('list', { name: 'Upcoming bookings' });
        await expect(upcoming).toContainText('Gus Guest');
        await expect(upcoming).toContainText(email);

        // ── guest: cancel with the link from the confirmation ─────────────────────────────
        await done.getByRole('link', { name: 'Reschedule or cancel' }).click();
        await expect(guest.getByTestId('manage-booking')).toContainText('Intro call');
        await guest.getByLabel('Cancel (reason, optional)').fill('Something came up');
        await guest.getByRole('button', { name: 'Cancel booking' }).click();
        await expect(guest.getByTestId('manage-booking')).toContainText('Cancelled');

        await page.reload();
        await page.getByRole('tab', { name: /Bookings/ }).click();
        await expect(page.getByText('Cancelled by the guest: Something came up')).toBeVisible();

        expect(errors).toEqual([]);
        await context.close();
    });

    test('an unknown or switched-off page is not found', async ({ page }) => {
        const response = await page.goto('/book/no-such-page-here');
        expect(response?.status()).toBe(404);
    });
});
