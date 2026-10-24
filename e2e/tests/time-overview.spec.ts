import fs from 'node:fs';
import { expect, test, uid, urls } from '../fixtures';
import { USERS } from '../env';
import { addToProject, billingUrl, choose, filterProjects, logWork, memberId, overviewRow, projectWithIssue } from '../helpers/time-overview';

/**
 * Billing → Time + Clients (BRAI-100): two people log time on two projects, the workspace admin
 * reviews it for this month, groups it by person, exports Excel and filters by client. Members
 * never see billing. Each journey creates its own projects, so parallel runs never share totals.
 */
const owner = USERS.owner.name;
const member = USERS.member.name;

test.describe('team time overview', () => {
    test('the admin filters two projects, groups by person and downloads the Excel', async ({ page, memberPage }) => {
        test.slow(); // two projects, four work logs, several reloads and a download
        const web = await projectWithIssue(page, uid('Checkout bug'));
        const app = await projectWithIssue(page, uid('Login screen'));
        const maxId = await memberId(page);
        await addToProject(page, web.slug, maxId);
        await addToProject(page, app.slug, maxId);

        // two people, two projects, through the real "Log work" endpoint
        await logWork(page, web.slug, web.itemId, '1h 30m');
        await logWork(page, app.slug, app.itemId, '2h');
        await logWork(memberPage, web.slug, web.itemId, '45m');
        await logWork(memberPage, app.slug, app.itemId, '1h');

        // Billing in the sidebar opens the first billing tab (Reports); then the Time tab
        await page.goto(urls.workspace());
        await page.getByRole('link', { name: 'Billing', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${billingUrl('reports')}$`));
        await page.getByRole('navigation', { name: 'Billing' }).getByRole('link', { name: 'Time', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${billingUrl('time')}`));
        await expect(page.getByRole('link', { name: 'Time', exact: true })).toHaveAttribute('aria-current', 'page');
        await expect(page.getByRole('button', { name: 'Last month' })).toHaveAttribute('aria-pressed', 'true');

        await page.getByRole('button', { name: 'This month' }).click();
        await expect(page.getByRole('button', { name: 'This month' })).toHaveAttribute('aria-pressed', 'true');

        // one project: 1.5 h + 0.75 h, by ticket
        await filterProjects(page, [web.name]);
        await expect(page.getByTestId('time-total-hours')).toContainText('2.25 h');
        await expect(page.getByTestId('time-total-entries')).toContainText('2');
        await expect(overviewRow(page, new RegExp(web.name))).toContainText('2.25 h');

        // by person
        await choose(page, 'Group by', 'Person');
        await expect(page).toHaveURL(/group=person/);
        await expect(overviewRow(page, new RegExp(`^${owner}`))).toContainText('1.5 h');
        await expect(overviewRow(page, new RegExp(`^${member}`))).toContainText('0.75 h');

        // both projects
        await filterProjects(page, [app.name], 1);
        await expect(page.getByTestId('time-total-hours')).toContainText('5.25 h');
        await expect(overviewRow(page, new RegExp(`^${owner}`))).toContainText('3.5 h');
        await expect(overviewRow(page, new RegExp(`^${member}`))).toContainText('1.75 h');
        await expect(page.getByTestId('time-total-non-billable')).toContainText('0 h');
        // the seeded activities are priced, so there is an estimate in the workspace currency
        await expect(page.getByTestId('time-total-estimate')).toContainText('€');

        // entry detail lists all four entries
        await page.getByLabel('Show entries').check();
        await expect(page.getByTestId('time-entry')).toHaveCount(4);

        // Excel of exactly these entries
        const downloading = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Export Excel' }).click();
        const download = await downloading;
        expect(download.suggestedFilename()).toMatch(/^time-\d{4}-\d{2}\.xlsx$/);
        const file = await download.path();
        const bytes = fs.readFileSync(file);
        expect(bytes.length).toBeGreaterThan(1000);
        expect(bytes.subarray(0, 2).toString()).toBe('PK'); // a zip: xlsx
    });

    test('members get 403 on billing pages', async ({ memberPage }) => {
        for (const tab of ['time', 'clients'] as const) {
            const response = await memberPage.goto(billingUrl(tab));
            expect(response?.status(), tab).toBe(403);
        }
        const response = await memberPage.goto(`${billingUrl('time')}/export`);
        expect(response?.status()).toBe(403);
        await memberPage.goto(urls.workspace());
        await expect(memberPage.getByRole('link', { name: 'Billing', exact: true })).toHaveCount(0);
    });

    test('a client gets both projects assigned and the overview filters by it', async ({ page }) => {
        test.slow(); // two projects, a client, settings and the overview
        const web = await projectWithIssue(page, uid('Newsletter'));
        const app = await projectWithIssue(page, uid('Push'));
        await logWork(page, web.slug, web.itemId, '1h');
        await logWork(page, app.slug, app.itemId, '30m');

        await page.goto(billingUrl('time'));
        await page.getByRole('link', { name: 'Clients', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${billingUrl('clients')}$`));

        const name = uid('Muster GmbH');
        await page.getByRole('button', { name: 'New client' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'New client' });
        await dialog.getByLabel('Name').fill(name);
        await dialog.getByLabel('Billing email').fill('billing@muster.example.test');
        await dialog.getByLabel('VAT rate (%)').fill('19');
        await dialog.getByRole('button', { name: 'Create client' }).click();
        await expect(dialog).toBeHidden();

        const card = page.getByRole('listitem').filter({ has: page.getByRole('heading', { name }) });
        await expect(card).toContainText('19 % VAT');
        await card.getByRole('button', { name: 'Assign projects' }).click();
        const assign = page.getByRole('dialog', { name: `Projects of ${name}` });
        await assign.getByRole('checkbox', { name: web.name }).check();
        await assign.getByRole('checkbox', { name: app.name }).check();
        await assign.getByRole('button', { name: 'Save projects' }).click();
        await expect(assign).toBeHidden();
        const projects = card.getByRole('list', { name: `Projects of ${name}` });
        await expect(projects).toContainText(web.name);
        await expect(projects).toContainText(app.name);

        // project settings show the client to workspace admins
        await page.goto(`${urls.project(web.slug)}/edit`);
        await expect(page.getByRole('combobox', { name: 'Client' })).toContainText(name);

        // the overview, filtered by the client: both projects, nothing else
        await page.goto(billingUrl('time'));
        await page.getByRole('button', { name: 'This month' }).click();
        await expect(page.getByRole('button', { name: 'This month' })).toHaveAttribute('aria-pressed', 'true');
        await choose(page, 'Client', name);
        await expect(page).toHaveURL(/client=\d+/);
        await expect(page.getByTestId('time-total-hours')).toContainText('1.5 h');
        await expect(overviewRow(page, new RegExp(web.name))).toContainText('1 h');
        await expect(overviewRow(page, new RegExp(app.name))).toContainText('0.5 h');
    });
});
