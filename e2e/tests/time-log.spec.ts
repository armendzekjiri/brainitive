import { expect, test, uid, urls } from '../fixtures';
import { SEED } from '../env';
import { closeIssue, createCard, openCard, openIssueId } from '../helpers/boards';
import { request } from '../helpers/workload';
import { logWork, projectWithIssue } from '../helpers/time-overview';
import { createWorkspace, openSettingsTab } from '../helpers/workspaces-projects';
import type { Locator, Page } from '@playwright/test';

/**
 * Time log (v1.38.0, BRAI-99): logging time asks for the activity (E2eSeeder's billing fixtures:
 * Programming and Testing, hourly), the timer's stop dialog too, and My timesheet shows your week
 * to add, edit and delete entries — never a price.
 */
const timesheetUrl = `/workspaces/${SEED.workspace}/timesheet`;

/** "1h 30m" → 90 (the issue sidebar's "… logged" text). */
function minutesOf(text: string): number {
    if (/No time logged/.test(text)) return 0;
    const units: Record<string, number> = { w: 2400, d: 480, h: 60, m: 1 };
    return [...text.matchAll(/(\d+)([wdhm])/g)].reduce((sum, [, n, unit]) => sum + Number(n) * units[unit!]!, 0);
}

function duration(minutes: number): string {
    const parts: string[] = [];
    let rest = minutes;
    for (const [unit, size] of [['w', 2400], ['d', 480], ['h', 60], ['m', 1]] as const) {
        if (rest >= size) {
            parts.push(`${Math.floor(rest / size)}${unit}`);
            rest %= size;
        }
    }
    return parts.join(' ');
}

const loggedText = (dialog: Locator) => dialog.getByText(/(\d+[wdhm] ?)+ logged|No time logged/).first();

async function pickActivity(page: Page, name: string) {
    await page.getByRole('combobox', { name: /^Activity/ }).click();
    const option = page.getByRole('option', { name: new RegExp(name) });
    await expect(option).toContainText('Per hour'); // the pricing type, never the rate
    await expect(page.getByRole('listbox')).not.toContainText(/120|45,00|€/);
    await option.click();
}

