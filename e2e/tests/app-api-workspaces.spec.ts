import type { APIResponse, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { REMOTE, SEED } from '../env';
import { pinnedGroup } from '../helpers/custom-sidebar';
import { currentUserId, mention } from '../helpers/notifications';
import { projectKey } from '../helpers/workspaces-projects';

/**
 * App API v1 phase 03 (BRAI-388 … BRAI-394), as the desktop app calls it: a signed-in desktop
 * device's token (seeded through the e2e-only POST /profile/app-devices/e2e-create, the stand-in for
 * the sign-in exchange) reads workspaces and projects, creates a project, pins a board, searches,
 * reads notifications — and the web shows the same state, because both share the services.
 */

async function seedToken(page: Page): Promise<string> {
    await page.goto(urls.home); // any app page, for the XSRF cookie
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!cookie) throw new Error('no XSRF-TOKEN cookie');
    const res = await page.request.post('/profile/app-devices/e2e-create', {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value), Accept: 'application/json' },
        data: { name: `E2E Mac ${uid()}`, platform: 'macos' },
    });
    expect(res.ok(), `seed app device: ${res.status()}`).toBeTruthy();
    return ((await res.json()) as { access_token: string }).access_token;
}

/** JSON of a 2xx App API response, with the body in the failure message otherwise. */
async function ok<T = any>(res: APIResponse, status = 200): Promise<T> {
    expect(res.status(), await res.text()).toBe(status);
    return (await res.json()) as T;
}

test.describe('app api: workspaces, projects, navigation, search, notifications', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('a project created and pinned through the API shows on the web', async ({ page }) => {
        const token = await seedToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        // the workspace switcher and one workspace
        const workspaces = await ok(await page.request.get('/api/v1/workspaces', { headers: auth }));
        expect(workspaces.data.map((w: { slug: string }) => w.slug)).toContain(SEED.workspace);
        const workspace = await ok(await page.request.get(`/api/v1/workspaces/${SEED.workspace}`, { headers: auth }));
        expect(workspace.data.can.create_projects).toBe(true);

        // "New project" from the app
        const name = uid('App project');
        const created = await ok(await page.request.post(`/api/v1/workspaces/${SEED.workspace}/projects`, {
            headers: auth,
            data: { name, key: projectKey() },
        }), 201);
        const project = created.data as { id: number; slug: string; url: string };
        expect(project.url).toContain(`/workspaces/${SEED.workspace}/projects/${project.slug}`);

        const home = await ok(await page.request.get(`/api/v1/projects/${project.id}`, { headers: auth }));
        const board = (home.data.boards as { id: number; name: string }[]).find((b) => b.name === 'Main Board');
        expect(board, 'a blank project gets a Main Board').toBeTruthy();

        // it is on the workspace home's project list
        await page.goto(urls.workspace());
        await expect(page.getByRole('main').getByText(name, { exact: true }).first()).toBeVisible();

        // pin its board from the app: the web sidebar shows it under Pinned
        const pins = await ok(await page.request.put('/api/v1/me/pins', {
            headers: auth,
            data: { project_id: project.id, pins: [{ type: 'board', id: board!.id }] },
        }));
        expect(pins.data.pins).toEqual([expect.objectContaining({ type: 'board', id: board!.id, name: 'Main Board' })]);
        await page.goto(urls.project(project.slug));
        await expect(pinnedGroup(page).getByRole('link', { name: 'Main Board', exact: true })).toBeVisible();

        // the app's navigation has the pin too, and resolves its own recent entries
        const nav = await ok(await page.request.get(`/api/v1/me/navigation?workspace=${SEED.workspace}&recent[]=project:${project.id}`, { headers: auth }));
        const navProject = (nav.data.projects as { id: number; pins: { id: number }[] }[]).find((p) => p.id === project.id);
        expect(navProject?.pins.map((p) => p.id)).toEqual([board!.id]);
        expect(nav.data.recent[0]).toEqual(expect.objectContaining({ type: 'project', id: project.id, title: name }));

        // the quick switcher finds it by name
        const found = await ok(await page.request.get(`/api/v1/search?q=${encodeURIComponent(name)}`, { headers: auth }));
        expect(found.data).toEqual(expect.any(Array));
        expect((found.places as { type: string; id: number }[]).some((p) => p.type === 'project' && p.id === project.id)).toBe(true);
    });

    test('reading notifications in the app clears them on the web', async ({ page, memberPage }) => {
        const ownerId = await currentUserId(page);
        const title = uid('App mention');
        await mention(memberPage, ownerId, title);

        const token = await seedToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        let id = '';
        await expect.poll(async () => {
            const list = await ok(await page.request.get('/api/v1/notifications?filter=mentions', { headers: auth }));
            id = (list.data as { id: string; data: { context_title?: string } }[]).find((n) => n.data.context_title?.includes(title))?.id ?? '';
            return id;
        }, { message: 'the mention reaches the app inbox', timeout: 30_000 }).not.toBe('');

        await ok(await page.request.post(`/api/v1/notifications/${id}/read`, { headers: auth }));
        await ok(await page.request.post(`/api/v1/notifications/${id}/archive`, { headers: auth }));

        // the web inbox (bell feed) no longer lists it; the archive does
        const inbox = await ok(await page.request.get('/api/notifications', { headers: { Accept: 'application/json' } }));
        expect((inbox.data as { id: string }[]).map((n) => n.id)).not.toContain(id);
        const archive = await ok(await page.request.get('/api/notifications?archived=1', { headers: { Accept: 'application/json' } }));
        expect((archive.data as { id: string; read_at: string | null }[]).find((n) => n.id === id)?.read_at).toBeTruthy();

        // the badge count of the app and the web agree
        const appCount = await ok(await page.request.get('/api/v1/notifications/unread-count', { headers: auth }));
        const webCount = await ok(await page.request.get('/api/notifications/unread-count', { headers: { Accept: 'application/json' } }));
        expect(appCount.data.unread_count).toBe(webCount.count);
    });

    test('connected apps list this device and revoking it ends its token', async ({ page }) => {
        const token = await seedToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        const apps = await ok(await page.request.get('/api/v1/me/connected-apps', { headers: auth }));
        const current = (apps.data as { id: string; kind: string; current: boolean }[]).find((a) => a.current);
        expect(current?.kind).toBe('desktop');

        const revoked = await page.request.delete(`/api/v1/me/connected-apps/${current!.id}`, { headers: auth });
        expect(revoked.status()).toBe(204);
        expect((await page.request.get('/api/v1/me', { headers: auth })).status()).toBe(401);
    });
});
