import type { Page, Route } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { card } from '../helpers/boards';
import { addProjectContent, docEditor, draftSaved } from '../helpers/content';

/**
 * Meeting notes → issues (BRAI-62).
 *
 * There is no AI provider in e2e (nor on dev.brainitive.app). The extraction request goes from the
 * browser to our server, so the journeys fake it with `page.route`: the options response is
 * patched to `ai_configured: true` and `…/meeting-notes/extract` answers with fixed proposals. No
 * stub exists on the server. Everything after the review — Create, permissions, idempotency,
 * the cards on the board — is the real app.
 */

type Member = { id: number; name: string };
type Proposal = Record<string, unknown>;

const dialog = (page: Page) => page.getByRole('dialog', { name: 'Extract action items' });
const suggestion = (page: Page, n: number) => dialog(page).getByRole('group', { name: `Suggestion ${n}` });

/** Pretend the workspace has AI and answer extraction with `proposals(members)`. */
async function fakeAi(page: Page, proposals: (members: Member[]) => Proposal[]) {
    let members: Member[] = [];
    await page.route(/\/meeting-notes\/options(\?|$)/, async (route: Route) => {
        const response = await route.fetch();
        const json = await response.json();
        members = json.members;
        await route.fulfill({ response, json: { ...json, ai_configured: true } });
    });
    const requests: Record<string, unknown>[] = [];
    await page.route(/\/meeting-notes\/extract$/, async (route: Route) => {
        const body = route.request().postDataJSON();
        requests.push(body);
        await route.fulfill({
            json: { proposals: proposals(members), dropped: 1, truncated: false, board: { id: body.board_id, name: 'Main Board' }, source: null },
        });
    });
    return requests;
}

const proposal = (n: number, title: string, excerpt: string, extra: Proposal = {}): Proposal => ({
    temp_id: `p${n}`, title, description: '', excerpt, assignee: null, due: null, duplicate_of: null, existing: null, selected: true, ...extra,
});

async function openFromProject(page: Page) {
    await page.goto(urls.project());
    await page.getByRole('button', { name: 'Extract action items' }).click();
    await expect(dialog(page)).toBeVisible();
}

async function pickMainBoard(page: Page) {
    await dialog(page).getByRole('combobox', { name: 'Destination board' }).click();
    await page.getByRole('option', { name: 'Main Board', exact: true }).click();
}