test.describe('time log', () => {
    test('a member logs 1h 30m with an activity on a seeded issue and its time updates', async ({ memberPage: page }) => {
        await page.goto(urls.board());
        const dialog = await openCard(page, 'Pricing page copy');
        // the server requires an activity while the workspace has any
        const id = await openIssueId(dialog);
        const refused = await request(page, 'POST', `${urls.board()}/items/${id}/work-logs`, { time: '1h' });
        expect(refused.status()).toBe(422);
        expect((await refused.json()).errors).toHaveProperty('activity_id');
        const before = minutesOf(await loggedText(dialog).innerText());

        await dialog.getByRole('button', { name: 'Log work' }).click();
        await page.getByLabel('Time spent').fill('1h 30m');
        // required: the workspace has activities, so "Log" waits for one
        const log = page.getByRole('button', { name: 'Log', exact: true });
        await expect(page.getByRole('combobox', { name: /^Activity/ })).toBeVisible();
        await pickActivity(page, 'Testing');
        await expect(page.getByLabel('Date', { exact: true })).not.toHaveValue('');
        await page.getByLabel('What did you work on? (optional)').fill('Copy review');
        await log.click();

        await expect(loggedText(dialog)).toHaveText(`${duration(before + 90)} logged`);
        await dialog.getByRole('tab', { name: 'Work log' }).click();
        await expect(dialog.getByText(/logged 1h 30m · Testing — Copy review/).first()).toBeVisible();
        await closeIssue(page);
    });

    test('stopping the focus timer asks for the activity', async ({ page }) => {
        await page.goto(urls.board());
        const title = uid('Timer issue');
        await createCard(page, title);
        const dialog = await openCard(page, title);
        const id = await openIssueId(dialog);
        await closeIssue(page);

        const started = await request(page, 'POST', '/work-timer', { item_id: id });
        expect(started.status(), await started.text()).toBeLessThan(400);
        await page.goto(timesheetUrl);

        await page.getByRole('button', { name: /^Timer running on/ }).click();
        await page.getByRole('button', { name: /Stop & log/ }).click();
        const review = page.getByRole('dialog', { name: 'Log your time' });
        await expect(review).toBeVisible();
        await expect(review.getByRole('combobox', { name: /^Activity/ })).toBeVisible();
        await review.getByLabel('Time worked').fill('25m');
        await pickActivity(page, 'Programming');
        await review.getByRole('button', { name: 'Log time' }).click();
        await expect(review).toBeHidden();
        await expect(page.getByRole('button', { name: /^Timer running on/ })).toBeHidden();

        // the timesheet shows it (reload: the entry was logged from this page)
        await page.reload();
        const row = page.getByTestId('timesheet-entry').filter({ hasText: title });
        await expect(row).toContainText('25m');
        await expect(row).toContainText('Programming');
        await expect(row).toContainText('timer');
        // clean up: the owner's week stays readable for other runs
        await row.getByRole('button', { name: 'Delete time entry' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
        await expect(row).toHaveCount(0);
    });

    test('a member adds, edits and deletes an entry in My timesheet', async ({ memberPage: page }) => {
        const note = uid('Homepage pairing');
        await page.goto(urls.workspace());
        await page.getByRole('link', { name: 'Timesheet', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${timesheetUrl}$`));
        await expect(page.getByRole('heading', { level: 1, name: /My timesheet/ })).toBeVisible();
        await expect(page.getByTestId('timesheet-week')).toContainText(/Week \d+/);

        await page.getByRole('button', { name: 'Log time', exact: true }).click();
        const form = page.getByRole('dialog', { name: 'Log time' });
        await form.getByRole('combobox', { name: 'Project' }).click();
        await page.getByRole('option', { name: /Website/ }).click();
        await form.getByLabel('Issue (optional)').fill('Homepage hero');
        await form.getByRole('option', { name: /Homepage hero/ }).click();
        await expect(form.getByTestId('entry-issue-picked')).toContainText(`${SEED.key}-1`);
        await form.getByLabel('Time', { exact: true }).fill('45m');
        await pickActivity(page, 'Programming');
        await form.getByLabel('Note (optional)').fill(note);
        // members don't choose billable: it follows the activity (v1.39.0)
        await expect(form.getByLabel('Billable')).toHaveCount(0);
        await expect(form.getByTestId('entry-billable-hint')).toContainText('set by the activity');
        await form.getByRole('button', { name: 'Log time' }).click();
        await expect(form).toBeHidden();

        const row = page.getByTestId('timesheet-entry').filter({ hasText: note });
        await expect(row).toContainText(`${SEED.key}-1`);
        await expect(row).toContainText('Homepage hero');
        await expect(row).toContainText('45m');
        await expect(row.getByRole('link', { name: 'Homepage hero' })).toHaveAttribute('href', /\/items\/\d+$/);

        // edit: 1h 15m, still on the same issue
        await row.getByRole('button', { name: 'Edit time entry' }).click();
        const edit = page.getByRole('dialog', { name: 'Edit time entry' });
        await expect(edit.getByLabel('Time', { exact: true })).toHaveValue('45m');
        await edit.getByLabel('Time', { exact: true }).fill('1h 15m');
        await edit.getByRole('button', { name: 'Save' }).click();
        await expect(edit).toBeHidden();
        await expect(row).toContainText('1h 15m');

        // delete
        await row.getByRole('button', { name: 'Delete time entry' }).click();
        const confirm = page.getByRole('alertdialog');
        await expect(confirm).toContainText('1h 15m');
        await confirm.getByRole('button', { name: 'Delete' }).click();
        await expect(row).toHaveCount(0);

        // the week arrows
        const week = await page.getByTestId('timesheet-week').innerText();
        await page.getByRole('button', { name: 'Previous week' }).click();
        await expect(page.getByTestId('timesheet-week')).not.toHaveText(week);
        await page.getByRole('button', { name: 'This week' }).click();
        await expect(page.getByTestId('timesheet-week')).toHaveText(week);
    });

    test('the workspace admin chooses billable in the timesheet dialog', async ({ page }) => {
        await page.goto(timesheetUrl);
        await page.getByRole('button', { name: 'Log time', exact: true }).click();
        const form = page.getByRole('dialog', { name: 'Log time' });
        await expect(form.getByLabel('Billable')).toBeVisible();
        await expect(form.getByTestId('entry-billable-hint')).toHaveCount(0);
        await page.keyboard.press('Escape');
        await expect(form).toBeHidden();
    });

    test('moving a project takes its unbilled time along', async ({ page }) => {
        test.slow(); // a project, a workspace, a work log and the move
        const project = await projectWithIssue(page, uid('Move me'));
        await logWork(page, project.slug, project.itemId, '1h 30m');
        const target = await createWorkspace(page, uid('Agency'));

        await page.goto(`${urls.project(project.slug)}/edit`);
        await openSettingsTab(page, 'Danger zone');
        await page.getByRole('tabpanel').getByRole('combobox').click();
        await page.getByRole('option', { name: target.name }).click();
        const time = page.getByTestId('move-time');
        await expect(time).toContainText('1h 30m of unbilled time moves with the project');
        await expect(time).toContainText(`“${target.name}” has no activity called`); // the new workspace has no activities yet

        await page.getByLabel(`Type ${project.name} to confirm`).fill(project.name);
        await page.getByRole('button', { name: `Move to ${target.name}` }).click();
        await expect(page).toHaveURL(new RegExp(`/workspaces/${target.slug}/projects/`));
    });

    test('My timesheet fits a phone screen', async ({ memberPage: page }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        await page.goto(timesheetUrl);
        await expect(page.getByRole('heading', { level: 1, name: /My timesheet/ })).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow).toBeLessThanOrEqual(0);
    });
});
