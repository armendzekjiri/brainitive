import { REMOTE } from '../env';
import { expect, test, uid } from '../fixtures';
import { createHelpdesk, createProject, projectKey, ticketCard, ticketDialog } from '../helpers/helpdesk';
import { createTicket, runSlaChecker, saveSlaPolicy } from '../helpers/helpdesk-sla';
import { insightsUrl, pickProject } from '../helpers/insights';
import { expectNotification } from '../helpers/notifications';

/*
 * Helpdesk SLA (BRAI-125): an agent sets targets in the helpdesk settings, tickets show their countdown
 * on the board, the list and the ticket panel; the checker (an e2e-only route here, the scheduler in
 * production) turns an overdue ticket into "Breached", notifies the project admin and counts it in
 * Insights. Each test makes its own project, so the keys and the Insights numbers are its own.
 */
test.describe('helpdesk SLA', () => {
    test('an agent sets SLA targets and a new ticket shows "Respond in 2h" on the board, the list and the panel', async ({ page }) => {
        const project = await createProject(page, uid('SLA project'), projectKey());
        const hd = await createHelpdesk(page, uid('SLA desk'), { project });

        // Settings → SLA: Medium (the default priority) = respond in 2 hours, resolve in 1 day
        await page.goto(`${hd.url}/edit?tab=sla`);
        await expect(page.getByRole('tab', { name: 'SLA' })).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByText('Service levels', { exact: true })).toBeVisible();
        await page.getByLabel('Track SLAs for this helpdesk').check();
        await page.getByLabel('First response target for Medium').fill('2');
        await page.getByLabel('First response unit for Medium').selectOption('hours');
        await page.getByLabel('Resolution target for Medium').fill('1');
        await page.getByLabel('Resolution unit for Medium').selectOption('days');
        await page.getByRole('radio', { name: 'Business hours' }).check();
        await expect(page.getByRole('button', { name: 'Mon', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await page.getByRole('radio', { name: '24/7' }).check();
        await page.getByRole('button', { name: 'Save SLA policy' }).click();
        await expect(page.getByText('SLA policy saved.')).toBeVisible();

        // it is still there after a reload
        await page.reload();
        await expect(page.getByLabel('First response target for Medium')).toHaveValue('2');
        await expect(page.getByLabel('Track SLAs for this helpdesk')).toBeChecked();

        const subject = uid('Printer on fire');
        await createTicket(page, hd.url, subject);
        await expect(ticketCard(page, subject).getByTestId('sla-badge')).toHaveText(/Respond in (2h|1\d\dm)/);

        // the list has an SLA column
        await page.getByRole('button', { name: 'List', exact: true }).click();
        await expect(page.getByRole('row').filter({ hasText: subject }).getByTestId('sla-badge')).toHaveText(/Respond in/);
        await page.getByRole('button', { name: 'Board', exact: true }).click();

        // the panel shows both targets
        await ticketCard(page, subject).click();
        const panel = ticketDialog(page).getByTestId('ticket-sla').last();
        await expect(panel).toContainText('First response');
        await expect(panel).toContainText('Resolution');
    });

    test('the checker marks an overdue ticket "Breached", notifies the admin and Insights counts it', async ({ page }) => {
        test.skip(REMOTE, 'the checker trigger is an e2e-only route (dev runs no scheduler and has no such route)');
        test.slow(); // project, helpdesk, ticket, checker, inbox and Insights
        const projectName = uid('SLA breach project');
        const project = await createProject(page, projectName, projectKey());
        const hd = await createHelpdesk(page, uid('Breach desk'), { project });
        await saveSlaPolicy(page, hd.url, { Medium: { first_response: [2, 'hours'], resolution: [1, 'days'] } });

        const late = uid('Late answer');
        const fresh = uid('Fresh question');
        const ticket = await createTicket(page, hd.url, late);
        await createTicket(page, hd.url, fresh);

        // created 3 hours ago: the 2 h first response was due an hour ago
        expect(await runSlaChecker(page, hd.url, ticket.id, 180)).toMatch(/Sent \d+ SLA notification/);

        await page.goto(hd.url);
        await expect(ticketCard(page, late).getByTestId('sla-badge')).toHaveText('Breached 1h ago');
        await expect(ticketCard(page, fresh).getByTestId('sla-badge')).toHaveText(/Respond in/);

        // filter by SLA: only the breached one, then only the ones on track
        await page.getByRole('button', { name: /^Filter/ }).click();
        await page.getByRole('combobox', { name: 'SLA filter' }).click();
        await page.getByRole('option', { name: 'Breached', exact: true }).click();
        await expect(ticketCard(page, late)).toBeVisible();
        await expect(ticketCard(page, fresh)).toHaveCount(0);
        await page.getByRole('combobox', { name: 'SLA filter' }).click();
        await page.getByRole('option', { name: 'On track', exact: true }).click();
        await expect(ticketCard(page, fresh)).toBeVisible();
        await expect(ticketCard(page, late)).toHaveCount(0);
        await page.keyboard.press('Escape');

        // nobody is assigned, so the project admin (the owner, who created the project) hears about it
        const notification = await expectNotification(page, (n) => n.type === 'sla_breached' && n.subject_key === ticket.key, 'SLA breached');
        expect(notification.title).toContain('missed its first-response target');

        // Insights → Helpdesk counts the breach for this project
        await page.goto(insightsUrl('helpdesk'));
        await pickProject(page, projectName);
        await expect(page.getByTestId('kpi-sla-breaches-value')).toHaveText('1');
        await expect(page.getByRole('table', { name: 'Helpdesks' }).getByRole('row', { name: new RegExp(hd.name) })).toContainText('1');
    });
});
