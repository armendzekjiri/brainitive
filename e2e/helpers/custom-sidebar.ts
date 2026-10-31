import { expect, type Locator, type Page } from '@playwright/test';
import { urls } from '../fixtures';

/** The desktop app sidebar. */
export const sidebar = (page: Page): Locator => page.locator('[data-sidebar="sidebar"]');

/** The sidebar's top-level navigation (Home, My Work, …). */
export const mainNav = (page: Page): Locator => page.getByRole('navigation', { name: 'Main' });

/** The "Pinned" group at the top of the active project's sidebar section. */
export const pinnedGroup = (page: Page): Locator => sidebar(page).getByRole('group', { name: 'Pinned' });

/** Resolves on the next successful save of the sidebar preferences. */
export const sidebarSaved = (page: Page) =>
    page.waitForResponse((r) => r.request().method() === 'PATCH' && new URL(r.url()).pathname === '/sidebar/preferences' && r.ok());

/** Open "Customize sidebar" from the pencil at the bottom of the sidebar. */
export async function openCustomize(page: Page): Promise<Locator> {
    await sidebar(page).getByRole('button', { name: 'Customize sidebar' }).click();
    const panel = page.getByRole('dialog', { name: 'Customize sidebar' });
    await expect(panel).toBeVisible();
    return panel;
}

/** Flip one switch in the panel and wait until the server stored it. */
export async function toggle(page: Page, panel: Locator, name: string, checked: boolean) {
    const control = panel.getByRole('switch', { name, exact: true });
    await expect(control).toHaveAttribute('aria-checked', String(!checked));
    const saved = sidebarSaved(page);
    await control.click();
    await saved;
    await expect(control).toHaveAttribute('aria-checked', String(checked));
}

/** Pin an item from its card / row on the open project page and wait until it is saved. */
export async function pin(page: Page, name: string) {
    const saved = sidebarSaved(page);
    await page.getByRole('main').getByRole('button', { name: `Pin ${name}`, exact: true }).click();
    await saved;
    await expect(pinnedGroup(page).getByRole('link', { name, exact: true })).toBeVisible();
}

async function xsrf(page: Page): Promise<Record<string, string>> {
    const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    if (!cookie) throw new Error('no XSRF-TOKEN cookie: open an app page first');
    return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.value), Accept: 'application/json' };
}

/** Setup / cleanup shortcut: back to the default sidebar (same endpoint as "Reset to default"). */
export async function resetSidebarPreferences(page: Page) {
    if (page.url() === 'about:blank') await page.goto(urls.home);
    const res = await page.request.patch('/sidebar/preferences', { headers: await xsrf(page), data: { reset: true } });
    expect(res.ok(), `reset sidebar: ${res.status()}`).toBeTruthy();
}

/** Setup shortcut: a doc page (draft, visible to its creator) in a project; returns its name. */
export async function createDocPage(page: Page, projectSlug: string, name: string, workspace = 'e2e-workspace') {
    const res = await page.request.post(`${urls.project(projectSlug, workspace)}/pages`, {
        headers: { ...(await xsrf(page)), Accept: 'text/html' },
        form: { name, type: 'page' },
    });
    expect(res.ok(), `create page: ${res.status()}`).toBeTruthy();
    return name;
}

/** Setup shortcut: a task list in a project. */
export async function createTaskList(page: Page, projectSlug: string, name: string, workspace = 'e2e-workspace') {
    const res = await page.request.post(`${urls.project(projectSlug, workspace)}/task-lists`, {
        headers: { ...(await xsrf(page)), Accept: 'text/html' },
        form: { name },
    });
    expect(res.ok(), `create list: ${res.status()}`).toBeTruthy();
    return name;
}
