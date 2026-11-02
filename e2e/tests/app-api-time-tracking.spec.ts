import { expect, test, uid, urls } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { ok, seedAppToken } from '../helpers/app-api';
import { newIssue } from '../helpers/workload';

/*
 * App API v1 time tracking (v1.49.0, BRAI-400), as the desktop tray calls it: the focus timer started
 * through the API is the web's timer (the top-bar pill shows it), stopping it through the API logs the
 * time once (the web timesheet lists it), and a work log deleted through the API leaves the timesheet.
 * As the seeded member: the owner's timer is used by time-log.spec.ts, and a user has one timer.
 */

test.describe('app api: time tracking', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('the tray timer and work logs are the web timer and timesheet', async ({ memberPage: page }) => {
        test.slow(); // an issue first
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        await page.goto(urls.board());
        const title = uid('Tray timer issue');
        const itemId = await newIssue(page, SEED.project, title);

        // a leftover timer of an earlier, failed run would make the start a 409
        const before = await ok(await page.request.get('/api/v1/me/timer', { headers: auth }));
        if (before.data.running) await ok(await page.request.post('/api/v1/me/timer/discard', { headers: auth, data: { timer_id: before.data.timer.id } }));

        const started = await ok(await page.request.post('/api/v1/me/timer/start', { headers: auth, data: { id: `issue:${itemId}` } }), 201);
        expect(started.data).toMatchObject({ running: true, timer: { issue: { title, work_id: `issue:${itemId}` } } });
        expect((await page.request.post('/api/v1/me/timer/start', { headers: auth, data: { id: `issue:${itemId}` } })).status()).toBe(409);

        // the web's top-bar pill shows the same timer
        await page.goto(`/workspaces/${SEED.workspace}/timesheet`);
        await expect(page.getByRole('button', { name: /^Timer running on/ })).toBeVisible();

        const activityId = started.data.default_activity_id ?? started.data.activities[0]?.id ?? null;
        const stopped = await ok(await page.request.post('/api/v1/me/timer/stop', {
            headers: auth, data: { timer_id: started.data.timer.id, time: '25m', activity_id: activityId },
        }));
        expect(stopped.data).toMatchObject({ result: 'logged', work_log: { minutes: 25, source: 'timer', issue: { title } } });
        // a retried stop logs nothing
        const again = await ok(await page.request.post('/api/v1/me/timer/stop', { headers: auth, data: { timer_id: started.data.timer.id, time: '25m', activity_id: activityId } }));
        expect(again.data.result).toBe('already_stopped');

        await page.reload();
        await expect(page.getByRole('button', { name: /^Timer running on/ })).toBeHidden();
        const row = page.getByTestId('timesheet-entry').filter({ hasText: title });
        await expect(row).toContainText('25m');
        await expect(row).toContainText('timer');

        // the API's timesheet agrees; deleting the log empties the web row
        const sheet = await ok(await page.request.get(`/api/v1/me/timesheet?workspace=${SEED.workspace}`, { headers: auth }));
        expect(sheet.data.entries.map((e: { id: number }) => e.id)).toContain(stopped.data.work_log.id);
        expect((await page.request.delete(`/api/v1/work-logs/${stopped.data.work_log.id}`, { headers: auth })).status()).toBe(204);
        await page.reload();
        await expect(row).toHaveCount(0);

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
