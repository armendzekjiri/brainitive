import type { Locator, Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { REMOTE } from '../env';
import { card, createBoard, createCard, openCard, postJson } from '../helpers/boards';

/**
 * BRAI-123: recurring issues. Templates are managed from the board header's menu (and an issue's
 * "Make this recurring"); `app:create-recurring-issues` creates the issues. Dev has no scheduler and no
 * trigger route, so the "created on the board" part runs locally only (e2e-only route).
 */

/** The board header menu → "Recurring issues" dialog. */
async function openRecurring(page: Page): Promise<Locator> {
    await page.getByRole('button', { name: 'Board menu' }).click();
    await page.getByRole('menuitem', { name: 'Recurring issues' }).click();
    const dialog = page.getByRole('dialog', { name: 'Recurring issues' });
    await expect(dialog).toBeVisible();
    return dialog;
}

const isoToday = () => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};
const weekdayName = () => new Date().toLocaleDateString('en', { weekday: 'long' });
const formatted = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString('en', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

test('a weekly template creates its issue with the "Repeats weekly" badge', async ({ page }) => {
    const prefix = uid('Weekly report');
    const boardUrl = await createBoard(page, uid('Recurring board'));

    let dialog = await openRecurring(page);
    await expect(dialog.getByText('No recurring issues yet.')).toBeVisible();
    await dialog.getByRole('button', { name: 'New recurring issue' }).click();

    dialog = page.getByRole('dialog', { name: 'New recurring issue' });
    await dialog.getByLabel('Title').fill(`${prefix} {{date}}`);
    // weekly on today's weekday, starting today: the preview's first date is today
    await expect(dialog.getByRole('button', { name: weekdayName(), exact: true })).toHaveAttribute('aria-pressed', 'true');
    const next = dialog.getByRole('list', { name: 'Next dates' }).getByRole('listitem');
    await expect(next).toHaveCount(5);
    await expect(next.first()).toHaveText(formatted(isoToday()));
    await expect(dialog.getByText(`Every week on ${weekdayName().slice(0, 3)}`)).toBeVisible();

    await dialog.getByRole('button', { name: 'Create recurring issue' }).click();
    dialog = page.getByRole('dialog', { name: 'Recurring issues' });
    const row = dialog.getByRole('listitem').filter({ hasText: prefix });
    await expect(row).toContainText(`Every week on ${weekdayName().slice(0, 3)}`);
    await expect(row).toContainText(`Next: ${formatted(isoToday())}`);

    test.skip(REMOTE, 'creating the issue needs the e2e-only trigger route (no scheduler on dev)');

    // what the scheduler does at the next run; a second run creates nothing (idempotent)
    const run = await postJson<{ created: { key: string; title: string }[] }>(page, `${boardUrl}/recurring-issues/e2e-run`, {});
    expect(run.created).toHaveLength(1);
    expect(run.created[0].title).toMatch(new RegExp(`^${prefix} \\d{4}-\\d{2}-\\d{2}$`));
    expect((await postJson<{ created: unknown[] }>(page, `${boardUrl}/recurring-issues/e2e-run`, {})).created).toHaveLength(0);

    await page.goto(boardUrl);
    const created = card(page, run.created[0].title, 'To Do');
    await expect(created).toBeVisible();
    await expect(created).toContainText('Repeats weekly');
    await expect(created).toContainText(run.created[0].key);

    dialog = await openRecurring(page);
    await expect(dialog.getByRole('listitem').filter({ hasText: prefix })).toContainText('1 created');
});

test('"Make this recurring" pre-fills the template; pause, resume and delete it', async ({ page }) => {
    const title = uid('Backup check');
    const boardUrl = await createBoard(page, uid('Recurring board'));
    await page.goto(boardUrl);
    await createCard(page, title);
    const issue = await openCard(page, title);

    await issue.getByRole('button', { name: 'Make this recurring' }).click();
    let dialog = page.getByRole('dialog', { name: 'New recurring issue' });
    await expect(dialog.getByLabel('Title')).toHaveValue(title);

    // monthly on the last weekday
    await dialog.getByRole('combobox', { name: 'Repeat' }).click();
    await page.getByRole('option', { name: 'Monthly' }).click();
    await dialog.getByRole('radiogroup', { name: 'Day of the month' }).getByRole('radio').nth(1).check();
    await dialog.getByLabel('Which week').selectOption({ label: 'last' });
    await dialog.getByLabel('Which day').selectOption({ label: 'weekday (Mon–Fri)' });
    await expect(dialog.getByText('Every month on the last weekday')).toBeVisible();
    await expect(dialog.getByRole('list', { name: 'Next dates' }).getByRole('listitem')).toHaveCount(5);
    await dialog.getByRole('button', { name: 'Create recurring issue' }).click();

    dialog = page.getByRole('dialog', { name: 'Recurring issues' });
    const row = dialog.getByRole('listitem').filter({ hasText: title });
    await expect(row).toContainText('Every month on the last weekday');

    await row.getByRole('button', { name: `Pause ${title}` }).click();
    await expect(row).toContainText('Paused');
    await expect(row).not.toContainText('Next:');
    await row.getByRole('button', { name: `Resume ${title}` }).click();
    await expect(row).not.toContainText('Paused');
    await expect(row).toContainText('Next:');

    await row.getByRole('button', { name: `Delete ${title}` }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(dialog.getByRole('listitem').filter({ hasText: title })).toHaveCount(0);
    await expect(dialog.getByText('No recurring issues yet.')).toBeVisible();
});
