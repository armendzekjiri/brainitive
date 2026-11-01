import type { APIResponse, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { REMOTE } from '../env';
import { projectWithIssue } from '../helpers/time-overview';
import { projectPath, request } from '../helpers/workload';

/*
 * App API v1 My Work + My Day (v1.49.0, BRAI-396), as the desktop / TV app calls it: an issue assigned
 * to the owner shows in GET /me/work, goes on My Day through the API and is completed there — and the
 * web's My Day shows the same plan and the same tick, because both share MyDayPlan and WorkCompletion.
 * GET /me/today (the TV wallboard) carries the plan too and answers 304 when nothing changed.
 */

async function seedToken(page: Page): Promise<string> {
    await page.goto(urls.home); // any app page, for the XSRF cookie
    const res = await request(page, 'POST', '/profile/app-devices/e2e-create', { name: `E2E TV ${uid()}`, platform: 'android_tv' });
    expect(res.ok(), `seed app device: ${res.status()}`).toBeTruthy();
    return ((await res.json()) as { access_token: string }).access_token;
}

async function ok<T = any>(res: APIResponse, status = 200): Promise<T> {
    expect(res.status(), await res.text()).toBe(status);
    return (await res.json()) as T;
}

test.describe('app api: my work and my day', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('work planned and ticked off through the API shows on the web My Day', async ({ page }) => {
        test.slow(); // a project with an issue first
        const title = uid('TV wallboard task');
        const project = await projectWithIssue(page, title);
        const token = await seedToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        // assign it to the owner (the signed-in user), due today
        const me = await ok(await page.request.get('/api/v1/me', { headers: auth }));
        const assigned = await request(page, 'PATCH', `${projectPath(project.slug)}/boards/main-board/items/${project.itemId}`, { assigned_to: me.data.id });
        expect(assigned.status(), await assigned.text()).toBeLessThan(400);

        const ref = `issue:${project.itemId}`;
        const work = await ok(await page.request.get('/api/v1/me/work?limit=100', { headers: auth }));
        const entry = work.data.find((row: { id: string }) => row.id === ref);
        expect(entry, 'the assigned issue is in My Work').toBeTruthy();
        expect(entry).toMatchObject({ type: 'issue', title, done: false, project: { name: project.name } });

        // plan it for today through the API → the web's My Day lists it
        const day = await ok(await page.request.post('/api/v1/me/my-day/items', { headers: auth, data: { id: ref } }));
        expect(day.data.items.map((row: { id: string }) => row.id)).toContain(ref);

        // the TV wallboard's one call shows it too, and an unchanged refresh is a 304
        const today = await page.request.get('/api/v1/me/today', { headers: auth });
        expect((await ok(today)).data.my_day.map((row: { id: string }) => row.id)).toContain(ref);
        const etag = today.headers()['etag'];
        expect(etag).toBeTruthy();
        expect((await page.request.get('/api/v1/me/today', { headers: { ...auth, 'If-None-Match': etag } })).status()).toBe(304);

        await page.goto('/my-work');
        const myDay = page.getByRole('region', { name: 'My Day' });
        await expect(myDay.getByRole('checkbox', { name: `Mark “${title}” as done` })).toHaveAttribute('aria-checked', 'false');

        // tick it off through the API → done on the web too, and gone from the open list
        const ticked = await ok(await page.request.patch(`/api/v1/me/my-day/items/${ref}`, { headers: auth, data: { done: true } }));
        expect(ticked.data.items.find((row: { id: string }) => row.id === ref).done).toBe(true);
        await page.reload();
        await expect(myDay.getByRole('checkbox', { name: `Reopen “${title}”` })).toHaveAttribute('aria-checked', 'true');
        const open = await ok(await page.request.get('/api/v1/me/work?limit=100', { headers: auth }));
        expect(open.data.map((row: { id: string }) => row.id)).not.toContain(ref);

        // off the plan again
        const removed = await ok(await page.request.delete(`/api/v1/me/my-day/items/${ref}`, { headers: auth }));
        expect(removed.data.items.map((row: { id: string }) => row.id)).not.toContain(ref);
        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
