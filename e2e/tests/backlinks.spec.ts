import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { addProjectContent } from '../helpers/content';
import { closeIssue, createCard, openCard, openDeepLink, openIssueId } from '../helpers/boards';
import {
    backlinkList, createPlan, findCandidate, linkContent, linkWithPicker, pageUrl, referencesOf, relatedList, relatedSection,
    savePlanRow, saveWhiteboardCards,
} from '../helpers/backlinks';

/**
 * Linked content and backlinks (BRAI-71): issues, docs, whiteboards and plans of a project link to
 * each other; the other side shows the link under "Referenced by". Plan rows and whiteboard cards
 * linked to an issue show up once as derived links. Private drafts never leak.
 */

/** A new issue on the seeded board; returns its id. */
async function newIssue(page: Page, title: string): Promise<number> {
    await page.goto(urls.board());
    await createCard(page, title);
    const dialog = await openCard(page, title);
    const id = await openIssueId(dialog);
    await closeIssue(page);
    return id;
}

/** A new doc (unpublished draft, the owner is its author); returns its uuid. */
async function newDoc(page: Page, name: string): Promise<string> {
    await addProjectContent(page, 'Page', name);
    await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}/);
    return /\/pages\/([0-9a-f-]{36})/.exec(page.url())![1];
}