test.describe('meeting notes to issues', () => {
    // the app's service worker would see the requests first, and page.route never would
    test.use({ serviceWorkers: 'block' });

    test('without workspace AI the dialog explains how to set it up', async ({ page }) => {
        await openFromProject(page);
        await expect(dialog(page).getByText('AI isn’t set up for this workspace')).toBeVisible();
        await expect(dialog(page).getByRole('link', { name: 'Open workspace settings' })).toBeVisible();
        await expect(dialog(page).getByRole('button', { name: 'Extract action items' })).toHaveCount(0);
    });

    test('review, edit and reject suggestions, then create only the selected ones', async ({ page }) => {
        const pricing = uid('Update the FAQ wording');
        const venue = uid('Book the venue');
        const rejected = uid('Order pizza');
        const requests = await fakeAi(page, (members) => [
            proposal(1, pricing, 'Max to update the FAQ wording by Friday.', {
                assignee: { mention: 'Max', status: 'matched', user_id: members.find((m) => m.name === 'Max Member')!.id, candidates: [] },
                due: { text: 'by Friday', date: null, status: 'needs_meeting_date' },
            }),
            proposal(2, venue, 'E. will book the venue.', {
                assignee: { mention: 'E.', status: 'ambiguous', user_id: null, candidates: members.slice(0, 2) },
            }),
            proposal(3, rejected, 'Someone could order pizza.'),
            proposal(4, `${pricing}!`, 'Max to update the FAQ wording', { duplicate_of: 'p1', selected: false }),
        ]);

        await openFromProject(page);
        await pickMainBoard(page);
        await dialog(page).getByLabel('Meeting notes').fill('- Max to update the FAQ wording by Friday.\n- E. will book the venue.\n- Someone could order pizza.');
        await dialog(page).getByRole('button', { name: 'Extract action items' }).click();

        // evidence next to editable fields; flags are visible, nothing created yet
        await expect(suggestion(page, 1).getByText('Max to update the FAQ wording by Friday.')).toBeVisible();
        await expect(suggestion(page, 1).getByText(/“by Friday” — add the meeting date/)).toBeVisible();
        await expect(suggestion(page, 2).getByText(/matches 2 members — choose one/)).toBeVisible();
        await expect(suggestion(page, 4).getByText('Same as #1')).toBeVisible();
        await expect(suggestion(page, 4).getByRole('checkbox')).not.toBeChecked();
        await expect(dialog(page).getByText(/1 suggestion was left out/)).toBeVisible();
        expect(requests[0]).toMatchObject({ text: expect.stringContaining('Someone could order pizza') });

        // an ambiguous owner blocks Create until someone is chosen
        const create = dialog(page).getByRole('button', { name: /^Create \d issues?$/ });
        await expect(create).toBeDisabled();
        await suggestion(page, 2).getByRole('combobox', { name: 'Assignee' }).click();
        await page.getByRole('option', { name: 'Erin Owner' }).click();

        // edit one, reject one
        await suggestion(page, 1).getByLabel('Title').fill(`${pricing} v2`);
        await suggestion(page, 1).getByLabel('Due date').fill('2030-01-18');
        await suggestion(page, 3).getByRole('checkbox').uncheck();
        await expect(create).toHaveText('Create 2 issues');
        await create.click();

        await expect(dialog(page).getByText(/Created 2 issues on Main Board/)).toBeVisible();
        await expect(dialog(page).getByRole('link', { name: new RegExp(`${pricing} v2`) })).toBeVisible();
        await dialog(page).getByRole('button', { name: 'Open board' }).click();

        await expect(page).toHaveURL(new RegExp(`${urls.board()}$`));
        await expect(card(page, `${pricing} v2`)).toHaveCount(1);
        await expect(card(page, venue)).toHaveCount(1);
        await expect(card(page, rejected)).toHaveCount(0);
        await expect(card(page, `${pricing}!`)).toHaveCount(0);
    });

    test('the meeting date defaults to today, so “by Friday” becomes the due date', async ({ page }) => {
        const title = uid('Send the offer');
        const requests = await fakeAi(page, () => [
            proposal(1, title, 'Erin sends the offer by Friday.', { due: { text: 'by Friday', date: '2030-01-18', status: 'set' } }),
        ]);

        await openFromProject(page);
        await pickMainBoard(page);
        const meetingDate = dialog(page).getByLabel('Meeting date');
        await expect(meetingDate).toHaveValue(/^\d{4}-\d{2}-\d{2}$/); // today in the user's zone
        await expect(dialog(page).getByLabel('Time zone')).not.toHaveValue('');
        const today = await meetingDate.inputValue();
        await dialog(page).getByLabel('Meeting notes').fill('Erin sends the offer by Friday.');
        await dialog(page).getByRole('button', { name: 'Extract action items' }).click();
        await expect(suggestion(page, 1)).toBeVisible();
        expect(requests[0]).toMatchObject({ meeting_date: today, timezone: expect.any(String) });

        await expect(suggestion(page, 1).getByLabel('Due date')).toHaveValue('2030-01-18');
        const created = page.waitForRequest(/\/meeting-notes\/create$/);
        await dialog(page).getByRole('button', { name: 'Create 1 issue' }).click();
        expect((await created).postDataJSON().actions[0]).toMatchObject({ title, due_date: '2030-01-18' });
        await expect(dialog(page).getByText(/Created 1 issue on Main Board/)).toBeVisible();
    });

    test('a lost response is retried without creating the issues twice', async ({ page }) => {
        const title = uid('Send the recap');
        await fakeAi(page, () => [proposal(1, title, 'Erin sends the recap.')]);

        // the first Create reaches the server, but the browser never sees the answer
        let calls = 0;
        await page.route(/\/meeting-notes\/create$/, async (route: Route) => {
            calls++;
            if (calls === 1) {
                await route.fetch();
                await route.abort('failed');
                return;
            }
            await route.continue();
        });

        await openFromProject(page);
        await pickMainBoard(page);
        await dialog(page).getByLabel('Meeting notes').fill('Erin sends the recap.');
        await dialog(page).getByRole('button', { name: 'Extract action items' }).click();
        const create = dialog(page).getByRole('button', { name: 'Create 1 issue' });
        await create.click();
        await expect(dialog(page).getByText(/Click Create again — they are never created twice/)).toBeVisible();

        await create.click();
        await expect(dialog(page).getByText(/already created — nothing was duplicated/)).toBeVisible();
        expect(calls).toBe(2);

        await page.goto(urls.board());
        await expect(card(page, title)).toHaveCount(1);
    });

    test('extract from a doc: the issue quotes the notes and links back', async ({ page }) => {
        const docName = uid('Weekly sync');
        const title = uid('Send the release notes');
        const requests = await fakeAi(page, () => [proposal(1, title, 'Max sends the release notes.')]);

        await addProjectContent(page, 'Page', docName);
        await docEditor(page).click();
        const saved = draftSaved(page);
        await page.keyboard.type('Max sends the release notes.');
        await saved;

        await page.getByRole('button', { name: 'More actions' }).click();
        await page.getByRole('menuitem', { name: 'Extract action items' }).click();
        await expect(dialog(page).getByText(docName)).toBeVisible();
        await expect(dialog(page).getByLabel('Meeting notes')).toHaveCount(0);
        await pickMainBoard(page);
        await dialog(page).getByRole('button', { name: 'Extract action items' }).click();
        expect(requests[0]).toMatchObject({ page: expect.any(String) });
        expect(requests[0].text).toBeUndefined();

        await dialog(page).getByRole('button', { name: 'Create 1 issue' }).click();
        await dialog(page).getByRole('link', { name: new RegExp(title) }).click();

        await expect(page.getByText('Max sends the release notes.').first()).toBeVisible();
        await expect(page.getByRole('link', { name: docName }).first()).toBeVisible();
    });
});
