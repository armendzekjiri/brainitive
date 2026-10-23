import type { Browser, BrowserContext, Page } from '@playwright/test';
import { expect, urls } from '../fixtures';

/** A project to put a helpdesk in (default: the seeded one). */
export type ProjectRef = { workspace: string; slug: string };
const SEEDED: ProjectRef = { workspace: 'e2e-workspace', slug: 'website' };

export const helpdeskUrl = (slug: string, project: ProjectRef = SEEDED) => `${urls.project(project.slug, project.workspace)}/helpdesks/${slug}`;
export const embedUrl = (uuid: string) => `/widget/helpdesks/${uuid}/embed`;

export type CreatedHelpdesk = { name: string; slug: string; url: string; uuid: string };

export type FieldSpec = { label: string; type: 'text' | 'select' | 'radio' | 'number' | 'date' | 'checkbox' | 'checkboxes'; options?: string[]; required?: boolean; onCard?: boolean };

/** Laravel's CSRF header for requests sent with the page's own session cookies. */
async function xsrfHeaders(page: Page): Promise<Record<string, string>> {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!cookie) throw new Error('no XSRF-TOKEN cookie: open an app page first');
    return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value), Accept: 'text/html' };
}

/** Props of the Inertia page currently loaded (the initial `data-page` of a full page load). */
export async function pageProps<T = Record<string, any>>(page: Page): Promise<T> {
    return page.locator('#app').evaluate((el) => JSON.parse((el as HTMLElement).dataset.page ?? '{}').props);
}

/**
 * Setup shortcut (not the journey under test): create a helpdesk, optionally from a template, plus
 * custom fields, through the same endpoints the UI posts to. Leaves the page on the agent board.
 */
export async function createHelpdesk(page: Page, name: string, opts: { template?: string; fields?: FieldSpec[]; project?: ProjectRef } = {}): Promise<CreatedHelpdesk> {
    const project = opts.project ?? SEEDED;
    const projectUrl = urls.project(project.slug, project.workspace);
    await page.goto(projectUrl);
    const created = await page.request.post(`${projectUrl}/helpdesks`, {
        headers: await xsrfHeaders(page),
        form: { name, ...(opts.template ? { template: opts.template } : {}) },
    });
    expect(created.ok(), `create helpdesk: ${created.status()}`).toBeTruthy();
    const slug = new URL(created.url()).pathname.split('/').pop()!;
    const url = helpdeskUrl(slug, project);

    for (const field of opts.fields ?? []) {
        const res = await page.request.post(`${url}/fields`, {
            headers: { ...(await xsrfHeaders(page)), 'Content-Type': 'application/json' },
            data: { label: field.label, type: field.type, options: field.options ?? [], is_required: !!field.required, is_visible_on_card: !!field.onCard },
        });
        expect(res.ok(), `add field ${field.label}: ${res.status()}`).toBeTruthy();
    }

    await page.goto(url);
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    const { helpdesk } = await pageProps<{ helpdesk: { uuid: string } }>(page);
    return { name, slug, url, uuid: helpdesk.uuid };
}

/** Setup shortcut: a new workspace owned by the signed-in user; returns its slug. */
export async function createWorkspace(page: Page, name: string): Promise<string> {
    await page.goto(urls.home);
    const res = await page.request.post('/workspaces', { headers: await xsrfHeaders(page), form: { name } });
    expect(res.ok(), `create workspace: ${res.status()}`).toBeTruthy();
    return new URL(res.url()).pathname.split('/')[2];
}

/** Setup shortcut: a new project (the creator becomes its admin; nobody else is a member). */
export async function createProject(page: Page, name: string, key: string, workspace = SEEDED.workspace): Promise<ProjectRef> {
    await page.goto(urls.workspace(workspace));
    const res = await page.request.post(`/workspaces/${workspace}/projects`, { headers: await xsrfHeaders(page), form: { name, key } });
    expect(res.ok(), `create project: ${res.status()}`).toBeTruthy();
    const match = new URL(res.url()).pathname.match(/\/projects\/([^/]+)/);
    expect(match, `project URL: ${res.url()}`).toBeTruthy();
    return { workspace, slug: match![1] };
}

/** A random project key (letters only, as the key rule requires). */
export const projectKey = () => Array.from({ length: 6 }, () => String.fromCharCode(65 + Math.floor(Math.random() * 26))).join('');

/** A visitor without an account: no cookies, no saved session. */
export async function guestPage(browser: Browser): Promise<{ context: BrowserContext; page: Page; close: () => Promise<void> }> {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    // same rule as the fixtures' auto check: an uncaught error in the guest's page fails the test
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    return {
        context,
        page,
        close: async () => {
            await context.close();
            expect(errors, 'uncaught errors in the guest page').toEqual([]);
        },
    };
}

/** Type into a Lexical rich-text editor (contenteditable). */
export async function typeRich(page: Page, editor: ReturnType<Page['locator']>, text: string) {
    await editor.click();
    await page.keyboard.type(text);
    await expect(editor).toContainText(text);
}

/** The ticket card on the agent board, found by its subject. */
export const ticketCard = (page: Page, subject: string) =>
    page.locator('[draggable="true"]').filter({ has: page.getByText(subject, { exact: true }) });

/** A board column (header + its cards), found by the column name. */
export const boardColumn = (page: Page, name: string) =>
    page.locator('div.flex-shrink-0').filter({ has: page.getByRole('heading', { level: 3, name, exact: true }) });

/** The ticket dialog the agent opens from a card. */
export const ticketDialog = (page: Page) => page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Activity' }) });

/** The status select in the ticket dialog's sidebar (desktop layout). */
export const statusSelect = (dialog: ReturnType<Page['getByRole']>) =>
    dialog.locator('div.min-h-\\[40px\\]').filter({ hasText: /^Status/ }).getByRole('combobox');

/** Fill the public form (embed / support page / iframe) — a Page or a FrameLocator. */
export async function fillPublicForm(
    root: Pick<Page, 'getByLabel' | 'locator' | 'getByRole'>,
    page: Page,
    data: { name?: string; email: string; subject?: string; message?: string; fields?: Record<string, string>; selects?: Record<string, string> },
) {
    if (data.name !== undefined) await root.getByLabel(/^Name/).fill(data.name);
    await root.getByLabel('Email *').fill(data.email);
    if (data.subject !== undefined) await root.getByLabel(/^Subject/).fill(data.subject);
    if (data.message !== undefined) {
        const editor = root.locator('[contenteditable="true"]').first();
        await editor.click();
        await page.keyboard.type(data.message);
        await expect(editor).toContainText(data.message);
    }
    for (const [label, value] of Object.entries(data.fields ?? {})) await root.getByLabel(label).fill(value);
    for (const [label, value] of Object.entries(data.selects ?? {})) await root.getByLabel(label).selectOption(value);
}
