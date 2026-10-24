import { SEED } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { card, createBoard, createCard, openCard } from '../helpers/boards';
import { addProjectContent, docEditor, draftSaved } from '../helpers/content';
import { isoDay } from '../helpers/leads-plans-search';
import { expectNotification } from '../helpers/notifications';

/**
 * Notification coverage (BRAI-103): comments reach the people involved in an issue, and a sprint
 * start reaches everyone with an issue in it. MCP / meeting-notes / due-date notifications have no
 * extra UI path and are covered by PHPUnit (tests/Feature/Notifications).
 */
test.describe('notification coverage', () => {
    test('a member comments on the owner’s issue and the owner (its watcher) is told', async ({ page, memberPage }) => {
        const title = uid('commented');
        const note = uid('looks-good');

        // Erin creates the issue: as its reporter she watches it
        await page.goto(urls.board());
        const created = await createCard(page, title);
        const key = (await created.getByText(/^[A-Z]+-\d+$/).textContent())?.trim();
        expect(key).toMatch(/^[A-Z]+-\d+$/);

        // Max comments on it
        await memberPage.goto(urls.board());
        const dialog = await openCard(memberPage, title);
        await dialog.locator('[contenteditable="true"][aria-placeholder="Add a comment…"]').click();
        await memberPage.keyboard.type(note);
        const posted = memberPage.waitForResponse((r) => r.request().method() === 'POST' && /\/items\/\d+\/comments/.test(r.url()));
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        expect((await posted).status()).toBeLessThan(400);
        await expect(dialog.getByText(note)).toBeVisible();

        // Erin's inbox has it: who, which issue, a preview and a link to the issue
        const entry = await expectNotification(page, (n) => n.type === 'item_commented' && n.subject_key === key, `item_commented on ${key}`);
        expect(entry.title).toBe(`Max Member commented on ${key}`);
        expect(entry.body).toContain(note);
        expect(entry.url).toMatch(/\/items\/\d+$/);
        expect(entry.project_name).toContain('Main Board');
        await page.goto('/notifications');
        await expect(page.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible();
        await expect(page.getByText(/Max Member/).first()).toBeVisible();

        // …and Max, the author, is not told about his own comment
        const own = await memberPage.request.get('/api/notifications');
        const mine = ((await own.json()).data as { data: { type: string; subject_key?: string } }[]).map((n) => n.data);
        expect(mine.some((n) => n.type === 'item_commented' && n.subject_key === key)).toBe(false);
    });

    test('starting a sprint tells the member who has an issue in it', async ({ page, memberPage }) => {
        const boardName = uid('notify-sprint');
        const issueTitle = uid('sprint-issue');
        const sprintName = uid('sprint');

        // a board with an issue assigned to Max…
        const boardUrl = await createBoard(page, boardName);
        const issue = await createCard(page, issueTitle);
        await issue.getByTitle('Unassigned — click to assign').click();
        await page.getByRole('button', { name: 'Max Member' }).click();
        await expect(card(page, issueTitle).getByTitle(/Assignee: Max Member/)).toBeVisible();

        // …that switches to sprints
        await page.goto(`${boardUrl}/edit`);
        await page.getByRole('checkbox', { name: /Use sprints \(Scrum\)/ }).check();
        const saved = page.waitForResponse((r) => new URL(r.url()).pathname === boardUrl && r.request().method() === 'PUT');
        await page.getByRole('button', { name: 'Save Changes' }).first().click();
        expect((await saved).status()).toBeLessThan(400);

        // plan the sprint with that issue and start it
        await page.goto(`${boardUrl}/backlog`);
        const issueRow = page.getByRole('listitem').filter({ hasText: issueTitle });
        await expect(issueRow).toBeVisible();
        await page.getByRole('button', { name: 'Create sprint' }).click();
        const sprint = page.locator('section').filter({ has: page.getByRole('button', { name: `${SEED.key} Sprint 1` }) });
        await expect(sprint).toBeVisible();
        const ranked = page.waitForResponse((r) => r.url().includes('/backlog/rank') && r.request().method() === 'POST');
        await issueRow.dragTo(sprint.getByText('Plan this sprint by dragging issues here from the backlog.'));
        await ranked;
        await expect(sprint.getByText('(1 issue)')).toBeVisible();

        await sprint.getByRole('button', { name: 'Start sprint' }).click();
        const start = page.getByRole('dialog', { name: 'Start sprint' });
        await start.getByLabel('Sprint name').fill(sprintName);
        await start.getByLabel('Start date').fill(isoDay(0));
        await start.getByLabel('End date').fill(isoDay(7));
        await start.getByRole('button', { name: 'Start', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${boardUrl}$`));

        // Max hears about it, with the counts and a link to the board
        const entry = await expectNotification(memberPage, (n) => n.type === 'sprint_started' && !!n.title?.includes(sprintName), `sprint_started ${sprintName}`);
        expect(entry.title).toBe(`Erin Owner started ${sprintName}`);
        expect(entry.body).toMatch(/^1 open · 0 done · ends /);
        expect(new URL(entry.url!).pathname).toBe(boardUrl);
        expect(entry.project_name).toContain(boardName);
    });

    test('an @mention on a page is notified when the page saves, once', async ({ page, memberPage }) => {
        const name = uid('Mention doc');
        await addProjectContent(page, 'Page', name);
        await docEditor(page).click();

        // Erin mentions Max; the autosave carries the mention
        let saved = draftSaved(page);
        await page.keyboard.type('Hello @Max');
        const picker = page.getByRole('listbox', { name: 'Mention someone' });
        await expect(picker.getByRole('option', { name: /Max Member/, selected: true })).toBeVisible();
        await page.keyboard.press('Enter');
        await expect(picker).toBeHidden();
        await page.keyboard.type(' please review');
        await saved;

        const isMention = (n: { type: string; context_type?: string; context_title?: string }) => n.type === 'mentioned' && n.context_type === 'page' && n.context_title === name;
        await expectNotification(memberPage, isMention, `page mention on ${name}`);

        // more typing (more autosaves) does not mention him again
        saved = draftSaved(page);
        await page.keyboard.type(' and thanks');
        await saved;
        const feed = await memberPage.request.get('/api/notifications');
        const mentions = ((await feed.json()).data as { data: { type: string; context_type?: string; context_title?: string } }[]).filter((n) => isMention(n.data));
        expect(mentions).toHaveLength(1);
    });
});
