import type { Page } from '@playwright/test';
import { REMOTE, SEED } from '../env';
import { expect, test, uid } from '../fixtures';
import { appProjectId, ok, seedAppToken } from '../helpers/app-api';
import { isoDay } from '../helpers/content';
import { projectPath, request } from '../helpers/workload';

/*
 * App API v1 calendar, write part (v1.49.0, BRAI-418): on a writable calendar connected on the web (the
 * made-up "E2E calendar link" provider, APP_ENV=e2e only: a link with "meet" offers Google Meet / Teams),
 * an app creates and deletes an event with a call, schedules a meeting on an issue that the web's meeting
 * list shows, and turns the private ICS feed link on and off.
 */

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
    return true;
}

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

test.describe('app api: calendar writes, meetings, feed link', () => {
    test.skip(REMOTE, 'the e2e calendar provider and the device seed only exist locally');

    test('an app creates an event with a call, schedules a meeting on an issue and turns the feed link on', async ({ memberPage: page }) => {
        // as the member: calendar-feed.spec.ts and calendar-links.spec.ts use the owner's feed and calendars
        const host = `${uid('apimeet')}.example.test`;
        test.skip(!(await connectMeetCalendar(page, host)), 'the e2e provider exists only with APP_ENV=e2e');
        const auth = { Authorization: `Bearer ${await seedAppToken(page)}` };
        try {
            const calendars = await ok(await page.request.get('/api/v1/calendar/meeting-calendars', { headers: auth }));
            const calendar = calendars.data.find((c: { name: string; account_email: string }) => `${c.name} ${c.account_email}`.includes(host));
            expect(calendar, 'the connected calendar offers calls').toBeTruthy();
            expect(calendar.conference_providers).toContain('google_meet');

            // an event with a Google Meet call, then gone again
            const today = isoDay(new Date());
            const title = uid('API event');
            const event = (await ok(await page.request.post('/api/v1/calendar/events', {
                headers: auth,
                data: { connection_id: calendar.id, title, date: today, time: '15:00', end_time: '15:30', conference: 'google_meet' },
            }), 201)).data;
            expect(event).toMatchObject({ type: 'event', title, all_day: false, conference_provider: 'google_meet' });
            expect(event.conference_url).toMatch(/^https:\/\/meet\.e2e\.test\//);
            const listed = await ok(await page.request.get(`/api/v1/calendar/events?from=${today}&to=${today}&sources[]=external`, { headers: auth }));
            expect(listed.data.some((e: { id: string }) => e.id === event.id)).toBe(true);
            const res = await page.request.delete(`/api/v1/calendar/events/${encodeURIComponent(event.id)}`, { headers: auth });
            expect(res.status(), await res.text()).toBe(204);

            // a meeting on an issue: the web's meeting list of the issue shows it
            const projectId = await appProjectId(page, auth, SEED.workspace, SEED.project);
            const boards = await ok(await page.request.get(`/api/v1/projects/${projectId}/boards`, { headers: auth }));
            const board = boards.data.find((b: { slug: string }) => b.slug === SEED.board);
            const issue = (await ok(await page.request.post(`/api/v1/boards/${board.id}/items`, { headers: auth, data: { title: uid('Meeting issue') } }), 201)).data;
            const meetingTitle = uid('API sync');
            const meeting = (await ok(await page.request.post('/api/v1/meetings', {
                headers: auth,
                data: { subject_type: 'item', subject_id: String(issue.id), connection_id: calendar.id, title: meetingTitle, date: today, start_time: '16:00', duration: 30, conference: 'teams', emails: ['guest@partner.test'] },
            }), 201)).data;
            expect(meeting).toMatchObject({ title: meetingTitle, conference_provider: 'teams', subject: { type: 'item', id: String(issue.id) }, can: { remove: true, cancel: true } });
            const web = await request(page, 'GET', `${projectPath()}/meetings?subject_type=item&subject_id=${issue.id}`);
            expect(web.status()).toBe(200);
            expect(((await web.json()).meetings as { id: number }[]).map((m) => m.id)).toContain(meeting.id);
            const cancelled = await page.request.delete(`/api/v1/meetings/${meeting.id}?cancel_event=1`, { headers: auth });
            expect(cancelled.status(), await cancelled.text()).toBe(204);
            await page.request.delete(`/api/v1/items/${issue.id}`, { headers: auth });

            // the private ICS feed link
            const feed = (await ok(await page.request.put('/api/v1/me/calendar-feed', { headers: auth, data: { options: { tasks: false } } }), 201)).data;
            expect(feed).toMatchObject({ enabled: true, options: { tasks: false } });
            const ics = await page.request.get(feed.url);
            expect(ics.status()).toBe(200);
            expect(await ics.text()).toContain('BEGIN:VCALENDAR');
            expect((await page.request.delete('/api/v1/me/calendar-feed', { headers: auth })).status()).toBe(204);
            expect((await page.request.get(feed.url)).status()).toBe(404);
        } finally {
            await page.request.post('/api/v1/auth/logout', { headers: auth });
            await disconnect(page, host);
        }
    });
});
