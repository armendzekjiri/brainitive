import { REMOTE } from '../env';
import { expect, test, uid } from '../fixtures';
import { ok, seedAppToken } from '../helpers/app-api';
import { isoDay } from '../helpers/content';
import { request } from '../helpers/workload';

/*
 * App API v1 bookings, host side (v1.49.0, BRAI-419): a booking page made on the web gets a booking through
 * its public page; the host's app lists the page and the booking and cancels it, and the web's Bookings tab
 * shows it cancelled.
 */
test.describe('app api: bookings', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('the host lists a booking made on the public page and cancels it', async ({ page }) => {
        const title = uid('API bookings');
        await page.goto('/booking-pages');
        await page.getByRole('button', { name: 'New booking page' }).click();
        const dialog = page.getByRole('dialog', { name: 'New booking page' });
        await dialog.getByRole('radio', { name: /Sales \/ intro call/ }).click();
        await dialog.getByLabel('Title (optional)').fill(title);
        await dialog.getByRole('button', { name: 'Create' }).click();
        await expect(page.getByRole('heading', { level: 1 })).toContainText(title);

        // Intro call: every day 0:00–24:00, no notice, so a free time is always near
        const types = page.getByRole('list', { name: 'Meeting types' });
        await types.getByRole('listitem').filter({ hasText: 'Intro call' }).getByRole('button', { name: 'Edit' }).click();
        const editor = page.getByRole('dialog', { name: /Edit “Intro call”/ });
        for (const day of ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']) {
            const box = editor.getByRole('checkbox', { name: day });
            if (!(await box.isChecked())) await box.check();
            await editor.getByLabel(`${day} from`).first().fill('00:00');
            await editor.getByLabel(`${day} until`).first().fill('23:59');
        }
        await editor.getByLabel('Minimum notice (min)').fill('0');
        await editor.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(editor).toBeHidden();

        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        const pages = await ok(await page.request.get('/api/v1/booking-pages', { headers: auth }));
        const mine = pages.data.find((p: { title: string }) => p.title === title);
        expect(mine).toMatchObject({ enabled: true, upcoming_count: 0 });
        const intro = mine.types.find((t: { name: string }) => t.name === 'Intro call');
        const typePath = new URL(intro.public_url).pathname;

        // a guest books the first free time through the public page
        const from = isoDay(new Date());
        const to = isoDay(new Date(Date.now() + 3 * 86400000));
        const slots = await ok(await page.request.get(`${typePath}/slots?from=${from}&to=${to}&timezone=UTC`));
        expect(slots.slots.length).toBeGreaterThan(0);
        const guest = `${uid('guest')}@example.test`;
        const booked = await request(page, 'POST', typePath, { start: slots.slots[0], name: 'Gus Guest', email: guest, timezone: 'UTC' });
        expect(booked.status(), await booked.text()).toBe(200);

        const list = await ok(await page.request.get(`/api/v1/bookings?page=${mine.uuid}`, { headers: auth }));
        const booking = list.data.find((b: { guest_email: string }) => b.guest_email === guest);
        expect(booking).toMatchObject({ status: 'confirmed', guest_name: 'Gus Guest', type: 'Intro call', page: { uuid: mine.uuid } });

        const cancelled = await ok(await page.request.post(`/api/v1/bookings/${booking.uuid}/cancel`, { headers: auth, data: { reason: 'Double booked' } }));
        expect(cancelled.data).toMatchObject({ status: 'cancelled', cancelled_by: 'host', cancel_reason: 'Double booked' });
        const after = await ok(await page.request.get(`/api/v1/bookings?status=cancelled&page=${mine.uuid}`, { headers: auth }));
        expect(after.data.map((b: { uuid: string }) => b.uuid)).toContain(booking.uuid);

        await page.goto(mine.url);
        await page.getByRole('tab', { name: /Bookings/ }).click();
        await expect(page.getByText('Gus Guest').first()).toBeVisible();
        await expect(page.getByText(/Double booked/).first()).toBeVisible();

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
