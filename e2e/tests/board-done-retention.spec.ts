import type { Locator, Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { SEED } from '../env';
import { card, closeIssue, column, createCard, openCard } from '../helpers/boards';

/**
 * BRAI-346: done column retention, like Jira's "hide completed issues older than…". The board
 * needs a card that was completed 10 days ago; the Jira CSV import keeps an issue's "Resolved" date
 * as its completion time, so the journey imports a two-issue file into its own board (imported
 * boards start with retention Off: their whole history is visible until someone turns it on).
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** A date N days ago the way Jira's CSV export writes it: "20/Oct/26 9:05 AM" (UTC). */
const daysAgo = (days: number) => {
    const d = new Date(Date.now() - days * 24 * 60 * 60 * 1000);
    const hours = d.getUTCHours();
    const minutes = String(d.getUTCMinutes()).padStart(2, '0');
    return `${d.getUTCDate()}/${MONTHS[d.getUTCMonth()]}/${String(d.getUTCFullYear()).slice(2)} ${hours % 12 || 12}:${minutes} ${hours < 12 ? 'AM' : 'PM'}`;
};

function jiraCsv(oldTitle: string, openTitle: string): Buffer {
    return Buffer.from([
        'Summary,Issue key,Status,Status Category,Created,Resolved',
        `"${oldTitle}",RET-1,Done,Done,"${daysAgo(12)}","${daysAgo(10)}"`,
        `"${openTitle}",RET-2,To Do,To Do,"${daysAgo(12)}",`,
    ].join('\n') + '\n');
}

/** Import the file as a new Kanban board in the seeded project; returns the board's path. */
async function importBoard(page: Page, boardName: string, file: Buffer): Promise<string> {
    await page.goto(`/workspaces/${SEED.workspace}/imports`);
    await page.getByRole('radio', { name: /Jira \(CSV\)/ }).click();
    await page.getByRole('combobox', { name: 'Project' }).click();
    await page.getByRole('option', { name: 'Website', exact: true }).click();
    await page.getByLabel('Exported file').setInputFiles({ name: 'retention.csv', mimeType: 'text/csv', buffer: file });
    await page.getByRole('button', { name: 'Upload and preview' }).click();
    await expect(page.getByTestId('import-status')).toHaveText('Ready to import');
    await page.getByLabel('Board name').fill(boardName);
    await page.getByRole('button', { name: 'Start import', exact: true }).click();
    await expect(page.getByTestId('import-status')).toHaveText('Done', { timeout: 30_000 });
    await page.getByRole('link', { name: 'Open what was imported' }).click();
    await expect(page.getByRole('heading', { level: 1, name: boardName })).toBeVisible();
    return new URL(page.url()).pathname;
}

async function setRetention(page: Page, boardUrl: string, option: string) {
    await page.goto(`${boardUrl}/edit`);
    const settings = page.locator('#done-column');
    await settings.getByRole('combobox', { name: 'Keep done cards on the board for' }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
    const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().endsWith('/done-retention'));
    await settings.getByRole('button', { name: 'Save', exact: true }).click();
    await saved;
    await expect(settings.getByText('Unsaved changes')).toHaveCount(0);
}

const doneNote = (page: Page): Locator => column(page, 'Done').getByText(/older done cards? (hidden|·)|Showing \d+ older done/);

test('older done cards leave the board after the chosen days; Show brings them back', async ({ page }) => {
    const [oldTitle, openTitle, freshTitle] = [uid('shipped long ago'), uid('still open'), uid('just finished')];
    const boardUrl = await importBoard(page, uid('Retention board'), jiraCsv(oldTitle, openTitle));

    // imported boards show everything: the card finished 10 days ago is in Done
    await expect(card(page, oldTitle, 'Done')).toBeVisible();
    await expect(card(page, openTitle, 'To Do')).toBeVisible();
    await expect(doneNote(page)).toHaveCount(0);

    // keep done cards for 7 days: it leaves the board, the column says so
    await setRetention(page, boardUrl, '7 days');
    await page.goto(boardUrl);
    await expect(card(page, openTitle, 'To Do')).toBeVisible();
    await expect(card(page, oldTitle)).toHaveCount(0);
    await expect(column(page, 'Done').getByText('1 older done card hidden')).toBeVisible();

    // it stays in the project: the list view still lists it
    await page.goto(`${boardUrl}?view=list`);
    await expect(page.getByRole('row').filter({ hasText: oldTitle })).toBeVisible();
    await page.goto(`${boardUrl}?view=kanban`);

    // a card finished now stays on the board
    await createCard(page, freshTitle);
    const dialog = await openCard(page, freshTitle);
    const moved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+\/move$/.test(new URL(r.url()).pathname));
    await dialog.getByRole('combobox', { name: 'Status' }).click();
    await page.getByRole('option', { name: 'Done', exact: true }).click();
    await moved;
    await closeIssue(page);
    await page.reload();
    await expect(card(page, freshTitle, 'Done')).toBeVisible();
    await expect(card(page, oldTitle)).toHaveCount(0);

    // Show: the older card is back, for this tab (it survives a reload), Hide puts it away again
    await column(page, 'Done').getByRole('button', { name: 'Show older done cards' }).click();
    await expect(card(page, oldTitle, 'Done')).toBeVisible();
    await expect(column(page, 'Done').getByText('Showing 1 older done card')).toBeVisible();
    await page.reload();
    await expect(card(page, oldTitle, 'Done')).toBeVisible();
    await column(page, 'Done').getByRole('button', { name: 'Hide older done cards' }).click();
    await expect(card(page, oldTitle)).toHaveCount(0);
    await expect(card(page, freshTitle, 'Done')).toBeVisible();

    // 14 days: the card finished 10 days ago is back for everyone
    await setRetention(page, boardUrl, '14 days');
    await page.goto(boardUrl);
    await expect(card(page, oldTitle, 'Done')).toBeVisible();
    await expect(doneNote(page)).toHaveCount(0);
});
