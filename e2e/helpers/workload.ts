import { expect, type APIResponse, type Locator, type Page } from '@playwright/test';
import { SEED } from '../env';
import { closeIssue, createCard, openCard, openIssueId } from './boards';

/** Helpers for the workload planning journeys (tests/workload.spec.ts, BRAI-75). */

export const projectPath = (project: string = SEED.project) => `/workspaces/${SEED.workspace}/projects/${project}`;
export const workloadUrl = (project: string = SEED.project) => `${projectPath(project)}/workload`;

/** A request as the signed-in user of `page` (session cookie + CSRF header). */
export async function request(page: Page, method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE', url: string, data?: Record<string, unknown>): Promise<APIResponse> {
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    return page.request.fetch(url, {
        method,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' },
        data,
        maxRedirects: 0,
    });
}

/** A new issue on the project's main board (the page must show that board); returns its id. */
export async function newIssue(page: Page, project: string, title: string, fields: { estimate?: string; logged?: string } = {}): Promise<number> {
    await createCard(page, title);
    const dialog = await openCard(page, title);
    const id = await openIssueId(dialog);
    await closeIssue(page);

    const itemUrl = `${projectPath(project)}/boards/main-board/items/${id}`;
    if (fields.estimate) {
        const response = await request(page, 'PATCH', itemUrl, { original_estimate: fields.estimate });
        expect(response.status(), `estimate: ${await response.text()}`).toBeLessThan(400);
    }
    if (fields.logged) {
        // v1.38.0: logging needs an activity once the workspace has any (E2eSeeder billing fixtures)
        const options = await (await request(page, 'GET', `/workspaces/${SEED.workspace}/timesheet/activities?item_id=${id}`)).json() as { activities: { id: number }[]; default_activity_id: number | null };
        const activityId = options.default_activity_id ?? options.activities[0]?.id ?? null;
        const response = await request(page, 'POST', `${itemUrl}/work-logs`, { time: fields.logged, activity_id: activityId });
        expect(response.status(), `log work: ${await response.text()}`).toBeLessThan(400);
    }
    return id;
}

/** The grid cell button of a person in a week (`index` 0 = the first week shown). */
export function weekCell(page: Page, person: string, index = 0): Locator {
    return page.getByRole('button', { name: new RegExp(`^${person}, W\\d+:`) }).nth(index);
}

/** Open a person's week; returns the sheet. */
export async function openWeek(page: Page, person: string, index = 0): Promise<Locator> {
    await weekCell(page, person, index).click();
    const sheet = page.getByRole('dialog', { name: new RegExp(`^${person} · W\\d+ \\d{4}$`) });
    await expect(sheet).toBeVisible();
    return sheet;
}
