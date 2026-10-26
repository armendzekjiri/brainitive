import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createBoard, postJson } from '../helpers/boards';
import { pageProps } from '../helpers/helpdesk';

/**
 * BRAI-144: automation conditions are built as Field → Operator → Value rows with "All" / "Any".
 * A rule "Title contains <token> AND Labels has none of <label> AND Priority is any of High" runs for
 * the matching issue only; an "Any" rule runs when one condition holds, and the run history shows
 * which conditions passed and failed. Rules only match unique words / labels (parallel specs create
 * issues on the same project) and are deleted at the end.
 */
const automationsUrl = () => `${urls.project()}/automations`;

interface Setup {
    labelName: string;
    labelId: number;
    highId: number;
    boardUrl: string;
    columnId: number;
}

async function setup(page: Page): Promise<Setup> {
    const labelName = uid('cond-label');
    const { id: labelId } = await postJson<{ id: number }>(page, `${urls.project()}/labels`, { name: labelName, color: '#ef4444' });
    const boardUrl = await createBoard(page, uid('Cond board'));
    await page.goto(boardUrl);
    const { board } = await pageProps<{ board: { columns: { id: number }[] } }>(page);
    await page.goto(`${automationsUrl()}/create`);
    const { options } = await pageProps<{ options: { priorities: { id: number; name: string }[] } }>(page);
    const highId = options.priorities.find((p) => p.name === 'High')!.id;
    return { labelName, labelId, highId, boardUrl, columnId: board.columns[0].id };
}

/** Setup shortcut: what the board's "Create card" form posts (with priority and labels). */
async function createIssue(page: Page, s: Setup, title: string, extra: { priority_id?: number; label_ids?: number[] } = {}) {
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value);
    const response = await page.request.post(`${s.boardUrl}/items`, {
        headers: { 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html, application/xhtml+xml' },
        data: { title, column_id: s.columnId, ...extra },
        maxRedirects: 0,
    });
    expect(response.status(), `create ${title}`).toBe(302);
}

async function condition(page: Page, n: number, field: string, operator: string) {
    await page.getByRole('button', { name: 'Add condition' }).click();
    await page.getByLabel(`Condition ${n} field`).selectOption({ label: field });
    await page.getByLabel(`Condition ${n} operator`).selectOption({ label: operator });
}

async function addComment(page: Page, text: string) {
    await page.getByRole('button', { name: 'Add action' }).click();
    await page.getByLabel('Action 1', { exact: true }).selectOption({ label: 'Add a comment' });
    await page.getByLabel('Action 1 text').fill(text);
}

async function saveAndSwitchOn(page: Page, name: string) {
    await page.getByRole('button', { name: 'Save rule' }).click();
    await expect(page).toHaveURL(/\/automations\/\d+$/);
    const editUrl = page.url();
    await page.getByRole('link', { name: 'Automations', exact: true }).click();
    const toggle = page.getByRole('switch', { name: `${name} on` });
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-checked', 'true');
    return editUrl;
}

