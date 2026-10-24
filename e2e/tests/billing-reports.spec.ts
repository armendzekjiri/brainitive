import { expect, test, urls } from '../fixtures';
import { createReport, reportTotal, reportsUrl, resetReports, voidReport } from '../helpers/billing-reports';

/**
 * Billing reports (BRAI-101): a workspace admin turns last month's hours of a client into a report,
 * leaves an entry out, finalizes (the entries are locked: a second report finds nothing), then voids
 * it and the time is billable again. Seed ("v1.38.0 billing fixtures" in E2eSeeder): client Muster
 * GmbH on `website`, last month WEB-3 Programming 200 + 60 min = 520.00, WEB-1 Programming 45 min =
 * 90.00, WEB-2 Testing 90 min = 67.50 → 677.50 (no VAT, no rounding).
 */
const CLIENT = 'Muster GmbH';

test.describe('billing reports', () => {
    test('an admin bills last month: lines and total, leave out, finalize locks, void unlocks', async ({ page }) => {
        await resetReports(page, CLIENT);

        // Billing in the sidebar opens the reports
        await page.goto(urls.workspace());
        await page.getByRole('link', { name: 'Billing', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${reportsUrl}$`));

        await createReport(page, CLIENT);
        const doc = page.getByTestId('report-document');
        await expect(doc).toContainText(CLIENT);
        const lines = doc.getByTestId('report-line');
        await expect(lines).toHaveCount(3);
        await expect(lines.nth(0)).toContainText('WEB-1');
        await expect(lines.nth(0)).toContainText('Homepage hero');
        await expect(lines.nth(0)).toContainText('90.00');
        await expect(lines.nth(1)).toContainText('Pricing page copy');
        await expect(lines.nth(1)).toContainText('Testing');
        await expect(lines.nth(1)).toContainText('67.50');
        await expect(lines.nth(2)).toContainText('Checkout bug');
        await expect(lines.nth(2)).toContainText('Erin Owner, Max Member');
        await expect(lines.nth(2)).toContainText('4.33 h');
        await expect(lines.nth(2)).toContainText('520.00');
        await expect(reportTotal(page)).toContainText('677.50');

        // leave the WEB-1 entry out: the total drops, the entry is listed as left out
        await doc.getByRole('button', { name: /^Show entries of WEB-1/ }).click();
        await doc.getByRole('list', { name: /^Time entries of WEB-1/ }).getByRole('button', { name: /^Leave out Erin Owner/ }).click();
        await expect(reportTotal(page)).toContainText('587.50');
        await expect(lines).toHaveCount(2);
        const leftOut = page.getByRole('region', { name: 'Left out' });
        await expect(leftOut).toContainText('Homepage hero');

        // …and back in
        await leftOut.getByRole('button', { name: /^Include again/ }).click();
        await expect(reportTotal(page)).toContainText('677.50');
        await expect(leftOut).toBeHidden();

        // finalize: confirm, the stored document stays, nothing can be left out any more
        await page.getByRole('button', { name: 'Finalize', exact: true }).click();
        const confirm = page.getByRole('alertdialog', { name: 'Finalize this report?' });
        await expect(confirm).toContainText('4 time entries get locked');
        await confirm.getByRole('button', { name: 'Finalize' }).click();
        await expect(page.getByText('Finalized', { exact: true })).toBeVisible();
        await expect(page.getByText('Report finalized. Its time entries are locked.')).toBeVisible();
        await expect(reportTotal(page)).toContainText('677.50');
        await expect(page.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
        await doc.getByRole('button', { name: /^Show entries of WEB-3/ }).click();
        await expect(doc.getByRole('list', { name: /^Time entries of WEB-3/ }).getByRole('listitem')).toHaveCount(2);
        await expect(doc.getByRole('button', { name: /^Leave out/ })).toHaveCount(0);
        const finalizedUrl = page.url();

        // the entries are locked: a new report for the same period has nothing to bill
        await createReport(page, CLIENT);
        await expect(page.getByText('Nothing to bill: there is no unbilled time')).toBeVisible();
        await expect(reportTotal(page)).toContainText('0.00');
        await expect(page.getByRole('button', { name: 'Finalize', exact: true })).toBeDisabled();
        const secondUrl = page.url();

        // void the first one: billable again
        await page.goto(finalizedUrl);
        await voidReport(page);
        await expect(page.getByText('Void', { exact: true })).toBeVisible();
        await page.goto(secondUrl);
        await expect(page.getByTestId('report-line')).toHaveCount(3);
        await expect(reportTotal(page)).toContainText('677.50');

        // the list shows both, filtered by status
        await page.goto(`${reportsUrl}?status=void`);
        await expect(page.getByRole('list', { name: 'Reports' }).getByRole('link').filter({ hasText: CLIENT }).first()).toContainText('Void');

        // clean up the second draft
        await page.goto(secondUrl);
        await page.getByRole('button', { name: 'Delete draft' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete draft' }).click();
        await expect(page).toHaveURL(new RegExp(`${reportsUrl}$`));
    });

    test('members see no billing', async ({ memberPage }) => {
        const response = await memberPage.goto(reportsUrl);
        expect(response?.status()).toBe(403);

        await memberPage.goto(urls.workspace());
        await expect(memberPage.getByRole('link', { name: 'Billing', exact: true })).toHaveCount(0);
    });
});
