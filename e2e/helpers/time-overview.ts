import { expect, type Locator, type Page } from '@playwright/test';
import { SEED } from '../env';
import { createBlankProject } from './workspaces-projects';
import { newIssue, request } from './workload';

/** Helpers for the team time overview + clients journeys (tests/time-overview.spec.ts, BRAI-100). */

export const billingUrl = (tab: 'time' | 'clients' | 'reports') => `/workspaces/${SEED.workspace}/billing/${tab}`;
const projectPath = (project: string) => `/workspaces/${SEED.workspace}/projects/${project}`;

/** A new project of the seeded workspace with one issue on its main board. */
export async function projectWithIssue(page: Page, title: string): Promise<{ name: string; slug: string; itemId: number }> {
    const project = await createBlankProject(page, SEED.workspace);
    const itemId = await newIssue(page, project.slug, title);
    return { name: project.name, slug: project.slug, itemId };
}

/** The seeded member's user id (they are on the seeded project). */
export async function memberId(page: Page): Promise<number> {
    const response = await request(page, 'GET', `${projectPath(SEED.project)}/members/search?query=Max`);
    expect(response.ok(), await response.text()).toBeTruthy();
    const members = (await response.json()) as { id: number; email: string }[];
    const member = members.find((m) => m.email === 'e2e-member@example.test');
    expect(member, 'the seeded member').toBeTruthy();
    return member!.id;
}

/** Add a workspace member to a project (as editor), like the Members tab of project settings. */
export async function addToProject(page: Page, project: string, userId: number): Promise<void> {
    const response = await request(page, 'POST', `${projectPath(project)}/members`, { user_id: userId, role: 'editor' });
    expect(response.status(), `add member: ${await response.text()}`).toBeLessThan(400);
}

/**
 * Log work on an issue through the real "Log work" endpoint, as the user signed in on `page` (today),
 * with the issue's default activity — required once the workspace has activities (E2eSeeder fixtures).
 */
export async function logWork(page: Page, project: string, itemId: number, time: string): Promise<void> {
    const options = (await (await request(page, 'GET', `/workspaces/${SEED.workspace}/timesheet/activities?item_id=${itemId}`)).json()) as {
        activities: { id: number }[];
        default_activity_id: number | null;
    };
    const activityId = options.default_activity_id ?? options.activities[0]?.id ?? null;
    const response = await request(page, 'POST', `${projectPath(project)}/boards/main-board/items/${itemId}/work-logs`, { time, activity_id: activityId });
    expect(response.status(), `log work: ${await response.text()}`).toBeLessThan(400);
}

/** Pick an option of a labelled shadcn select. */
export async function choose(page: Page, label: string, option: string | RegExp): Promise<void> {
    await page.getByRole('combobox', { name: label }).click();
    await page.getByRole('option', { name: option }).click();
}

/** Tick projects in the overview's project filter; `alreadyTicked` = how many were ticked before. */
export async function filterProjects(page: Page, names: string[], alreadyTicked = 0): Promise<void> {
    const trigger = page.getByRole('button', { name: 'Projects', exact: true });
    await trigger.click();
    const list = page.getByRole('list', { name: 'Filter by project' });
    for (const [i, name] of names.entries()) {
        // every tick reloads the overview; wait for that reload, not just the (optimistic) tick
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/billing/time?') && r.request().method() === 'GET'),
            list.getByRole('checkbox', { name }).check(),
        ]);
        const count = alreadyTicked + i + 1;
        await expect(trigger).toHaveText(count === 1 ? name : `${count} projects`);
        await expect(list.getByRole('checkbox', { name })).toBeChecked();
    }
    await page.keyboard.press('Escape');
}

/** A row of the overview table by its first cell. */
export const overviewRow = (page: Page, name: string | RegExp): Locator =>
    page.getByRole('table', { name: 'Logged time' }).getByRole('row', { name });
