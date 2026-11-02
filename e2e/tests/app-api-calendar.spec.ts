import type { Page } from '@playwright/test';
import { REMOTE } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { isoDay } from '../helpers/content';
import { startCalendarServer } from '../helpers/calendar-server';
import { request } from '../helpers/workload';

/*
 * App API v1 calendar, read part (v1.49.0, BRAI-418): a calendar connected on the web (an ICS link
 * served by the local calendar server) shows up in GET /calendar/connections, and its events in
 * GET /calendar/events — the same events the web calendar shows (shared CalendarEvents).
 */

function day(n: number): string {
    const d = new Date();
    d.setDate(n);
    return isoDay(d);
}

async function seedToken(page: Page): Promise<string> {
    await page.goto(urls.home);
    const res = await request(page, 'POST', '/profile/app-devices/e2e-create', { name: `E2E TV ${uid()}`, platform: 'android_tv' });
    expect(res.ok(), `seed app device: ${res.status()}`).toBeTruthy();
    return ((await res.json()) as { access_token: string }).access_token;
}

async function disconnect(page: Page, name: string) {
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

test.describe('app api: calendar', () => {
    test.skip(REMOTE, 'the calendar server and the device seed only exist locally');

    test('a calendar connected on the web is read through the API', async ({ memberPage: page }) => {
        // as the member: calendar-links.spec.ts expects the owner to have only its own ICS link
        const tag = uid('api');
        const name = `Team ${tag}`;
        const server = await startCalendarServer('unused', tag);
        try {
            await page.goto(`/calendar?view=month&date=${day(15)}`);
            await page.getByRole('button', { name: 'Add calendar' }).click();
            await page.getByRole('menuitem', { name: 'Calendar link (ICS)' }).click();
            const dialog = page.getByRole('dialog', { name: 'Connect Calendar link (ICS)' });
            await dialog.getByLabel('Calendar link').fill(server.icsUrl(name, tag));
            await dialog.getByRole('button', { name: 'Connect', exact: true }).click();
            await expect(page.getByText(`Link call ${tag}`).first()).toBeVisible();

            const auth = { Authorization: `Bearer ${await seedToken(page)}` };

            const connections = await page.request.get('/api/v1/calendar/connections', { headers: auth });
            expect(connections.status(), await connections.text()).toBe(200);
            const connection = (await connections.json()).data.find((c: { name: string }) => c.name === name);
            expect(connection).toMatchObject({ kind: 'calendar', read_only: true, is_active: true, sync_error: null });

            const res = await page.request.get(`/api/v1/calendar/events?from=${day(22)}&to=${day(23)}&sources[]=external`, { headers: auth });
            expect(res.status(), await res.text()).toBe(200);
            const events = ((await res.json()).data as { title: string; all_day: boolean; start: string; read_only: boolean; calendar: { connection_id: number } }[])
                .filter((e) => e.calendar?.connection_id === connection.id);

            const offsite = events.find((e) => e.title === `Link offsite ${tag}`);
            expect(offsite).toMatchObject({ all_day: true, start: day(22), read_only: true });
            const call = events.find((e) => e.title === `Link call ${tag}`);
            expect(call).toMatchObject({ all_day: false, start: `${day(23)}T10:00:00Z` });
            // the weekly event is on the 8th and 15th: not in these days
            expect(events.some((e) => e.title === `Link weekly ${tag}`)).toBe(false);

            await page.request.post('/api/v1/auth/logout', { headers: auth });
        } finally {
            await disconnect(page, name);
            await server.close();
        }
    });
});
