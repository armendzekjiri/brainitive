import { expect, type Locator, type Page } from '@playwright/test';
import { workspaceUrl } from './content';

/** Quick capture (BRAI-70) helpers. */

export const captureDialog = (page: Page): Locator => page.getByRole('dialog', { name: 'Quick capture' });
export const organizeDialog = (page: Page): Locator => page.getByRole('dialog', { name: 'Organize capture' });

/** A capture row on /captures, by the first line of its text. */
export const captureCard = (page: Page, firstLine: string): Locator => page.getByRole('article', { name: `Capture: ${firstLine}` });

/** Fill the open capture dialog and save it; resolves once the toast confirms. */
export async function saveInDialog(page: Page, text: string, opts: { url?: string; viaKeyboard?: boolean } = {}): Promise<void> {
    const dialog = captureDialog(page);
    await expect(dialog).toBeVisible();
    await dialog.getByLabel('Idea').fill(text);
    if (opts.url) await dialog.getByLabel('Link (optional)').fill(opts.url);
    if (opts.viaKeyboard) {
        await dialog.getByLabel('Idea').press('ControlOrMeta+Enter');
    } else {
        await dialog.getByRole('button', { name: 'Save capture' }).click();
    }
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Saved to your captures')).toBeVisible();
}

/** Setup shortcut: create a capture through the endpoint the dialog posts to. */
export async function createCapture(page: Page, text: string, url?: string): Promise<void> {
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await page.request.post('/captures', {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
        form: url ? { text, url } : { text },
        maxRedirects: 0,
    });
    expect(response.status(), `create capture: ${response.status()}`).toBe(302);
}

/** Open /captures filtered to one capture (search keeps parallel tests apart). */
export async function openCaptures(page: Page, search: string, state: 'inbox' | 'processed' | 'archived' = 'inbox'): Promise<void> {
    await page.goto(`/captures?state=${state}&q=${encodeURIComponent(search)}`);
    await expect(page.getByRole('heading', { name: 'Captures', level: 1 })).toBeVisible();
}

/**
 * Organize a capture: open the dialog, pick the type and destination (option inside its
 * "Workspace / Project" group), then create. Returns the dialog showing the success state.
 */
export async function organize(page: Page, firstLine: string, type: string, group: string, destination: string): Promise<Locator> {
    await captureCard(page, firstLine).getByRole('button', { name: 'Organize' }).click();
    const dialog = organizeDialog(page);
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: type, exact: true }).click();
    await expect(dialog.getByLabel('Title')).not.toHaveValue('');
    await dialog.getByRole('combobox', { name: 'Destination' }).click();
    await page.getByRole('group', { name: group }).getByRole('option', { name: destination, exact: true }).click();
    return dialog;
}

/** Create a bookmark collection in the seeded workspace (same steps as content.spec). */
export async function createBookmarkCollection(page: Page, name: string): Promise<void> {
    await page.goto(workspaceUrl('bookmarks'));
    await page.getByRole('button', { name: 'Add Bookmark' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New bookmark collection' });
    await dialog.getByLabel('Name').fill(name);
    await dialog.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
}

/** Create a snippet collection in the seeded workspace (same steps as content.spec). */
export async function createSnippetCollection(page: Page, name: string): Promise<void> {
    await page.goto(workspaceUrl('snippets'));
    await page.getByRole('button', { name: 'New Collection' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New Snippet Collection' });
    await dialog.getByLabel('Name').fill(name);
    await dialog.getByRole('button', { name: 'Create' }).click();
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible();
}
