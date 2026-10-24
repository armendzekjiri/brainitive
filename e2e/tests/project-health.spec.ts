import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { send } from '../helpers/backlinks';
import { createBlankProject, createWorkspace } from '../helpers/workspaces-projects';

/**
 * Project health (BRAI-64): live metrics from the project's issues, the "AI not configured" state
 * (there is no AI provider in e2e), and saving / reading / deleting a status update with a
 * person-chosen health status.
 */

interface BoardColumn { id: number; name: string; is_done_column: boolean; items?: { id: number; title: string }[] }

/** The board page's Inertia props (columns with their issues). */
async function boardColumns(page: Page, boardUrl: string): Promise<BoardColumn[]> {
    await page.goto(boardUrl);
    const data = JSON.parse((await page.locator('#app').getAttribute('data-page')) ?? '{}');
    return data.props.board.columns as BoardColumn[];
}

/** Y-m-d in UTC, `days` from today. */
const utcDay = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);

const metric = (page: Page, label: string) => page.getByRole('group', { name: label, exact: true });
const value = (page: Page, label: string) => metric(page, label).getByTestId('metric-value');

test.describe('project health', () => {
    test('metrics match the project’s issues, AI is optional, and a status update is saved with its sources', async ({ page }) => {
        // an isolated project, so the numbers are only ours
        const workspace = await createWorkspace(page);
        const project = await createBlankProject(page, workspace.slug);
        const boardUrl = urls.board('main-board', project.slug, workspace.slug);
        const columns = await boardColumns(page, boardUrl);
        const todo = columns.find((c) => c.name === 'To Do')!;
        const done = columns.find((c) => c.is_done_column)!;

        const create = async (title: string, extra: Record<string, unknown> = {}) => {
            const response = await send(page, 'POST', `${boardUrl}/items`, { title, column_id: todo.id, ...extra });
            expect(response.status(), `create ${title}`).toBeLessThan(400);
        };
        await create('Late homepage', { due_date: utcDay(-1) });
        await create('Due today', { due_date: utcDay(0) });
        await create('Due in three days', { due_date: utcDay(3) });
        await create('No due date');
        await create('Shipped', { column_id: done.id });

        // "Late homepage" is blocked by "Due in three days"
        const items = (await boardColumns(page, boardUrl)).flatMap((c) => c.items ?? []);
        const late = items.find((i) => i.title === 'Late homepage')!;
        const blocker = items.find((i) => i.title === 'Due in three days')!;
        const linked = await send(page, 'POST', `${boardUrl}/items/${late.id}/links`, { target_item_id: blocker.id, link_type: 'is_blocked_by' });
        expect(linked.status()).toBeLessThan(400);

        // the project nav opens the health view
        await page.goto(boardUrl);
        await page.getByRole('link', { name: 'Health', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`/projects/${project.slug}/health`));
        await expect(page.getByRole('heading', { level: 1, name: 'Project health' })).toBeVisible();

        // pin the time zone so "today" matches the dates above
        await page.goto(`${urls.project(project.slug, workspace.slug)}/health?tz=UTC`);
        await expect(page.getByTestId('health-as-of')).toContainText('(UTC)');
        await expect(value(page, 'Open issues')).toHaveText('4');
        await expect(metric(page, 'Open issues').getByTestId('metric-note')).toHaveText('1 completed in total');
        await expect(value(page, 'Overdue')).toHaveText('1');
        await expect(value(page, 'Due in the next 7 days')).toHaveText('2');
        await expect(value(page, 'Unassigned open issues')).toHaveText('4');
        await expect(value(page, 'Blocked issues')).toHaveText('1');

        // the issues behind the numbers (due today is not overdue)
        await expect(page.getByRole('tabpanel').getByRole('link', { name: /Late homepage/ })).toBeVisible();
        await expect(page.getByRole('tabpanel').getByText('Due today')).toHaveCount(0);
        await page.getByRole('tab', { name: /^Blocked/ }).click();
        await expect(page.getByRole('tabpanel').getByText('blocked by')).toBeVisible();

        // no plans: milestones are "not available", never a healthy zero
        await expect(page.getByText(/Not available — This project has no plans/)).toBeVisible();

        // no AI provider in e2e: the metrics work, the draft button isn't offered
        await expect(page.getByTestId('ai-not-configured')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Draft with AI' })).toHaveCount(0);

        // the status is required
        await page.getByRole('button', { name: 'Save status update' }).click();
        await expect(page.getByText('Pick a health status.')).toBeVisible();

        // write the update: status, summary, a risk citing the overdue metric
        const summary = uid('summary');
        await page.getByRole('radio', { name: 'At risk' }).click();
        await page.getByLabel('Summary').fill(summary);
        const risks = page.getByRole('region', { name: 'Risks' });
        await risks.getByRole('button', { name: 'Add point' }).click();
        await risks.getByRole('textbox', { name: 'Risks point 1' }).fill('The homepage is late and blocked.');
        await risks.getByRole('button', { name: 'Cite source' }).click();
        await page.getByPlaceholder('Search metrics, issues, plans…').fill('Late homepage');
        await page.getByRole('option', { name: /Late homepage/ }).click();
        await expect(risks.getByRole('link', { name: /Late homepage/ })).toBeVisible();
        await page.getByRole('button', { name: 'Save status update' }).click();

        // saved: the update opens with its sources, and it's in the history
        const sheet = page.getByRole('dialog', { name: /Status update ·/ });
        await expect(sheet).toBeVisible();
        await expect(sheet.getByText('At risk')).toBeVisible();
        await expect(sheet.getByText(summary)).toBeVisible();
        await expect(sheet.getByText('The homepage is late and blocked.')).toBeVisible();
        await expect(sheet.getByRole('region', { name: 'Risks' }).getByRole('link', { name: /Late homepage/ })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();
        await expect(page.getByRole('list', { name: 'Saved status updates' }).getByText(summary)).toBeVisible();
    });

    test('a teammate reads a saved update; only the author or an admin deletes it', async ({ page, memberPage }) => {
        const health = `${urls.project()}/health`;
        const summary = uid('team-update');

        // the owner writes an update in the seeded project
        await page.goto(health);
        await page.getByRole('radio', { name: 'On track' }).click();
        await page.getByLabel('Summary').fill(summary);
        await page.getByRole('button', { name: 'Save status update' }).click();
        await expect(page.getByRole('dialog', { name: /Status update ·/ })).toBeVisible();

        // the member (project editor) sees it, without a delete button
        await memberPage.goto(health);
        const saved = memberPage.getByRole('list', { name: 'Saved status updates' }).getByRole('button', { name: new RegExp(summary) });
        await expect(saved).toBeVisible();
        await saved.click();
        const sheet = memberPage.getByRole('dialog', { name: /Status update ·/ });
        await expect(sheet.getByText(summary)).toBeVisible();
        await expect(sheet.getByText('On track')).toBeVisible();
        await expect(sheet.getByRole('button', { name: 'Delete' })).toHaveCount(0);

        // the owner deletes it
        await page.getByRole('button', { name: 'Delete' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
        await expect(page.getByRole('dialog', { name: /Status update ·/ })).toBeHidden();
        await expect(page.getByRole('list', { name: 'Saved status updates' }).getByText(summary)).toHaveCount(0);
    });
});