// they create issues in the seeded project: one after another (see boards.spec.ts)
test.describe('linked content and backlinks', () => {
    test.describe.configure({ mode: 'default' });

    test('link an issue to a doc: the doc shows it under "Referenced by", and linking twice doesn’t duplicate', async ({ page }) => {
        const issue = uid('issue');
        const doc = uid('doc');
        const issueId = await newIssue(page, issue);
        const docUuid = await newDoc(page, doc);

        // link from the issue dialog's picker
        const dialog = await openDeepLink(page, urls.board(), issueId);
        const section = relatedSection(dialog);
        await expect(section.getByText('Nothing linked yet.')).toBeVisible();
        await linkWithPicker(page, section, doc);
        await expect(relatedList(section).getByRole('link', { name: new RegExp(doc) })).toHaveCount(1);

        // the doc shows the backlink, with a link that opens the issue
        await page.goto(pageUrl(docUuid));
        const docSection = relatedSection(page);
        const backlink = backlinkList(docSection).getByRole('link', { name: new RegExp(issue) });
        await expect(backlink).toHaveCount(1);
        await expect(backlink).toHaveAttribute('href', new RegExp(`/boards/${urls.board().split('/').pop()}#item-${issueId}$`));

        // the same link again (picker shows "Linked"; the API answers with the existing link)
        const target = await findCandidate(page, { type: 'item', id: issueId }, doc);
        const again = await linkContent(page, { type: 'item', id: issueId }, target);
        expect(again.created).toBe(false);
        expect((await referencesOf(page, 'item', issueId)).related).toHaveLength(1);
        await page.reload();
        await expect(backlinkList(relatedSection(page)).getByRole('listitem')).toHaveCount(1);

        // following the backlink opens the issue
        await backlinkList(relatedSection(page)).getByRole('link', { name: new RegExp(issue) }).click();
        await expect(page.getByRole('dialog', { name: new RegExp(`^Edit [A-Z]+-\\d+: ${issue}`) })).toBeVisible();
    });

    test('removing a link updates both sides', async ({ page }) => {
        const issue = uid('issue');
        const doc = uid('doc');
        const issueId = await newIssue(page, issue);
        const docUuid = await newDoc(page, doc);
        const target = await findCandidate(page, { type: 'item', id: issueId }, doc);
        await linkContent(page, { type: 'item', id: issueId }, target);

        await page.goto(pageUrl(docUuid));
        await expect(backlinkList(relatedSection(page)).getByRole('link', { name: new RegExp(issue) })).toBeVisible();

        // remove it from the issue (the source)
        const dialog = await openDeepLink(page, urls.board(), issueId);
        const section = relatedSection(dialog);
        const removed = page.waitForResponse((r) => r.request().method() === 'DELETE' && /\/references\/\d+$/.test(new URL(r.url()).pathname));
        await section.getByRole('button', { name: `Remove link to ${doc}` }).click();
        expect((await removed).ok()).toBeTruthy();
        await expect(section.getByText('Nothing linked yet.')).toBeVisible();

        // the doc no longer lists it
        await page.goto(pageUrl(docUuid));
        await expect(relatedSection(page).getByText('Nothing links here yet.')).toBeVisible();
        await expect(relatedSection(page).getByRole('link', { name: new RegExp(issue) })).toHaveCount(0);
    });

    test('a plan row and a whiteboard card linked to an issue show up once, as derived links', async ({ page }) => {
        const issue = uid('issue');
        const planName = uid('plan');
        const boardName = uid('whiteboard');
        const issueId = await newIssue(page, issue);

        const plan = await createPlan(page, planName);
        await savePlanRow(page, plan.url, issueId);
        // a manual link between the same two: still one entry
        await linkContent(page, { type: 'plan', id: (await findCandidate(page, { type: 'item', id: issueId }, planName)).id }, { type: 'item', id: issueId });

        await addProjectContent(page, 'Whiteboard', boardName);
        await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}/);
        const whiteboardUuid = /\/pages\/([0-9a-f-]{36})/.exec(page.url())![1];
        // leave the editor before writing the scene behind its back
        await page.goto('about:blank');
        // two cards of the same issue on the board: one backlink
        await saveWhiteboardCards(page, whiteboardUuid, [issueId, issueId]);

        const dialog = await openDeepLink(page, urls.board(), issueId);
        const backlinks = backlinkList(relatedSection(dialog));
        await expect(backlinks.getByRole('listitem')).toHaveCount(2);
        await expect(backlinks.getByRole('listitem').filter({ hasText: planName })).toContainText('Plan row');
        await expect(backlinks.getByRole('listitem').filter({ hasText: boardName })).toContainText('Whiteboard card');
        // the derived-only whiteboard link can't be removed here
        await expect(backlinks.getByRole('button', { name: `Remove link to ${boardName}` })).toHaveCount(0);
        await closeIssue(page);

        // the plan's "Related" panel lists the issue once
        await page.goto(plan.url);
        await page.getByRole('button', { name: 'Related content' }).click();
        const sheet = page.getByRole('dialog', { name: planName });
        await expect(relatedList(relatedSection(sheet)).getByRole('listitem')).toHaveCount(1);
        await expect(relatedList(relatedSection(sheet))).toContainText(issue);
        await page.keyboard.press('Escape');

        // unlinking the row removes the derived link; the whiteboard one stays
        await page.goto('about:blank');
        await savePlanRow(page, plan.url, null);
        const after = await referencesOf(page, 'item', issueId);
        expect(after.referenced_by.map((e) => [e.title, e.manual, e.derived])).toEqual(
            expect.arrayContaining([[planName, true, false], [boardName, false, true]]),
        );
        await saveWhiteboardCards(page, whiteboardUuid, []);
        expect((await referencesOf(page, 'item', issueId)).referenced_by.map((e) => e.title)).toEqual([planName]);
    });

    test('the member doesn’t see the owner’s private draft: no title, no count, no search hit', async ({ page, memberPage }) => {
        const issue = uid('issue');
        const draft = uid('secret-draft');
        const issueId = await newIssue(page, issue);
        await newDoc(page, draft); // never published: only the author and admins can open it

        const dialog = await openDeepLink(page, urls.board(), issueId);
        await linkWithPicker(page, relatedSection(dialog), draft);
        await expect(relatedSection(dialog).getByRole('heading', { name: /Related content \(1\)/ })).toBeVisible();

        // Max (project editor) opens the same issue
        const memberDialog = await openDeepLink(memberPage, urls.board(), issueId);
        const section = relatedSection(memberDialog);
        await expect(section.getByText('Nothing linked yet.')).toBeVisible();
        await expect(section.getByRole('heading', { name: 'Related content', exact: true })).toBeVisible();
        await expect(memberDialog.getByText(draft)).toHaveCount(0);

        // and the picker doesn't find it
        await section.getByRole('button', { name: 'Link related content' }).click();
        const picker = memberPage.getByRole('dialog', { name: 'Link related content' });
        await picker.getByLabel('Search content').fill(draft);
        await expect(picker.getByText('Nothing found.')).toBeVisible();
        await expect(picker.getByText(draft)).toHaveCount(0);

        const json = await referencesOf(memberPage, 'item', issueId);
        expect(json.related).toEqual([]);
        expect(JSON.stringify(json)).not.toContain(draft);
    });
});
