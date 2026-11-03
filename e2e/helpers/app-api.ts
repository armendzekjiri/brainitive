import type { APIResponse, Page } from '@playwright/test';
import { expect, uid, urls } from '../fixtures';
import { request } from './workload';

/** Helpers for the App API v1 journeys (`/api/v1`, desktop / mobile / TV apps): a seeded app token + JSON checks. */

/** An app token of the user signed in on `page` (e2e-only device seed route). */
export async function seedAppToken(page: Page, platform = 'macos'): Promise<string> {
    await page.goto(urls.home); // any app page, for the XSRF cookie
    const res = await request(page, 'POST', '/profile/app-devices/e2e-create', { name: `E2E app ${uid()}`, platform });
    expect(res.ok(), `seed app device: ${res.status()}`).toBeTruthy();
    return ((await res.json()) as { access_token: string }).access_token;
}

/** The JSON body of an API answer, after checking its status. */
export async function ok<T = any>(res: APIResponse, status = 200): Promise<T> {
    expect(res.status(), await res.text()).toBe(status);
    return (await res.json()) as T;
}

/** The App API id of a project of a workspace, by slug. */
export async function appProjectId(page: Page, auth: Record<string, string>, workspace: string, slug: string): Promise<number> {
    const projects = await ok(await page.request.get(`/api/v1/workspaces/${workspace}/projects?limit=100`, { headers: auth }));
    const project = projects.data.find((p: { slug: string }) => p.slug === slug);
    expect(project, `project ${slug} in the API`).toBeTruthy();
    return project.id as number;
}
