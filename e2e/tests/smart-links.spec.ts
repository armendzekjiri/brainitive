import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { addProjectContent, docEditor, docReader, draftSaved, publish } from '../helpers/content';
import { closeIssue, createCard, openCard, openDeepLink, openIssueId } from '../helpers/boards';
import { backlinkList, pageUrl, relatedSection } from '../helpers/backlinks';

/**
 * Smart links (BRAI-143): paste the URL of an issue / doc into a document (or an issue
 * description) and it becomes a live chip; switch it to a card; insert links with "/link" or the
 * side "+" menu; the target lists the document under "Referenced by". Also the side "+" block
 * menu, which used to close before anything could be picked.
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

/** A new doc (an unpublished draft, open in the editor); returns its uuid. */
async function newDoc(page: Page, name: string): Promise<string> {
    await addProjectContent(page, 'Page', name);
    await expect(page).toHaveURL(/\/pages\/[0-9a-f-]{36}/);
    await expect(docEditor(page)).toBeVisible();
    return /\/pages\/([0-9a-f-]{36})/.exec(page.url())![1];
}

/** Paste plain text into a contenteditable, like Ctrl+V of a copied URL. */
async function pasteText(target: Locator, text: string) {
    await target.evaluate((el, value) => {
        const data = new DataTransfer();
        data.setData('text/plain', value);
        el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    }, text);
}

const chip = (scope: Page | Locator, ref: string) => scope.locator(`[data-smart-link-chip="${ref}"]`);

