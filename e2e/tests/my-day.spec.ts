import { expect, test, uid } from '../fixtures';
import { projectWithIssue } from '../helpers/time-overview';
import { newIssue, request } from '../helpers/workload';

/**
 * My Day (BRAI-74): the circle in front of a task completes it — the issue moves to its board's
 * done column — and done tasks sink to the bottom of the plan; clicking again reopens it.
 */
test.describe('my day', () => {
    test('the circle completes a task, it sinks to the bottom, and reopens', async ({ page }) => {
        test.slow(); // a project, two issues and the plan
        const first = uid('Write the intro');
        const second = uid('Review the draft');
        const project = await projectWithIssue(page, first);
        const secondId = await newIssue(page, project.slug, second);
        for (const id of [project.itemId, secondId]) {
            const response = await request(page, 'POST', '/my-day', { work_type: 'board', work_id: id });
            expect(response.status(), await response.text()).toBeLessThan(400);
        }

        await page.goto('/my-work');
        const myDay = page.getByRole('region', { name: 'My Day' });
        const rows = myDay.getByRole('listitem');
        const row = (title: string) => rows.filter({ hasText: title });
        const indexOf = async (title: string) => (await rows.allInnerTexts()).findIndex((text) => text.includes(title));
        await expect(row(first)).toBeVisible();
        await expect(row(second)).toBeVisible();
        expect(await indexOf(first)).toBeLessThan(await indexOf(second));

        const done = myDay.getByRole('checkbox', { name: `Mark “${first}” as done` });
        await expect(done).toHaveAttribute('aria-checked', 'false');
        await done.click();
        const reopen = myDay.getByRole('checkbox', { name: `Reopen “${first}”` });
        await expect(reopen).toHaveAttribute('aria-checked', 'true');
        await expect(row(first).getByRole('link', { name: first })).toHaveClass(/line-through/);
        await expect.poll(async () => (await indexOf(first)) > (await indexOf(second))).toBe(true); // done sinks to the bottom

        // it really is done: after a reload too
        await page.reload();
        await expect(myDay.getByRole('checkbox', { name: `Reopen “${first}”` })).toHaveAttribute('aria-checked', 'true');

        await myDay.getByRole('checkbox', { name: `Reopen “${first}”` }).click();
        await expect(myDay.getByRole('checkbox', { name: `Mark “${first}” as done` })).toHaveAttribute('aria-checked', 'false');
        await expect.poll(async () => (await indexOf(first)) < (await indexOf(second))).toBe(true);
    });
});
