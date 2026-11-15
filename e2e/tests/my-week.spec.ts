import { expect, test, uid } from '../fixtures';
import { projectWithIssue } from '../helpers/time-overview';

/**
 * My Week (v1.53.0): the week planner on My Work — free-form lines on any day, a linked issue found
 * with "#", ticking both (the linked one completes the real issue), moving a line to another day.
 */
test.describe('my week', () => {
    test('plan the week with free lines and a linked issue', async ({ page }) => {
        test.slow(); // a project, an issue and several plan writes
        const issue = uid('Quarterly report');
        const note = uid('Call the bank');
        await projectWithIssue(page, issue);

        await page.goto('/my-work');
        await page.getByRole('region', { name: 'My Day' }).getByRole('button', { name: 'Week' }).click();
        const week = page.getByRole('region', { name: 'My Week' });
        await expect(week.getByRole('button', { name: 'Previous week' })).toBeVisible();
        await expect(page).toHaveURL(/plan=week/);

        // the week runs Monday to Sunday and today is one of its days
        const days = week.locator('section[data-date]');
        await expect(days).toHaveCount(7);
        const dates = await days.evaluateAll((els) => els.map((el) => (el as HTMLElement).dataset.date!));
        const monday = dates[0];
        const tuesday = dates[1];
        expect(new Date(`${monday}T12:00:00`).getDay()).toBe(1);
        const day = (date: string) => week.locator(`section[data-date="${date}"]`);

        // a free-form line on Monday
        const mondayInput = day(monday).getByRole('combobox');
        await mondayInput.fill(note);
        await mondayInput.press('Enter');
        await expect(day(monday).getByRole('button', { name: note, exact: true })).toBeVisible();
        await expect(mondayInput).toHaveValue('');

        // link the issue with "#"
        await mondayInput.fill(`#${issue}`);
        const option = page.getByRole('option').filter({ hasText: issue });
        await expect(option).toBeVisible();
        await mondayInput.press('Enter');
        await expect(day(monday).getByRole('link', { name: issue, exact: true })).toBeVisible();

        // tick both: the note itself, the issue through its board
        await day(monday).getByRole('checkbox', { name: `Mark “${note}” as done` }).click();
        await expect(day(monday).getByRole('checkbox', { name: `Reopen “${note}”` })).toHaveAttribute('aria-checked', 'true');
        await day(monday).getByRole('checkbox', { name: `Mark “${issue}” as done` }).click();
        await expect(day(monday).getByRole('checkbox', { name: `Reopen “${issue}”` })).toHaveAttribute('aria-checked', 'true');

        // move the note to Tuesday through its menu
        await day(monday).getByRole('button', { name: `Options for “${note}”` }).click();
        await page.getByRole('menuitem', { name: 'Move to' }).click();
        await page.getByRole('menuitem', { name: new RegExp(`^Tuesday`) }).click();
        await expect(day(tuesday).getByRole('button', { name: note, exact: true })).toBeVisible();
        await expect(day(monday).getByRole('button', { name: note, exact: true })).toHaveCount(0);

        // a line for "Someday" (no day yet), then onto Wednesday by its menu
        const later = uid('Learn Rust');
        const someday = week.locator('section[data-list="someday"]');
        await someday.getByRole('combobox', { name: 'Add a line to Someday' }).fill(later);
        await someday.getByRole('combobox', { name: 'Add a line to Someday' }).press('Enter');
        await expect(someday.getByRole('button', { name: later, exact: true })).toBeVisible();
        await someday.getByRole('button', { name: `Options for “${later}”` }).click();
        await page.getByRole('menuitem', { name: 'Move to' }).click();
        await page.getByRole('menuitem', { name: /^Wednesday/ }).click();
        await expect(day(dates[2]).getByRole('button', { name: later, exact: true })).toBeVisible();
        await expect(someday.getByRole('button', { name: later, exact: true })).toHaveCount(0);

        // all of it is saved
        await page.reload();
        await expect(day(tuesday).getByRole('checkbox', { name: `Reopen “${note}”` })).toBeVisible();
        await expect(day(monday).getByRole('checkbox', { name: `Reopen “${issue}”` })).toBeVisible();

        // weeks page back and forth
        await week.getByRole('button', { name: 'Next week' }).click();
        await expect(page).toHaveURL(/week=\d{4}-\d{2}-\d{2}/);
        await expect(week.getByRole('link', { name: issue, exact: true })).toHaveCount(0);
        await week.getByRole('button', { name: 'This week' }).click();
        await expect(day(monday).getByRole('link', { name: issue, exact: true })).toBeVisible();

        // the Day view links with "#" too (its results open above everything, never clipped)
        await page.getByRole('button', { name: 'Day', exact: true }).click();
        const myDay = page.getByRole('region', { name: 'My Day' });
        const todayInput = myDay.getByRole('combobox', { name: 'Add a line to today' });
        await todayInput.fill(`#${issue}`);
        const hit = page.getByRole('option').filter({ hasText: issue });
        await expect(hit).toBeInViewport();
        await hit.click();
        await expect(myDay.getByRole('link', { name: issue, exact: true })).toBeVisible();
    });
});
