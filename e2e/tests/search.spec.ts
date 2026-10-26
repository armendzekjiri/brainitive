import { expect, test, urls } from '../fixtures';
import { addProjectContent } from '../helpers/content';
import { createIssue } from '../helpers/leads-plans-search';

/**
 * Find anything (BRAI-55): the search page with filters and type tabs, the command palette's
 * "See all results", typo tolerance, and drafts that stay private.
 */

// unique random words (no shared time prefix, which typo matching would treat as similar):
// parallel specs fill the same workspace
const word = () => Array.from({ length: 10 }, () => 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)]).join('');

test.describe('search', () => {
    test('the search page finds an issue, filters by status and type, and shows a no-results state', async ({ page }) => {
        const marker = word();
        const title = `Pelican ${marker}`;
        const key = await createIssue(page, title);

        await page.goto(`/search?q=${marker}`);
        const results = page.getByRole('listbox', { name: 'Search results' });
        const hit = results.getByRole('option').filter({ hasText: title });
        await expect(hit).toBeVisible();
        await expect(hit).toContainText(key);
        await expect(hit.locator('mark').first()).toHaveText(new RegExp(marker, 'i'));
        await expect(page.getByRole('button', { name: /^Issues\s*1$/ })).toBeVisible();

        // the issue is open: "Done" leaves nothing, with a way back
        await page.getByRole('button', { name: 'Status' }).click();
        await page.getByRole('menuitemcheckbox', { name: 'Done' }).click();
        await expect(page).toHaveURL(/status=done/);
        await expect(page.getByText(`Nothing found for “${marker}”`)).toBeVisible();
        await page.getByRole('button', { name: 'Search everything' }).click();
        await expect(page).not.toHaveURL(/status=/);
        await expect(hit).toBeVisible();

        // the Issues tab; then the keyboard opens the result
        await page.getByRole('button', { name: /^Issues\s*1$/ }).click();
        await expect(page).toHaveURL(/type=issues/);
        await page.getByRole('searchbox', { name: 'Search everything' }).focus();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(new RegExp(`${urls.board()}`));
        await expect(page.getByRole('dialog', { name: new RegExp(title) })).toBeVisible();
    });

    test('the command palette shows top results and "See all results" opens the search page', async ({ page }) => {
        const marker = word();
        await createIssue(page, `Heron ${marker}`);

        await page.goto(urls.home);
        await page.keyboard.press('Control+k');
        const input = page.getByRole('combobox', { name: 'Search or type a command' });
        await input.fill(marker);
        await expect(page.getByRole('option', { name: new RegExp(`Heron ${marker}`) })).toBeVisible();
        await page.getByRole('option', { name: /See all 1 result$/ }).click();

        await expect(page).toHaveURL(new RegExp(`/search\\?q=${marker}`));
        await expect(page.getByRole('searchbox', { name: 'Search everything' })).toHaveValue(marker);
        await expect(page.getByRole('listbox', { name: 'Search results' }).getByRole('option').filter({ hasText: `Heron ${marker}` })).toBeVisible();
    });

    test('a typo still finds the issue', async ({ page }) => {
        const marker = word();
        await createIssue(page, `Marmalade ${marker}`);

        await page.goto(`/search?q=${encodeURIComponent(`marmlade ${marker}`)}`);
        await expect(page.getByText(/No exact matches .* showing similar titles/)).toBeVisible();
        await expect(page.getByRole('listbox', { name: 'Search results' }).getByRole('option').filter({ hasText: `Marmalade ${marker}` })).toBeVisible();
    });

    test('a member doesn’t find the owner’s unpublished draft; the owner does', async ({ page, memberPage }) => {
        const marker = word();
        const draft = `Secret ${marker}`;
        await addProjectContent(page, 'Page', draft); // a draft: only its author and admins can open it
        await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}/);

        await page.goto(`/search?q=${marker}`);
        await expect(page.getByRole('listbox', { name: 'Search results' }).getByRole('option').filter({ hasText: draft })).toContainText('Draft');

        await memberPage.goto(`/search?q=${marker}`);
        await expect(memberPage.getByText(`Nothing found for “${marker}”`)).toBeVisible();
        await expect(memberPage.getByText(draft)).toHaveCount(0);

        // the palette doesn't leak it either
        const quick = await (await memberPage.request.get(`/search/quick?q=${marker}`)).json();
        expect(quick.total).toBe(0);
    });
});
