import { expect, type Locator, type Page } from '@playwright/test';
import { SEED } from '../env';

/** Helpers for the billing reports journeys (tests/billing-reports.spec.ts, BRAI-101). */

export const reportsUrl = `/workspaces/${SEED.workspace}/billing/reports`;

export const reportTotal = (page: Page): Locator => page.getByTestId('report-total');

/** New report for a client, default period (last month); lands on the draft. */
export async function createReport(page: Page, client: string) {
    await page.goto(reportsUrl);
    await page.getByRole('button', { name: 'New report' }).click();
    const dialog = page.getByRole('dialog', { name: 'New report' });
    await dialog.getByLabel('Client').click();
    await page.getByRole('option', { name: client, exact: true }).click();
    await dialog.getByRole('button', { name: 'Create draft' }).click();
    await expect(page).toHaveURL(new RegExp(`${reportsUrl}/\\d+$`));
}

/** Void a final report from its page (confirm dialog). */
export async function voidReport(page: Page) {
    await page.getByRole('button', { name: 'Void', exact: true }).click();
    const confirm = page.getByRole('alertdialog', { name: 'Void this report?' });
    const paid = confirm.getByRole('checkbox');
    if (await paid.count()) await paid.check();
    await confirm.getByRole('button', { name: 'Void report' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'This report was voided' })).toBeVisible();
}

/**
 * A retried journey must start from billable entries: void what an earlier attempt finalized and
 * delete its drafts (the seeded entries are only billed by this spec).
 */
export async function resetReports(page: Page, client: string) {
    for (const status of ['finalized', 'sent', 'paid', 'draft'] as const) {
        for (;;) {
            await page.goto(`${reportsUrl}?status=${status}`);
            const row = page.getByRole('list', { name: 'Reports' }).getByRole('link').filter({ hasText: client }).first();
            if (!(await row.count())) break;
            await row.click();
            if (status === 'draft') {
                await page.getByRole('button', { name: 'Delete draft' }).click();
                await page.getByRole('alertdialog').getByRole('button', { name: 'Delete draft' }).click();
                await expect(page).toHaveURL(new RegExp(`${reportsUrl}$`));
            } else {
                await voidReport(page);
            }
        }
    }
}
