import { expect, type Locator, type Page } from '@playwright/test';
import { urls } from '../fixtures';

/** The "Add" button of a section (Boards, Plans, Lead forms, …) on the seeded project page. */
export function projectSectionAdd(page: Page, heading: string): Locator {
    return page.getByRole('heading', { name: heading, exact: true }).locator('..').getByRole('button', { name: 'Add' });
}

/** The card of an issue on a board. */
export function boardCard(page: Page, title: string): Locator {
    return page.getByRole('article').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
}

/** Create an issue in the To Do column of a board (default: the seeded Main Board) and return its key. */
export async function createIssue(page: Page, title: string, boardUrl = urls.board()): Promise<string> {
    await page.goto(boardUrl);
    await page.getByTitle('Create in To Do').click();
    const dialog = page.getByRole('dialog', { name: 'Create card' });
    const input = dialog.getByPlaceholder('What needs to be done?');
    // the dialog focuses its input once open; typing earlier can be lost
    await expect(input).toBeFocused();
    await input.fill(title);
    await expect(input).toHaveValue(title);
    const created = page.waitForResponse((r) => r.url().endsWith(`${boardUrl}/items`) && r.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    expect((await created).status()).toBeLessThan(400);
    // the dialog stays open (and empty) for the next card; the board behind it is aria-hidden
    await expect(input).toHaveValue('');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    const card = boardCard(page, title);
    await expect(card).toBeVisible();
    const key = (await card.getByText(/^[A-Z]+-\d+$/).textContent())?.trim();
    expect(key, 'issue key on the card').toMatch(/^[A-Z]+-\d+$/);
    return key!;
}

/** yyyy-mm-dd of today + `days` (local time, like the date inputs). */
export function isoDay(days = 0): string {
    const d = new Date();
    d.setDate(d.getDate() + days);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