// they create issues in the seeded project: one after another (see boards.spec.ts)
test.describe('smart links', () => {
    test.describe.configure({ mode: 'default' });

    test('paste an issue URL into a doc: a live chip, a card, the new status after a reload, and a backlink', async ({ page }) => {
        const issue = uid('smart-issue');
        const doc = uid('smart-doc');
        const issueId = await newIssue(page, issue);
        const docUuid = await newDoc(page, doc);
        const issueUrl = new URL(`${urls.board()}#item-${issueId}`, page.url()).href;

        const editor = docEditor(page);
        await editor.click();
        await page.keyboard.type('Blocked by ');
        const saved = draftSaved(page);
        await pasteText(editor, issueUrl);

        const link = chip(editor, `item:${issueId}`);
        await expect(link).toHaveAttribute('data-smart-link-state', 'ok');
        await expect(link).toContainText(/WEB-\d+/);
        await expect(link).toContainText(issue);
        await expect(link).toContainText('To Do');
        await saved;

        // the hover toolbar switches it to a card
        await link.hover();
        const toolbar = page.getByRole('toolbar', { name: 'Smart link' });
        const cardSaved = draftSaved(page);
        await toolbar.getByRole('button', { name: 'Card' }).click();
        await expect(link).toHaveAttribute('data-smart-link-view', 'card');
        await expect(link).toContainText(`Issue in`);
        await cardSaved;
        await publish(page);
        await expect(chip(docReader(page), `item:${issueId}`)).toHaveAttribute('data-smart-link-view', 'card');

        // move the issue to In Progress on the board; it lists the doc under "Referenced by"
        const dialog = await openDeepLink(page, urls.board(), issueId);
        await expect(backlinkList(relatedSection(dialog)).getByRole('listitem').filter({ hasText: doc })).toContainText('Smart link');
        const status = dialog.getByRole('combobox', { name: 'Status' });
        const moved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+\/move$/.test(new URL(r.url()).pathname));
        await status.click();
        await page.getByRole('option', { name: 'In Progress' }).click();
        await moved;
        await closeIssue(page);

        await page.goto(pageUrl(docUuid));
        await expect(chip(docReader(page), `item:${issueId}`)).toContainText('In Progress');
    });

    test('"/link" inserts a doc link; the linked doc shows it under "Referenced by"; Undo to plain link keeps a URL plain', async ({ page }) => {
        const target = uid('smart-target');
        const source = uid('smart-source');
        const targetUuid = await newDoc(page, target);
        await newDoc(page, source);

        const editor = docEditor(page);
        await editor.click();
        await page.keyboard.type('Read ');
        await page.keyboard.type('/link');
        await page.keyboard.press('Enter');
        const picker = page.getByRole('dialog', { name: 'Link to issue, doc, ticket or plan' });
        await expect(picker).toBeVisible();
        await picker.getByRole('searchbox', { name: 'Search content to link' }).fill(target);
        const saved = draftSaved(page);
        await picker.getByRole('button', { name: new RegExp(target) }).click();
        await expect(picker).toBeHidden();
        const link = chip(editor, `page:${targetUuid}`);
        await expect(link).toContainText(target);
        await expect(link).toContainText('Draft');
        await saved;

        // a pasted URL can be turned back into a plain link
        await page.keyboard.type(' and ');
        const ownUrl = new URL(pageUrl(targetUuid), page.url()).href;
        await pasteText(editor, ownUrl);
        await expect(editor.locator('[data-smart-link-chip]')).toHaveCount(2);
        await page.getByRole('button', { name: 'Undo to plain link' }).click();
        await expect(editor.locator('[data-smart-link-chip]')).toHaveCount(1);
        await expect(editor.getByRole('link', { name: ownUrl })).toBeVisible();

        // a URL of another site stays plain text
        await page.keyboard.type(' see ');
        await pasteText(editor, 'https://example.com/workspaces/x/projects/y/pages/00000000-0000-0000-0000-000000000000');
        await expect(editor.locator('[data-smart-link-chip]')).toHaveCount(1);

        const backlinkSaved = draftSaved(page);
        await page.keyboard.type('.');
        await backlinkSaved;
        await page.goto(pageUrl(targetUuid));
        await expect(backlinkList(relatedSection(page)).getByRole('listitem').filter({ hasText: source })).toContainText('Smart link');
    });

    test('the side "+" menu opens, inserts a heading, a list and a "Link to…" item', async ({ page }) => {
        const issue = uid('plus-issue');
        await newIssue(page, issue);
        await newDoc(page, uid('plus-doc'));

        const editor = docEditor(page);
        await editor.click();
        const typed = draftSaved(page);
        await page.keyboard.type('First line');
        await typed;
        const firstLine = editor.locator('p', { hasText: 'First line' });

        /** Move the pointer like a person does: in small steps, through the gap between text and handle. */
        const glideTo = async (target: Locator) => {
            const box = (await target.boundingBox())!;
            await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 15 });
        };

        /** Hover the block, glide to its "+", open the menu, glide into it and pick an entry. */
        const addBelow = async (entry: string) => {
            const line = (await firstLine.boundingBox())!;
            await page.mouse.move(line.x + 30, line.y + line.height / 2);
            const plus = page.getByRole('button', { name: 'Add block below' });
            await expect(plus).toBeVisible();
            // the handles used to vanish on the way: the pointer left the editor before reaching them
            await glideTo(plus);
            await expect(plus).toBeVisible();
            await plus.click();
            const menu = page.getByRole('dialog', { name: 'Add block below' });
            await expect(menu).toBeVisible();
            const option = menu.getByRole('button', { name: entry });
            await glideTo(option);
            // the menu stays open while the pointer is in it
            await expect(menu).toBeVisible();
            await option.click();
            await expect(menu).toBeHidden();
        };

        await addBelow('Heading 2');
        await page.keyboard.type('Section title');
        await expect(editor.locator('h2', { hasText: 'Section title' })).toBeVisible();

        await addBelow('Bullet List');
        await page.keyboard.type('A bullet');
        await expect(editor.locator('ul li', { hasText: 'A bullet' })).toBeVisible();

        await addBelow('Link to issue, doc…');
        const picker = page.getByRole('dialog', { name: 'Link to issue, doc, ticket or plan' });
        await picker.getByRole('searchbox', { name: 'Search content to link' }).fill(issue);
        const saved = draftSaved(page);
        await picker.getByRole('button', { name: new RegExp(issue) }).click();
        await expect(editor.locator('[data-smart-link-chip^="item:"]')).toContainText(issue);
        await saved;

        // order: first line, then the link (inserted last, right below it), the list, the heading
        const blocks = await editor.locator(':scope > *').allInnerTexts();
        expect(blocks[0]).toContain('First line');
        expect(blocks[1]).toContain(issue);
    });

    test('a smart link in an issue description; a private draft shows as "Restricted" to others', async ({ page, memberPage }) => {
        const issue = uid('desc-issue');
        const secret = uid('secret-draft');
        const issueId = await newIssue(page, issue);
        const secretUuid = await newDoc(page, secret); // never published: the owner's only

        const dialog = await openDeepLink(page, urls.board(), issueId);
        await dialog.getByText('Add a description…').dblclick();
        const description = dialog.locator('.lexical-editor-content[contenteditable="true"]').first();
        await description.click();
        await page.keyboard.type('Spec: ');
        await pasteText(description, new URL(pageUrl(secretUuid), page.url()).href);
        await expect(chip(description, `page:${secretUuid}`)).toContainText(secret);
        const savedDescription = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+$/.test(new URL(r.url()).pathname));
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        await savedDescription;
        await expect(chip(dialog, `page:${secretUuid}`)).toContainText(secret);
        await closeIssue(page);

        // the draft lists the issue under "Referenced by" for its author
        await page.goto(pageUrl(secretUuid));
        await expect(backlinkList(relatedSection(page)).getByRole('listitem').filter({ hasText: issue })).toBeVisible();

        // the member sees the issue, but the draft only as "Restricted", without its title
        const memberDialog = await openDeepLink(memberPage, urls.board(), issueId);
        const restricted = chip(memberDialog, `page:${secretUuid}`);
        await expect(restricted).toHaveAttribute('data-smart-link-state', 'restricted');
        await expect(restricted).toHaveText('Restricted');
    });
});
