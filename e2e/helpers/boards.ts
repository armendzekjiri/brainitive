import { expect, type Locator, type Page } from '@playwright/test';
import { SEED } from '../env';

/** Helpers for the board / issue journeys (tests/boards.spec.ts). */

/**
 * Save the issue description that is open for editing. Locally and in CI journeys edit alone:
 * Save → PATCH /items/{id}. On dev.brainitive.app a realtime server runs, so the description is
 * edited live (BRAI-345): changes are stored while typing and Done closes once the last one is.
 */
export async function saveDescription(page: Page, dialog: Locator): Promise<void> {
    const live = dialog.getByTestId('live-description');
    const save = dialog.getByRole('button', { name: 'Save', exact: true });
    await expect(live.or(save)).toBeVisible();
    if (await live.isVisible()) {
        await live.getByRole('button', { name: 'Done', exact: true }).click();
        await expect(live).toBeHidden();
        return;
    }
    // an Inertia write answers 303, so no r.ok() here
    const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+$/.test(new URL(r.url()).pathname));
    await save.click();
    await saved;
}

/** A kanban column (the <section> whose heading is the column name). */
export const column = (page: Page, name: string): Locator =>
    page.locator('section').filter({ has: page.getByRole('heading', { level: 3, name, exact: true }) });

/** A card on the kanban board, by its title (optionally only inside one column). */
export const card = (page: Page, title: string, columnName?: string): Locator =>
    (columnName ? column(page, columnName) : page)
        .getByRole('article')
        .filter({ has: page.getByRole('heading', { level: 4, name: title, exact: true }) });

/** The issue dialog (accessible name "Edit WEB-n: title"). */
export const issueDialog = (page: Page): Locator => page.getByRole('dialog', { name: /^Edit [A-Z]+-\d+:/ });

/** Create a card through the column's own "Create" button; returns the new card. */
export async function createCard(page: Page, title: string, columnName = 'To Do'): Promise<Locator> {
    await column(page, columnName).getByRole('button', { name: 'Create', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Create card' });
    await expect(dialog).toBeVisible();
    const input = dialog.getByPlaceholder('What needs to be done?');
    await input.fill(title);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    // saved: the dialog stays open, emptied for the next card
    await expect(input).toHaveValue('');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    const created = card(page, title, columnName);
    await expect(created).toBeVisible();
    return created;
}

/** Open a card's issue dialog by clicking it. */
export async function openCard(page: Page, title: string): Promise<Locator> {
    await card(page, title).getByRole('heading', { level: 4 }).click();
    const dialog = issueDialog(page);
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('heading', { level: 1, name: title })).toBeVisible();
    return dialog;
}

/** Numeric id of the issue open in the dialog (from its "Full page" link). */
export async function openIssueId(dialog: Locator): Promise<number> {
    const href = await dialog.getByRole('link', { name: 'Full page' }).getAttribute('href');
    const id = Number(/\/items\/(\d+)/.exec(href ?? '')?.[1]);
    expect(id, `issue id from ${href}`).toBeGreaterThan(0);
    return id;
}

/** The value control of a row in the issue sidebar ("Assignee", "Priority", …). */
export const sidebarField = (dialog: Locator, label: string): Locator =>
    dialog.getByText(label, { exact: true }).locator('xpath=..');

/** Create a kanban board in the seeded project; returns its URL path. */
export async function createBoard(page: Page, name: string): Promise<string> {
    await page.goto(`/workspaces/${SEED.workspace}/projects/${SEED.project}`);
    await page.getByRole('button', { name: 'New board' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Create Board' });
    await dialog.getByPlaceholder('e.g., Sprint Board').fill(name);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page).toHaveURL(/\/boards\/[a-z0-9-]+$/);
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    return new URL(page.url()).pathname;
}

/** Load the board fresh with an issue's deep link (`#item-<id>` opens its dialog). */
export async function openDeepLink(page: Page, boardUrl: string, id: number): Promise<Locator> {
    // a hash-only goto would not reload the board: leave it first
    await page.goto('about:blank');
    await page.goto(`${boardUrl}#item-${id}`);
    const dialog = issueDialog(page);
    await expect(dialog).toBeVisible();
    return dialog;
}

/** JSON POST as the signed-in user of `page` (session cookie + CSRF header), for test setup. */
export async function postJson<T = unknown>(page: Page, url: string, data: Record<string, unknown>): Promise<T> {
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
    const response = await page.request.post(url, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'application/json' },
        data,
    });
    expect(response.ok(), `${url}: ${response.status()} ${await response.text()}`).toBeTruthy();
    return response.json() as Promise<T>;
}

/** Close the issue dialog (Escape; a popover inside may take the first one). */
export async function closeIssue(page: Page): Promise<void> {
    const dialog = issueDialog(page);
    await expect(async () => {
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden({ timeout: 1_000 });
    }).toPass({ timeout: 10_000 });
}
