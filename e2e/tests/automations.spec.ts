import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { createCard } from '../helpers/boards';

/**
 * BRAI-63 workflow automation: a project admin builds a rule in the WHEN / IF / THEN form, tests it,
 * switches it on, and a new issue is assigned and prioritised by it; the run log shows the run.
 * Parallel specs create issues on the seeded Main Board too, so the rule also requires a unique
 * word in the title, and it is deleted at the end.
 */
const automationsUrl = () => `${urls.project()}/automations`;

async function buildRule(page: Page, name: string, token: string) {
    await page.goto(urls.project());
    await page.getByRole('link', { name: 'Open automations' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Automations' })).toBeVisible();
    await page.getByRole('link', { name: 'New rule' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'New rule' })).toBeVisible();

    await page.getByLabel('Rule name').fill(name);
    await page.getByLabel('When', { exact: true }).selectOption({ label: 'Issue created' });

    await page.getByRole('button', { name: 'Add condition' }).click();
    await page.getByLabel('Condition 1', { exact: true }).selectOption({ label: 'Board is' });
    await page.getByLabel('Condition 1 value').selectOption({ label: 'Main Board' });
    await page.getByRole('button', { name: 'Add condition' }).click();
    await page.getByLabel('Condition 2', { exact: true }).selectOption({ label: 'Title contains' });
    await page.getByLabel('Condition 2 value').fill(token);

    await page.getByRole('button', { name: 'Add action' }).click();
    await page.getByLabel('Action 1', { exact: true }).selectOption({ label: 'Assign to' });
    await page.getByLabel('Action 1 value').selectOption({ label: 'Max Member' });
    await page.getByRole('button', { name: 'Add action' }).click();
    await page.getByLabel('Action 2', { exact: true }).selectOption({ label: 'Set priority' });
    await page.getByLabel('Action 2 value').selectOption({ label: 'High' });
}

test('issue created on Main Board → assigned to Max with priority High, and the run is logged', async ({ page }) => {
    const token = uid('auto');
    const ruleName = `Triage ${token}`;
    const title = `Automated ${token}`;

    await buildRule(page, ruleName, token);

    // dry run on a seeded issue: its title lacks the word, so nothing would happen (and nothing does)
    await page.getByLabel('Issue key').fill('WEB-1');
    await page.getByRole('button', { name: 'Test on this issue' }).click();
    await expect(page.getByLabel('Test result')).toContainText('the conditions don’t match');

    await page.getByRole('button', { name: 'Save rule' }).click();
    await expect(page).toHaveURL(/\/automations\/\d+$/);
    await expect(page.getByText('Rule saved. It is switched off until you turn it on.').first()).toBeVisible();

    // rules start switched off: turn it on in the list
    await page.getByRole('link', { name: 'Automations', exact: true }).click();
    const toggle = page.getByRole('switch', { name: `${ruleName} on` });
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await toggle.click();
    await expect(page.getByText(`"${ruleName}" is on.`).first()).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-checked', 'true');

    // a new issue on the board: the rule assigns and prioritises it
    await page.goto(urls.board());
    await createCard(page, title);
    await page.goto(`${urls.board()}?view=list`);
    const row = page.getByRole('row').filter({ hasText: title });
    await expect(row).toContainText('Max Member');
    await expect(row).toContainText('High');

    // the run log
    await page.goto(automationsUrl());
    const run = page.getByRole('table', { name: 'Run history' }).getByRole('row').filter({ hasText: title });
    await expect(run).toContainText('Success');
    await expect(run).toContainText(ruleName);
    await run.getByRole('button', { name: /^Details of run/ }).click();
    await expect(page.getByText('Assigned to Max Member.')).toBeVisible();
    await expect(page.getByText('Priority set to High.')).toBeVisible();

    // clean up: delete the rule
    await page.getByRole('link', { name: ruleName }).click();
    await page.getByRole('button', { name: 'Delete rule' }).click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page).toHaveURL(/\/automations$/);
    await expect(page.getByRole('switch', { name: `${ruleName} on` })).toHaveCount(0);
});

test('validation errors show in the builder, and members cannot open automations', async ({ page, memberPage }) => {
    await page.goto(`${automationsUrl()}/create`);
    await page.getByLabel('Rule name').fill(uid('Empty rule'));
    await page.getByRole('button', { name: 'Save rule' }).click();
    await expect(page.getByText('Add at least one action.')).toBeVisible();

    await page.getByRole('button', { name: 'Add action' }).click();
    await page.getByLabel('Action 1', { exact: true }).selectOption({ label: 'Add a comment' });
    await page.getByRole('button', { name: 'Save rule' }).click();
    await expect(page.getByText('Write the text.')).toBeVisible();
    await expect(page).toHaveURL(/\/automations\/create$/);

    // Max is a project editor, not an admin: no Tools entry, and the page is refused
    await memberPage.goto(urls.project());
    await expect(memberPage.getByRole('heading', { name: 'Tools' })).toBeVisible();
    await expect(memberPage.getByRole('link', { name: 'Open automations' })).toHaveCount(0);
    const response = await memberPage.goto(automationsUrl());
    expect(response?.status()).toBe(403);
});
