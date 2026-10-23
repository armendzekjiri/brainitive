import { expect, type Page } from '@playwright/test';
import { uid, urls } from '../fixtures';

/** A random project key (letters only, the store rule is `alpha`, max 10). */
export function projectKey(): string {
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    let key = 'E';
    for (let i = 0; i < 7; i++) key += letters[Math.floor(Math.random() * letters.length)];
    return key;
}

/** Create a workspace through the "Create Workspace" form; lands on the new workspace. */
export async function createWorkspace(page: Page, name = uid('Workspace')): Promise<{ name: string; slug: string }> {
    await page.goto('/workspaces/create');
    await page.getByLabel('Workspace Name').fill(name);
    await page.getByRole('button', { name: 'Create Workspace' }).click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    const slug = new URL(page.url()).pathname.split('/')[2];
    expect(slug, 'workspace slug in the URL').toBeTruthy();
    return { name, slug };
}

/** Create a blank project through the full-page gallery; lands on its Main Board. */
export async function createBlankProject(page: Page, workspaceSlug: string, name = uid('Project'), key = projectKey()): Promise<{ name: string; key: string; slug: string }> {
    await page.goto(`${urls.workspace(workspaceSlug)}/projects/create`);
    await page.getByRole('button', { name: /Blank project/ }).click();
    await page.getByLabel('Project name').fill(name);
    await page.getByLabel('Project key').fill(key);
    await page.getByRole('button', { name: 'Create project', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/workspaces/${workspaceSlug}/projects/[^/]+/boards/main-board$`));
    const slug = new URL(page.url()).pathname.split('/')[4];
    return { name, key, slug };
}

/** Open a settings tab (Workspace or Project settings both use a vertical tab list). */
export async function openSettingsTab(page: Page, tab: string | RegExp): Promise<void> {
    await page.getByRole('tab', { name: tab }).click();
}
