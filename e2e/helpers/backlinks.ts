import { expect, type APIResponse, type Locator, type Page } from '@playwright/test';
import { SEED } from '../env';

/** Helpers for the linked content / backlinks journeys (tests/backlinks.spec.ts, BRAI-71). */

const projectPath = `/workspaces/${SEED.workspace}/projects/${SEED.project}`;

export type RefType = 'item' | 'page' | 'ticket' | 'plan';

interface Entry { key: string; type: RefType; id: number; title: string; manual: boolean; derived: boolean; reference_id: number | null }
interface List { related: Entry[]; referenced_by: Entry[]; can_link: boolean }

/** A request as the signed-in user of `page` (session cookie + CSRF header). */
export async function send(page: Page, method: 'GET' | 'POST' | 'PUT' | 'DELETE', url: string, data?: Record<string, unknown>): Promise<APIResponse> {
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    return page.request.fetch(url, {
        method,
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' },
        data,
        maxRedirects: 0,
    });
}

/** "Related content" / "Referenced by" of a resource as JSON (what the section renders). */
export async function referencesOf(page: Page, type: RefType, id: number): Promise<List> {
    const response = await send(page, 'GET', `${projectPath}/references?type=${type}&id=${id}`);
    expect(response.ok(), `references of ${type}:${id}`).toBeTruthy();
    return response.json() as Promise<List>;
}

/** Find something the source could link to by its title (picker search endpoint). */
export async function findCandidate(page: Page, source: { type: RefType; id: number }, title: string): Promise<{ type: RefType; id: number }> {
    const response = await send(page, 'GET', `${projectPath}/references/candidates?type=${source.type}&id=${source.id}&q=${encodeURIComponent(title)}`);
    expect(response.ok()).toBeTruthy();
    const { results } = (await response.json()) as { results: { type: RefType; id: number; title: string }[] };
    const hit = results.find((r) => r.title === title);
    expect(hit, `candidate "${title}"`).toBeTruthy();
    return { type: hit!.type, id: hit!.id };
}

/** Link source → target over the API; returns the JSON ({ id, created }). */
export async function linkContent(page: Page, source: { type: RefType; id: number }, target: { type: RefType; id: number }) {
    const response = await send(page, 'POST', `${projectPath}/references`, {
        source_type: source.type, source_id: source.id, target_type: target.type, target_id: target.id,
    });
    expect(response.ok(), `link: ${response.status()} ${await response.text()}`).toBeTruthy();
    return response.json() as Promise<{ id: number; created: boolean }>;
}

/** The "Related content" section (a region) inside `scope` (page or dialog). */
export const relatedSection = (scope: Page | Locator): Locator => scope.getByRole('region', { name: 'Related content' });

/** The "Related content" (what this links to) list of a section. */
export const relatedList = (section: Locator): Locator => section.getByRole('list', { name: 'Related content' });

/** The "Referenced by" (backlinks) list of a section. */
export const backlinkList = (section: Locator): Locator => section.getByRole('list', { name: 'Referenced by' });

/** Pick `title` in the "Link related content" picker of a section. */
export async function linkWithPicker(page: Page, section: Locator, title: string) {
    await section.getByRole('button', { name: 'Link related content' }).click();
    const picker = page.getByRole('dialog', { name: 'Link related content' });
    await picker.getByLabel('Search content').fill(title);
    const result = picker.getByRole('button', { name: new RegExp(escapeRegExp(title)) });
    await expect(result).toBeEnabled();
    const saved = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/references'));
    await result.click();
    expect((await saved).ok()).toBeTruthy();
    // linked: the result now says so and can't be picked twice
    await expect(result).toBeDisabled();
    await expect(result).toContainText('Linked');
    await page.keyboard.press('Escape');
    await expect(picker).toBeHidden();
}

/** Create a plan in the seeded project over HTTP; returns its slug and id. */
export async function createPlan(page: Page, name: string): Promise<{ slug: string; url: string }> {
    const response = await send(page, 'POST', `${projectPath}/plans`, { name });
    expect(response.status(), 'plan created (redirect to it)').toBe(302);
    const url = new URL(response.headers().location ?? '', 'http://x').pathname;
    expect(url).toMatch(/\/plans\/[^/]+$/);
    return { slug: url.split('/').pop()!, url };
}

/** Save a plan document whose single row is linked to `itemId` (or not linked). */
export async function savePlanRow(page: Page, planUrl: string, itemId: number | null, rowName = 'Linked row') {
    const row: Record<string, unknown> = { id: 'r1', name: rowName, level: 0, bars: [] };
    if (itemId) row.itemId = itemId;
    const response = await send(page, 'PUT', `${planUrl}/document`, { data: { rows: [row], dependencies: [] }, force: true });
    expect(response.ok(), `plan save: ${response.status()} ${await response.text()}`).toBeTruthy();
}

/** Save a whiteboard scene with one issue card per id (what the issues modal draws). */
export async function saveWhiteboardCards(page: Page, whiteboardUuid: string, itemIds: number[]) {
    const elements = itemIds.map((id, i) => ({
        id: `card-${id}`, type: 'rectangle', x: i * 320, y: 0, width: 290, height: 84, isDeleted: false,
        customData: { brainitive: 'issue', itemId: id, signature: 'todo|x' },
    }));
    const response = await send(page, 'POST', `${projectPath}/pages/${whiteboardUuid}/excalidraw/save`, {
        diagram_data: JSON.stringify({ elements, appState: {}, files: {} }),
        force: true,
    });
    expect(response.ok(), `whiteboard save: ${response.status()} ${await response.text()}`).toBeTruthy();
}

export const pageUrl = (uuid: string) => `${projectPath}/pages/${uuid}`;

function escapeRegExp(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
