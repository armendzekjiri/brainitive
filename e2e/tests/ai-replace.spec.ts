import type { Page, Route } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { card, closeIssue, createCard, openCard, openDeepLink, openIssueId, saveDescription } from '../helpers/boards';

/**
 * AI in the issue description (BRAI-30): "Generate prompt" may answer with long plain text or
 * Markdown instead of HTML. "Replace" must still put it into the description, and Save must keep it.
 * The workspace AI is faked (no provider in e2e): /ai/settings says configured, /ai/generate answers.
 */
async function fakeAi(page: Page, content: string) {
    await page.route(/\/ai\/settings$/, async (route: Route) => {
        if (route.request().method() !== 'GET') return route.continue();
        const response = await route.fetch();
        const json = await response.json().catch(() => ({}));
        await route.fulfill({ response, json: { ...json, ai_configured: true } });
    });
    await page.route(/\/ai\/generate$/, (route: Route) => route.fulfill({ json: { success: true, content } }));
}

test.describe('AI replace in the issue description', () => {
    // the app's service worker would see the requests first, and page.route never would
    test.use({ serviceWorkers: 'block' });

    test('a long plain-text prompt replaces the description and is saved', async ({ page }) => {
        const title = uid('ai prompt');
        const marker = uid('Prompt');
        const prompt = [
            `${marker}: You are a senior Laravel developer.`,
            '',
            'Goal: implement the checkout bug fix described below, step by step.',
            '- Reproduce the failing payment on Safari',
            '- Write a failing test first',
            '- Fix the session cookie and keep the change small',
            '',
            `${'Context: the customer pays and is sent back to an empty cart. '.repeat(12)}`,
            '',
            'Output: a short plan, then the patch, then the tests.',
        ].join('\n');
        await fakeAi(page, prompt);

        await page.goto(urls.board());
        await createCard(page, title);
        let dialog = await openCard(page, title);
        const id = await openIssueId(dialog);

        await dialog.getByText('Add a description…').dblclick();
        await dialog.getByTitle('AI Assistant').click();
        const ai = page.getByRole('dialog').filter({ has: page.getByRole('button', { name: 'Generate prompt' }) });
        await ai.getByRole('button', { name: 'Generate prompt' }).click();
        await page.getByRole('button', { name: 'Replace', exact: true }).click();

        const editor = dialog.locator('[contenteditable="true"][aria-placeholder="Add a description…"]');
        await expect(editor).toContainText(`${marker}: You are a senior Laravel developer.`);
        await expect(editor.getByText('Write a failing test first')).toBeVisible();

        await saveDescription(page, dialog);
        await expect(dialog.getByText(`${marker}: You are a senior Laravel developer.`)).toBeVisible();
        await closeIssue(page);
        await expect(card(page, title)).toBeVisible();

        // persisted: a fresh load shows the replaced description
        dialog = await openDeepLink(page, urls.board(), id);
        await expect(dialog.getByText(`${marker}: You are a senior Laravel developer.`)).toBeVisible();
        await expect(dialog.getByText('Output: a short plan, then the patch, then the tests.')).toBeVisible();
    });
});
