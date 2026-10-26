import type { Locator, Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { addProjectContent, docEditor, docReader, draftSaved, publish } from '../helpers/content';
import { expectNotification } from '../helpers/notifications';

/**
 * Internal document review (BRAI-80): the author asks a project member to review a draft; the member
 * comments on a selection and requests changes; the author edits (the review goes stale), asks again,
 * gets an approval and publishes. With "Review required before publish", publishing without an
 * approval is refused by the server.
 */

/** Select `text` inside `container` (the first text node that contains it), like a mouse drag would. */
async function selectText(container: Locator, text: string) {
    await container.evaluate((root, needle) => {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const at = (node as Text).data.indexOf(needle);
            if (at >= 0) {
                const range = document.createRange();
                range.setStart(node, at);
                range.setEnd(node, at + needle.length);
                const selection = window.getSelection()!;
                selection.removeAllRanges();
                selection.addRange(range);
                return;
            }
        }
        throw new Error(`"${needle}" not found`);
    }, text);
}

async function typeDraft(page: Page, text: string, replace = false) {
    await docEditor(page).click();
    if (replace) await page.keyboard.press('ControlOrMeta+a');
    const saved = draftSaved(page);
    await page.keyboard.type(text);
    await saved;
}

async function moreActions(page: Page, item: string) {
    await page.getByRole('button', { name: 'More actions' }).click();
    await page.getByRole('menuitem', { name: item }).click();
}

/** The admin's "Review required before publish" switch (a checkbox item in the page menu). */
async function requireReview(page: Page) {
    await page.getByRole('button', { name: 'More actions' }).click();
    const item = page.getByRole('menuitemcheckbox', { name: 'Review required before publish' });
    await expect(item).toHaveAttribute('aria-checked', 'false');
    await item.click();
}

const banner = (page: Page) => page.getByTestId('page-review-banner');

test.describe('Document review', () => {
    test('a member reviews a draft, the author revises it and publishes once a new review is approved', async ({ page, memberPage }) => {
        const name = uid('Launch plan');
        await addProjectContent(page, 'Page', name);
        await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}$/);
        const pageUrl = page.url();
        await typeDraft(page, 'We launch the beta on Friday for all customers.');

        // the owner (project admin) makes the review mandatory: Publish waits for an approval
        await requireReview(page);
        await expect(page.getByText('This page needs an approved review before it can be published.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Publish', exact: true })).toBeDisabled();

        // request a review from the member
        await moreActions(page, 'Request review…');
        const dialog = page.getByRole('dialog', { name: 'Request review' });
        await dialog.getByRole('checkbox', { name: /Max Member/ }).click();
        await dialog.getByLabel('Message (optional)').fill('Is the date right?');
        await dialog.getByRole('button', { name: 'Request review', exact: true }).click();
        await expect(page).toHaveURL(/\/reviews\/[0-9a-f-]{36}$/);
        const firstReviewUrl = page.url();

        // the member is told, can't open the unpublished page, but sees the snapshot through the review
        const requested = await expectNotification(memberPage, (n) => n.type === 'review_requested' && (n.title ?? '').includes(name), 'review requested');
        expect(requested.url).toContain('/reviews/');
        expect((await memberPage.goto(pageUrl))?.status()).toBe(403);
        await memberPage.goto(firstReviewUrl);
        const snapshot = memberPage.getByTestId('review-snapshot');
        await expect(snapshot).toContainText('We launch the beta on Friday');

        // comment on a selection
        await selectText(snapshot, 'on Friday');
        await memberPage.getByRole('button', { name: 'Comment', exact: true }).click();
        await memberPage.getByLabel('Comment on the selection').fill('Which Friday exactly?');
        await memberPage.getByRole('button', { name: 'Comment', exact: true }).click();
        await expect(snapshot.locator('mark[data-thread]')).toHaveText('on Friday');
        await expect(memberPage.getByRole('article', { name: 'Comment on “on Friday”' })).toContainText('Which Friday exactly?');

        // and request changes
        await memberPage.getByLabel('Decision comment').fill('Please pin the date.');
        await memberPage.getByRole('button', { name: 'Request changes' }).click();
        await expect(memberPage.getByRole('list', { name: 'Reviewers' })).toContainText('Changes requested');

        // the author hears about both and sees it on the page
        await expectNotification(page, (n) => n.type === 'review_commented' && (n.title ?? '').includes(name), 'review comment');
        await expectNotification(page, (n) => n.type === 'review_decided' && (n.title ?? '').includes('requested changes'), 'changes requested');
        await page.goto(pageUrl);
        await expect(banner(page)).toContainText('requested changes');

        // editing the draft makes the review stale
        await typeDraft(page, 'We launch the beta on 14 November for all customers.', true);
        await expect(banner(page)).toContainText('The draft changed after review #1');

        // ask again (the same reviewer is preselected)
        await banner(page).getByRole('button', { name: 'Request new review' }).click();
        const again = page.getByRole('dialog', { name: 'Request review' });
        await expect(again.getByRole('checkbox', { name: /Max Member/ })).toHaveAttribute('aria-checked', 'true');
        await again.getByRole('button', { name: 'Request review', exact: true }).click();
        await expect(page).toHaveURL(/\/reviews\/[0-9a-f-]{36}$/);
        const secondReviewUrl = page.url();
        expect(secondReviewUrl).not.toBe(firstReviewUrl);

        // the member sees the new text; the old thread is carried over as outdated (never re-attached)
        await memberPage.goto(secondReviewUrl);
        await expect(memberPage.getByTestId('review-snapshot')).toContainText('on 14 November');
        await expect(memberPage.getByText('Outdated', { exact: true })).toBeVisible();
        await expect(memberPage.getByTestId('review-snapshot').locator('mark[data-thread]')).toHaveCount(0);
        await memberPage.getByRole('button', { name: 'Approve' }).click();
        await expect(memberPage.getByRole('list', { name: 'Reviewers' })).toContainText('Approved');

        // the approved draft can be published
        await page.goto(pageUrl);
        await expect(banner(page)).toContainText('approved by Max Member');
        await publish(page);
        await expect(docReader(page)).toContainText('on 14 November');

        // the first review is closed (replaced by the second)
        await page.goto(firstReviewUrl);
        await expect(page.getByText('A newer review replaced this one.')).toBeVisible();
    });

    test('with review required, publishing without an approval is refused by the server', async ({ page }) => {
        await addProjectContent(page, 'Page', uid('Policy'));
        const pageUrl = page.url();
        await typeDraft(page, 'Unreviewed words.');

        await requireReview(page);
        await expect(page.getByRole('button', { name: 'Publish', exact: true })).toBeDisabled();

        // going around the button doesn't help
        const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value);
        const response = await page.request.post(`${pageUrl}/publish`, {
            headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
        });
        expect(response.status()).toBe(422);
        expect((await response.json()).errors.review[0]).toContain('approved review');

        await page.reload();
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Publish', exact: true })).toBeDisabled();
    });
});
