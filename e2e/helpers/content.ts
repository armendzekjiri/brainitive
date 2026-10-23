import { expect, type Page } from '@playwright/test';
import { SEED } from '../env';
import { urls } from '../fixtures';

export type ContentKind = 'Page' | 'Whiteboard' | 'List';

/**
 * Create a doc page, whiteboard or task list in the seeded project through the sidebar's
 * "Add content" dialog (AppLayout CreateItemModal). The app redirects to the new item.
 */
export async function addProjectContent(page: Page, kind: ContentKind, name: string) {
    await page.goto(urls.project());
    await page.getByRole('button', { name: 'Add content' }).first().click();

    const dialog = page.getByRole('dialog', { name: 'Create New' });
    await dialog.getByRole('button', { name: kind, exact: true }).click();

    const form = page.getByRole('dialog', { name: `Create ${kind}` });
    await form.getByLabel(`${kind} Name`).fill(name);
    await form.getByRole('button', { name: `Create ${kind}` }).click();
    await expect(form).toBeHidden();
}

/** Lexical rich-text editor of a document page (the editable one). */
export const docEditor = (page: Page) => page.locator('.lexical-editor-content[contenteditable="true"]');

/** Lexical editor in read mode (published page, not editing). */
export const docReader = (page: Page) => page.locator('.lexical-editor-content');

/** Resolves on the next successful autosave of a page draft (PUT …/pages/{uuid}/draft). */
export const draftSaved = (page: Page) =>
    page.waitForResponse((r) => r.request().method() === 'PUT' && /\/pages\/[^/]+\/draft$/.test(new URL(r.url()).pathname) && r.ok());

/** A date as YYYY-MM-DD in local time (what the calendar's ?date= expects). */
export const isoDay = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export const workspaceUrl = (path: string) => `/workspaces/${SEED.workspace}/${path}`;

/** First publish of a draft doc; resolves once the page reloaded in read mode. */
export async function publish(page: Page) {
    const published = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/publish'));
    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await published;
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
}
