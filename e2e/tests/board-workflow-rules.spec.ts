import type { Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { card, column, createBoard, createCard, issueDialog, openCard } from '../helpers/boards';

/**
 * Workflow rules for boards (v1.52.0): the owner sets "Done only from Testing, only Erin Owner" in the board
 * settings; Max (project editor) can't drag a card to Done or pick Done in the issue dialog, and the owner's card
 * reaches Done only through Testing. Each run works on its own board.
 */

const workflowWrite = (page: Page) =>
    page.waitForResponse((r) => r.request().method() === 'PUT' && /\/columns\/\d+\/rule$/.test(new URL(r.url()).pathname));

test('a rule on Done: only from Testing, only the owner — enforced on drag and in the status select', async ({ page, memberPage }) => {
    const boardName = uid('Rules board');
    const [mine, maxCard] = [uid('owner card'), uid('max card')];
    const boardUrl = await createBoard(page, boardName);
    await createCard(page, mine);
    await createCard(page, maxCard);

    // Columns & statuses: add a Testing column
    await page.goto(`${boardUrl}/edit?tab=columns`);
    await page.getByLabel('New column name').fill('Testing');
    const added = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith('/columns'));
    await page.getByRole('button', { name: 'Add column' }).click();
    await added;
    await expect(page.getByRole('listitem', { name: 'Column Testing' })).toBeVisible();

    // Workflow rules: Done only from Testing, only Erin Owner
    await page.getByRole('tab', { name: 'Workflow rules' }).click();
    await expect(page.getByText('No rules yet')).toBeVisible();
    const doneRule = page.getByRole('group', { name: 'Rule for Done' });
    await doneRule.getByRole('button', { name: 'Add a rule for Done' }).click();
    await doneRule.getByRole('button', { name: 'Allowed from for Done' }).click();
    await page.getByRole('menuitemcheckbox', { name: 'Testing' }).click();
    await page.keyboard.press('Escape');
    await doneRule.getByRole('button', { name: 'Who may move cards to Done' }).click();
    await page.getByRole('menuitemcheckbox', { name: /^Erin Owner/ }).click();
    await page.keyboard.press('Escape');
    await expect(doneRule.getByText('Cards can enter Done only from Testing.')).toBeVisible();
    await expect(doneRule.getByText("New cards can't be created directly in Done.")).toBeVisible();
    const saved = workflowWrite(page);
    await doneRule.getByRole('button', { name: 'Save rule for Done' }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(page.getByRole('list', { name: 'Rules on this board' }))
        .toContainText('Cards can enter Done only from Testing. Only Erin Owner can move cards here.');

    // Max (project editor) sees the rule but can't change it
    await memberPage.goto(`${boardUrl}/edit?tab=workflow`);
    await expect(memberPage.getByRole('list', { name: 'Rules on this board' })).toContainText('Only Erin Owner can move cards here.');
    await expect(memberPage.getByText('Only project admins can change workflow rules.')).toBeVisible();
    await expect(memberPage.getByRole('button', { name: /^Save rule for/ })).toHaveCount(0);
    await expect(memberPage.getByRole('list', { name: 'Rule changes' })).toContainText('added a rule to Done');

    // Max can't drag his card to Done: the board says why and the card stays
    await memberPage.goto(boardUrl);
    await expect(column(memberPage, 'Done').getByRole('img', { name: /^Workflow rule:/ })).toBeVisible();
    await card(memberPage, maxCard, 'To Do').dragTo(column(memberPage, 'Done'));
    await expect(memberPage.getByText('Only Erin Owner can move cards to Done.').first()).toBeVisible();
    await expect(card(memberPage, maxCard, 'To Do')).toBeVisible();
    await expect(card(memberPage, maxCard, 'Done')).toHaveCount(0);

    // …nor pick Done in the issue dialog's status select (shown disabled, with the reason)
    const dialog = await openCard(memberPage, maxCard);
    await dialog.getByRole('combobox', { name: 'Status' }).first().click();
    const doneOption = memberPage.getByRole('option', { name: /^Done/ });
    await expect(doneOption).toBeDisabled();
    await expect(doneOption).toContainText('Only Erin Owner can move cards to Done.');
    await memberPage.keyboard.press('Escape');
    await expect(issueDialog(memberPage)).toBeVisible();

    // the owner: not straight from To Do…
    await page.goto(boardUrl);
    await card(page, mine, 'To Do').dragTo(column(page, 'Done'));
    await expect(page.getByText('Cards can only move to Done from Testing.').first()).toBeVisible();
    await expect(card(page, mine, 'To Do')).toBeVisible();

    // …but through Testing it works
    let moved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+\/move$/.test(new URL(r.url()).pathname));
    await card(page, mine, 'To Do').dragTo(column(page, 'Testing'));
    await moved;
    await expect(card(page, mine, 'Testing')).toBeVisible();
    moved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+\/move$/.test(new URL(r.url()).pathname));
    await card(page, mine, 'Testing').dragTo(column(page, 'Done'));
    await moved;
    await expect(card(page, mine, 'Done')).toBeVisible();
});
