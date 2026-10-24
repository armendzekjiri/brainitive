import { expect, test, uid, urls } from '../fixtures';
import { USERS } from '../env';
import { createBlankProject } from '../helpers/workspaces-projects';
import { newIssue, openWeek, projectPath, request, weekCell, workloadUrl } from '../helpers/workload';

/**
 * Workload planning (BRAI-75): planners declare weekly capacity, put issues' hours into weeks
 * (the remaining estimate is only a suggestion) and see overload; members only look.
 * Each planning journey uses its own project, so parallel runs never share a grid.
 */
const owner = USERS.owner.name;

test.describe('workload planning', () => {
    test('a planner sets capacity, plans an issue from its remaining estimate and sees 5h over', async ({ page }) => {
        const project = await createBlankProject(page, 'e2e-workspace');
        const title = uid('Checkout redesign');
        await newIssue(page, project.slug, title, { estimate: '27h', logged: '2h' });

        // from the project sidebar
        await page.goto(urls.project(project.slug));
        await page.getByRole('link', { name: 'Workload', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${workloadUrl(project.slug)}$`));
        await expect(page.getByRole('heading', { level: 1, name: /Workload/ })).toBeVisible();
        await expect(page.getByTestId('workload-scope')).toHaveText('This project only');
        await expect(weekCell(page, owner)).toContainText('Capacity not set');

        // default capacity: 20h a week
        await page.getByRole('button', { name: new RegExp(`^Edit weekly capacity of ${owner}`) }).click();
        const capacity = page.getByRole('dialog', { name: `Weekly capacity · ${owner}` });
        await capacity.getByLabel('Hours per week').fill('20');
        await capacity.getByRole('button', { name: 'Save capacity' }).click();
        await expect(capacity).toBeHidden();
        await expect(page.getByRole('button', { name: new RegExp(`^Edit weekly capacity of ${owner}`) })).toContainText('20h / week');
        await expect(weekCell(page, owner)).toContainText('20h free');

        // plan the issue this week: the remaining estimate (27h − 2h logged) is offered, not applied
        const sheet = await openWeek(page, owner);
        await sheet.getByLabel('Search issues to plan').fill(title);
        await sheet.getByRole('button', { name: new RegExp(title) }).click();
        await expect(sheet.getByText('25h remaining (27h estimate − 2h logged)')).toBeVisible();
        await expect(sheet.getByLabel('Hours this week')).toHaveValue('');
        await sheet.getByRole('button', { name: 'Use remaining estimate (25h)' }).click();
        await expect(sheet.getByLabel('Hours this week')).toHaveValue('25');
        await sheet.getByRole('button', { name: 'Plan', exact: true }).click();
        await expect(sheet.getByRole('list', { name: 'Planned work' })).toContainText(title);

        // 20h capacity, 25h planned → 5h over capacity
        const summary = sheet.getByTestId('workload-week-summary');
        await expect(summary).toContainText('20h');
        await expect(summary).toContainText('25h');
        await expect(summary).toContainText('125%');
        await expect(sheet.getByText('5h over capacity')).toBeVisible();

        // trimming the plan in place
        const hours = sheet.getByLabel(/^Hours for /);
        await hours.fill('18');
        await hours.press('Enter');
        await expect(sheet.getByText('2h free')).toBeVisible();
        await hours.fill('25');
        await hours.press('Enter');
        await expect(sheet.getByText('5h over capacity')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(weekCell(page, owner)).toHaveAttribute('data-state', 'over');
        await expect(weekCell(page, owner)).toContainText('5h over capacity');

        // leave next week: 0h is "unavailable", not "not set"
        const next = await openWeek(page, owner, 1);
        await next.getByLabel('Hours', { exact: true }).fill('0');
        await next.getByLabel(/^Reason/).fill('Leave');
        await next.getByRole('button', { name: 'Set availability' }).click();
        await expect(next.getByText('Unavailable (0h)')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(weekCell(page, owner, 1)).toHaveAttribute('data-state', 'unavailable');
        await expect(weekCell(page, owner, 1)).toContainText('Leave');

        // the project total row adds it up
        await expect(page.getByRole('row', { name: /Project total/ })).toContainText('25h / 20h');
    });

    test('changed estimates are flagged for review, never applied to the plan', async ({ page }) => {
        const project = await createBlankProject(page, 'e2e-workspace');
        const title = uid('Pricing table');
        const id = await newIssue(page, project.slug, title, { estimate: '8h' });

        await page.goto(workloadUrl(project.slug));
        const sheet = await openWeek(page, owner);
        await sheet.getByLabel('Search issues to plan').fill(title);
        await sheet.getByRole('button', { name: new RegExp(title) }).click();
        await sheet.getByLabel('Hours this week').fill('6');
        await sheet.getByRole('button', { name: 'Plan', exact: true }).click();
        await expect(sheet.getByRole('list', { name: 'Planned work' })).toContainText(title);
        await page.keyboard.press('Escape');

        // the estimate grows on the board
        const update = await request(page, 'PATCH', `${projectPath(project.slug)}/boards/main-board/items/${id}`, { original_estimate: '12h' });
        expect(update.status()).toBeLessThan(400);

        await page.reload();
        const review = page.getByRole('region', { name: /Needs review/ });
        await expect(review).toContainText('Estimate changed from 8h to 12h since 6h were planned.');
        await expect(weekCell(page, owner)).toContainText('6h');
        await review.getByRole('button', { name: 'Keep 6h' }).click();
        await expect(review).toBeHidden();
        await expect(weekCell(page, owner)).toContainText('6h');
    });

    test('members see the workload but cannot change it', async ({ memberPage }) => {
        await memberPage.goto(workloadUrl());
        await expect(memberPage.getByRole('heading', { level: 1, name: /Workload/ })).toBeVisible();
        await expect(memberPage.getByTestId('workload-scope')).toHaveText('This project only');
        await expect(memberPage.getByText('Project admins plan capacity and hours; you can view them.')).toBeVisible();
        await expect(memberPage.getByRole('button', { name: /^Edit weekly capacity/ })).toHaveCount(0);

        const sheet = await openWeek(memberPage, USERS.member.name);
        await expect(sheet.getByRole('heading', { name: 'Planned work' })).toBeVisible();
        await expect(sheet.getByRole('button', { name: 'Set availability' })).toHaveCount(0);
        await expect(sheet.getByLabel('Search issues to plan')).toHaveCount(0);

        // and the server refuses it too
        const write = await request(memberPage, 'PUT', `${projectPath()}/workload/capacity`, { user_id: 1, weekly_minutes: 600 });
        expect(write.status()).toBe(403);
    });
});