async function deleteRule(page: Page, editUrl: string) {
    await page.goto(editUrl);
    await page.getByRole('button', { name: 'Delete rule' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page).toHaveURL(/\/automations$/);
}

const runRow = (page: Page, title: string): Locator => page.getByRole('table', { name: 'Run history' }).getByRole('row').filter({ hasText: title });

/** Expand a run and return its conditions list. */
async function runConditions(page: Page, title: string): Promise<Locator> {
    await runRow(page, title).getByRole('button', { name: /^Details of run/ }).click();
    const list = page.getByRole('list', { name: /^Conditions of run/ });
    await expect(list).toBeVisible();
    return list;
}

test('all: labels has none of X and priority is any of High — only the matching issue runs', async ({ page }) => {
    const s = await setup(page);
    const token = uid('allcond');
    const ruleName = `All ${token}`;

    await page.getByLabel('Rule name').fill(ruleName);
    await page.getByLabel('When', { exact: true }).selectOption({ label: 'Issue created' });
    // the match toggle shows once there is a condition; "All" is the default
    await condition(page, 1, 'Title', 'contains');
    await page.getByLabel('Condition 1 value').fill(token);
    await expect(page.getByRole('group', { name: 'Match' }).getByRole('button', { name: 'All' })).toHaveAttribute('aria-pressed', 'true');

    await condition(page, 2, 'Priority', 'is any of');
    await page.getByLabel('Condition 2 value').selectOption({ label: 'High' });
    await expect(page.getByLabel('Condition 2 chosen values')).toContainText('High');

    await condition(page, 3, 'Labels', 'has none of');
    await page.getByLabel('Condition 3 value').selectOption({ label: s.labelName });
    // reorder: labels before priority
    await page.getByRole('button', { name: 'Move condition 3 up' }).click();
    await expect(page.getByLabel('Condition 2 field')).toHaveValue('labels');

    // a 4th condition, removed again; empty-type operators have no value control
    await condition(page, 4, 'Assignee', 'is empty');
    await expect(page.getByLabel('Condition 4 value')).toHaveCount(0);
    await page.getByRole('button', { name: 'Remove condition 4' }).click();
    await expect(page.getByLabel('Condition 4 field')).toHaveCount(0);

    await addComment(page, 'all matched');
    await expect(page.getByRole('note', { name: 'Rule in words' })).toContainText(
        `When Issue created, if Title contains "${token}" and Labels has none of ${s.labelName} and Priority is any of High, then Add a comment`,
    );

    const editUrl = await saveAndSwitchOn(page, ruleName);
    // the saved rule reads the same in the list
    await expect(page.getByText(`Labels has none of ${s.labelName}`).first()).toBeVisible();

    const matching = `${token} ok`;
    const labelled = `${token} labelled`;
    const low = `${token} low`;
    await createIssue(page, s, matching, { priority_id: s.highId });
    await createIssue(page, s, labelled, { priority_id: s.highId, label_ids: [s.labelId] });
    await createIssue(page, s, low);

    await page.goto(editUrl);
    await expect(runRow(page, matching)).toContainText('Success');
    await expect(runRow(page, labelled)).toHaveCount(0); // conditions that don't match leave no run
    await expect(runRow(page, low)).toHaveCount(0);
    const list = await runConditions(page, matching);
    await expect(list.getByRole('listitem')).toHaveCount(3);
    for (const label of [`Title contains "${token}"`, `Labels has none of ${s.labelName}`, 'Priority is any of High']) {
        await expect(list.getByRole('listitem').filter({ hasText: label })).toContainText('(passed)');
    }
    await expect(page.getByText('all must match')).toBeVisible();

    await deleteRule(page, editUrl);
});

test('any: one passing condition is enough, and the run history shows which failed', async ({ page }) => {
    const s = await setup(page);
    const token = uid('anycond');
    const ruleName = `Any ${token}`;

    await page.getByLabel('Rule name').fill(ruleName);
    await page.getByLabel('When', { exact: true }).selectOption({ label: 'Issue created' });
    await condition(page, 1, 'Labels', 'has any of');
    await page.getByLabel('Condition 1 value').selectOption({ label: s.labelName });
    await condition(page, 2, 'Title', 'starts with');
    await page.getByLabel('Condition 2 value').fill(token);
    await page.getByRole('group', { name: 'Match' }).getByRole('button', { name: 'Any' }).click();
    await addComment(page, 'any matched');
    await expect(page.getByRole('note', { name: 'Rule in words' })).toContainText(`if Labels has any of ${s.labelName} or Title starts with "${token}"`);

    // dry run on a seeded issue: neither holds
    await page.getByLabel('Issue key').fill('WEB-1');
    await page.getByRole('button', { name: 'Test on this issue' }).click();
    await expect(page.getByLabel('Test result')).toContainText('the conditions don’t match');
    await expect(page.getByLabel('Test result')).toContainText('Any condition may match.');

    const editUrl = await saveAndSwitchOn(page, ruleName);

    const byTitle = `${token} by title`;
    const byLabel = uid('by-label');
    const neither = uid('neither');
    await createIssue(page, s, byTitle);
    await createIssue(page, s, byLabel, { label_ids: [s.labelId] });
    await createIssue(page, s, neither);

    await page.goto(editUrl);
    await expect(runRow(page, byTitle)).toContainText('Success');
    await expect(runRow(page, byLabel)).toContainText('Success');
    await expect(runRow(page, neither)).toHaveCount(0);

    let list = await runConditions(page, byTitle);
    await expect(page.getByText('any must match')).toBeVisible();
    await expect(list.getByRole('listitem').filter({ hasText: `Labels has any of ${s.labelName}` })).toContainText('(failed)');
    await expect(list.getByRole('listitem').filter({ hasText: `Title starts with "${token}"` })).toContainText('(passed)');
    await runRow(page, byTitle).getByRole('button', { name: /^Details of run/ }).click(); // collapse

    list = await runConditions(page, byLabel);
    await expect(list.getByRole('listitem').filter({ hasText: `Labels has any of ${s.labelName}` })).toContainText('(passed)');
    await expect(list.getByRole('listitem').filter({ hasText: `Title starts with "${token}"` })).toContainText('(failed)');

    await deleteRule(page, editUrl);
});
