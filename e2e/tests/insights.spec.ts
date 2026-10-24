import fs from 'node:fs';
import { expect, test, uid, urls } from '../fixtures';
import { USERS } from '../env';
import { insightsUrl, openTab, pickProject } from '../helpers/insights';
import { logWork, projectWithIssue } from '../helpers/time-overview';

/**
 * Admin insights (BRAI-105): the workspace admin opens Insights from the sidebar, narrows it to a
 * project of their own (the shared e2e workspace grows with every parallel test, so exact numbers
 * are only checked for that project), switches the period and the tabs and downloads a CSV.
 * Members see no link and get 403.
 */
test.describe('admin insights', () => {
    test('the admin opens Insights from the sidebar, filters a project, switches period and tabs', async ({ page }) => {
        test.slow(); // a project, an issue, a work log and five tabs
        const project = await projectWithIssue(page, uid('Insights issue'));
        await logWork(page, project.slug, project.itemId, '1h 30m');

        await page.goto(urls.workspace());
        await page.getByRole('link', { name: 'Insights', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${insightsUrl('overview')}$`));
        await expect(page.getByRole('heading', { name: 'Insights', level: 1 })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Overview', exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('button', { name: 'Last 30 days' })).toHaveAttribute('aria-pressed', 'true');

        // one project: its one issue (created today, open) and 1.5 h
        await pickProject(page, project.name);
        await expect(page.getByTestId('kpi-created-value')).toHaveText('1');
        await expect(page.getByTestId('kpi-completed-value')).toHaveText('0');
        await expect(page.getByTestId('kpi-completion-rate-value')).toHaveText('0%');
        await expect(page.getByTestId('kpi-hours-value')).toHaveText('1.5 h');
        await expect(page.getByRole('group', { name: 'Issues created and completed per week' })).toBeVisible();
        const openRow = page.getByRole('table', { name: 'Open and overdue by project' }).getByRole('row', { name: new RegExp(project.name) });
        await expect(openRow.getByRole('cell').nth(2)).toHaveText('1'); // open
        await expect(openRow.getByRole('cell').nth(3)).toHaveText('0'); // overdue

        // another period keeps the project
        await page.getByRole('button', { name: 'Last 90 days' }).click();
        await expect(page.getByRole('button', { name: 'Last 90 days' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page).toHaveURL(/period=last_90/);
        await expect(page).toHaveURL(/project=\d+/);
        await expect(page.getByTestId('kpi-created-value')).toHaveText('1');

        // a custom range that ends before today has nothing of this project
        await page.getByRole('button', { name: 'Custom' }).click();
        await page.getByRole('textbox', { name: 'From', exact: true }).fill('2024-01-01');
        await page.getByRole('textbox', { name: 'To', exact: true }).fill('2024-01-31');
        await page.getByRole('button', { name: 'Apply' }).click();
        await expect(page).toHaveURL(/from=2024-01-01/);
        await expect(page.getByTestId('kpi-created-value')).toHaveText('0');
        await expect(page.getByTestId('kpi-hours-value')).toHaveText('0 h');
        await page.getByRole('button', { name: 'This month' }).click();
        await expect(page).not.toHaveURL(/from=/);

        // Team: the owner logged the 1.5 h
        await openTab(page, 'Team', 'team');
        const owner = page.getByRole('table', { name: 'Team' }).getByRole('row', { name: new RegExp(USERS.owner.name) });
        await expect(owner).toContainText('1.5 h');
        await expect(owner.getByRole('link', { name: `Profile of ${USERS.owner.name}` })).toBeVisible();

        // Projects: health, open, hours
        await openTab(page, 'Projects', 'projects');
        const row = page.getByRole('table', { name: 'Projects' }).getByRole('row', { name: new RegExp(project.name) });
        await expect(row).toContainText('No report');
        await expect(row).toContainText('1.5 h');

        await openTab(page, 'Helpdesk & leads', 'helpdesk');
        await expect(page.getByTestId('kpi-tickets-created-value')).toHaveText('0');
        await expect(page.getByRole('region', { name: 'Lead funnel' })).toBeVisible();

        await openTab(page, 'Usage', 'usage');
        await expect(page.getByRole('group', { name: 'Period' })).toHaveCount(0);
        await expect(page.getByTestId('usage-members')).toContainText(/\d/);
        await expect(page.getByRole('table', { name: 'Storage by project' })).toContainText(project.name);
        await expect(page).toHaveURL(/project=\d+/);
    });

    test('each tab downloads its numbers as CSV', async ({ page }) => {
        const project = await projectWithIssue(page, uid('CSV issue'));
        await page.goto(insightsUrl('overview'));
        await pickProject(page, project.name);

        const downloading = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Export CSV' }).click();
        const download = await downloading;
        expect(download.suggestedFilename()).toMatch(/^insights-overview-\d{4}-\d{2}-\d{2}-\d{4}-\d{2}-\d{2}\.csv$/);
        const csv = fs.readFileSync(await download.path(), 'utf8');
        expect(csv).toContain(project.name);
        expect(csv).toContain('"Issues created",1');

        await openTab(page, 'Team', 'team');
        const team = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Export CSV' }).click();
        expect((await team).suggestedFilename()).toMatch(/^insights-team-/);
    });

    test('members have no Insights link and get 403', async ({ memberPage }) => {
        await memberPage.goto(urls.workspace());
        await expect(memberPage.getByRole('link', { name: 'Billing', exact: true })).toHaveCount(0); // sidebar rendered as a member
        await expect(memberPage.getByRole('link', { name: 'Insights', exact: true })).toHaveCount(0);
        for (const tab of ['overview', 'team', 'projects', 'helpdesk', 'usage'] as const) {
            const response = await memberPage.goto(insightsUrl(tab));
            expect(response?.status(), tab).toBe(403);
        }
        const response = await memberPage.goto(`${insightsUrl('overview')}/export`);
        expect(response?.status()).toBe(403);
    });
});
