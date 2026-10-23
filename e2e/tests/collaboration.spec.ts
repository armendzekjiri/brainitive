import { PASSWORD } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { card, createCard, issueDialog, openCard } from '../helpers/boards';
import { canReadMail, mailLink } from '../mail';

/**
 * Collaboration across real sessions (BRAI-96): an owner, the seeded member and brand-new people
 * in separate browser contexts — invitations, joining, and the notifications each side gets.
 */
test.describe('collaboration', () => {
    test.skip(!canReadMail, 'needs the local mail log');

    test('an invited person signs up from the email, verifies and joins the workspace', async ({ page, browser }) => {
        const name = `Nina ${uid('new')}`;
        const email = `${uid('invitee')}@example.test`;

        // owner: Workspace settings › Members › Invite
        await page.goto(`${urls.workspace()}/edit`);
        await page.getByRole('tab', { name: /members/i }).click();
        await page.getByRole('button', { name: 'Invite Members' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel(/email/i).fill(email);
        await dialog.locator('button[type="submit"]').click();
        // not on the platform yet → "Send invitation?"
        await dialog.getByRole('button', { name: 'Send invitation' }).click();
        await expect(dialog).toBeHidden();
        await expect(page.getByText(email).first()).toBeVisible();

        // the invitee, in a browser of their own, follows the email
        const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const invitee = await guest.newPage();
        await invitee.goto(await mailLink(email, /\/invite\//));
        await expect(invitee.getByText('E2E Workspace').first()).toBeVisible();
        await invitee.getByRole('link', { name: 'Sign up' }).click();
        await invitee.getByLabel('Name').fill(name);
        await invitee.getByLabel('Email').fill(email);
        await invitee.getByLabel('Password', { exact: true }).fill(PASSWORD);
        await invitee.getByLabel('Confirm Password').fill(PASSWORD);
        await invitee.getByRole('button', { name: 'Register' }).click();
        await expect(invitee).toHaveURL(/verify-email|invite/);

        // verifying the address accepts the pending invitation
        await invitee.goto(await mailLink(email, /verify-email/));
        await invitee.goto(urls.home);
        await expect(invitee.getByText('E2E Workspace').first()).toBeVisible();

        // owner: the invitation turned into a workspace member…
        await page.reload();
        await page.getByRole('tab', { name: /members/i }).click();
        await expect(page.getByText(name).first()).toBeVisible();

        // …who doesn't see a project until they're added to it
        const board = await invitee.goto(urls.board());
        expect(board?.status()).toBe(403);
        await page.goto(`${urls.project()}/edit`);
        await page.getByRole('tab', { name: /members/i }).click();
        await page.getByRole('combobox').filter({ hasText: 'Select user…' }).click();
        await page.getByRole('option', { name: new RegExp(name) }).click();
        await Promise.all([
            page.waitForResponse((r) => r.request().method() === 'POST' && /\/members$/.test(new URL(r.url()).pathname)),
            page.getByRole('button', { name: 'Add', exact: true }).click(),
        ]);
        await expect(page.getByText(email).first()).toBeVisible();

        await invitee.goto(urls.board());
        await expect(invitee.getByText('Homepage hero')).toBeVisible();
        await guest.close();
    });

    test('an @mention in a comment reaches the person, who opens the issue from the inbox', async ({ page, memberPage }) => {
        const title = uid('mention');
        const note = uid('please-review');
        await page.goto(urls.board());
        await createCard(page, title);
        const dialog = await openCard(page, title);

        // owner writes "@Max …" — the mention picker offers him, Enter inserts the mention
        await dialog.locator('[contenteditable="true"][aria-placeholder="Add a comment…"]').click();
        await page.keyboard.type('@Max');
        const picker = page.getByRole('listbox', { name: 'Mention someone' });
        await expect(picker.getByRole('option', { name: /Max Member/, selected: true })).toBeVisible();
        await page.keyboard.press('Enter');
        await expect(picker).toBeHidden();
        await page.keyboard.type(` ${note}`);
        const posted = page.waitForResponse((r) => r.request().method() === 'POST' && /\/items\/\d+\/comments/.test(r.url()));
        await dialog.getByRole('button', { name: 'Save', exact: true }).click();
        expect((await posted).status()).toBeLessThan(400);
        await expect(dialog.getByText(note)).toBeVisible();

        // Max: the inbox says who mentioned him, and the entry opens that issue
        await memberPage.goto('/notifications');
        const entry = memberPage.getByText(/Erin Owner mentioned you in/).first();
        await expect(entry).toBeVisible();
        await entry.locator('xpath=ancestor-or-self::*[.//a][1]').getByRole('link').first().click();
        await expect(issueDialog(memberPage).or(memberPage.getByRole('heading', { name: title }))).toBeVisible();
        await expect(memberPage.getByText(note)).toBeVisible();
    });

    test('the assignee hears about status changes on their issue', async ({ page, memberPage }) => {
        const title = uid('status-news');
        await page.goto(urls.board());
        await createCard(page, title);

        // assign Max (he becomes a watcher) …
        const created = card(page, title);
        await created.getByTitle('Unassigned — click to assign').click();
        await page.getByRole('button', { name: 'Max Member' }).click();
        await expect(created.getByTitle(/Assignee: Max Member/)).toBeVisible();

        // … and move it to Done in the issue dialog
        const dialog = await openCard(page, title);
        const key = (await dialog.getByRole('heading', { level: 1 }).locator('xpath=ancestor::*[@role="dialog"]').getAttribute('aria-label'))?.match(/[A-Z]+-\d+/)?.[0]
            ?? (await dialog.textContent())?.match(/WEB-\d+/)?.[0];
        const moved = page.waitForResponse((r) => r.request().method() === 'PATCH' && /\/items\/\d+/.test(r.url()));
        await dialog.getByRole('combobox', { name: 'Status' }).click();
        await page.getByRole('option', { name: 'Done' }).click();
        expect((await moved).status()).toBeLessThan(400);

        await memberPage.goto('/notifications');
        await expect(memberPage.getByText(new RegExp(`Erin Owner moved ${key} from`)).first()).toBeVisible();
        await expect(memberPage.getByText(new RegExp(`assigned you to ${key}`)).first()).toBeVisible();
    });
});
