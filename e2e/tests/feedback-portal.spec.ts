import type { Browser, Page } from '@playwright/test';
import { SEED } from '../env';
import { expect, test, uid, urls } from '../fixtures';
import { guestContext } from '../helpers/auth-account';
import { newGuest, portalSignIn } from '../helpers/portal';
import { createBlankProject } from '../helpers/workspaces-projects';
import { canReadMail, mailLink } from '../mail';

/**
 * Public feedback portal (v1.42.0, BRAI-60): a project admin opens a portal, guests sign in with
 * their email, submit and vote; the admin moderates, sets the public status; followers get a mail
 * with an unfollow link that works without signing in; a switched-off portal is gone.
 */

async function guestPage(browser: Browser, viewport?: { width: number; height: number }): Promise<Page> {
    const context = await guestContext(browser);
    const page = await context.newPage();
    if (viewport) await page.setViewportSize(viewport);
    return page;
}

const voteButton = (page: Page, title: string) => page.getByRole('button', { name: new RegExp(`(Vote for|Remove your vote for) “${title}”`) });

test.describe('feedback portal', () => {
    test('admin opens a portal, a guest submits, the admin approves and plans it, guests vote once each', async ({ page, browser }) => {
        test.skip(!canReadMail, 'guests sign in through the emailed link: local mail log only (not on dev)');
        test.setTimeout(120_000);
        const slug = uid('ideas').toLowerCase();
        const title = `Offline mode ${uid('idea')}`;

        // ── admin: project → quick link "Feedback" → enable the portal ──
        const project = await createBlankProject(page, SEED.workspace);
        await page.goto(urls.project(project.slug));
        await page.getByRole('link', { name: 'Feedback portal' }).click();
        await expect(page.getByRole('heading', { name: 'Feedback portal' })).toBeVisible();
        await page.getByLabel('Public address').fill(slug);
        await page.getByLabel('Title').fill('Website ideas');
        await page.getByLabel('Portal is public').check();
        await page.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByTestId('feedback-portal-state')).toHaveText('Public');
        await expect(page.getByTestId('feedback-public-url')).toContainText(`/feedback/${slug}`);
        await expect(page.getByRole('button', { name: 'Copy public link' })).toBeVisible();

        // ── guest 1 (phone width): read anonymously, sign in, submit an idea ──
        const ann = await guestPage(browser, { width: 390, height: 844 });
        await ann.goto(`/feedback/${slug}`);
        await expect(ann.getByTestId('feedback-portal-title')).toHaveText('Website ideas');
        await expect(ann.getByText('No ideas yet. Be the first to share one!')).toBeVisible();
        expect(await ann.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'no sideways scroll on a phone').toBe(true);

        const annGuest = newGuest('ann');
        await portalSignIn(ann, annGuest, `/feedback/${slug}`);
        await ann.getByRole('button', { name: 'Share an idea' }).click();
        await ann.getByRole('textbox', { name: 'Title' }).fill(title);
        await ann.getByLabel(/Details/).fill('So I can keep working on the train.');
        await ann.getByRole('button', { name: 'Submit idea' }).click();
        await expect(ann.getByTestId('feedback-pending')).toBeVisible();
        await expect(ann.getByTestId('feedback-post-title')).toHaveText(title);
        const postUrl = new URL(ann.url()).pathname;

        // not public yet: an anonymous visitor gets 404
        const anon = await guestPage(browser);
        expect((await anon.goto(postUrl))?.status()).toBe(404);

        // ── admin: approve, then set the public status to Planned with a note ──
        await page.reload();
        const pending = page.getByTestId('feedback-moderation-post').filter({ hasText: title });
        await pending.getByRole('button', { name: 'Approve' }).click();
        await page.getByRole('link', { name: /^Published/ }).click();
        await expect(page).toHaveURL(/tab=published/); // the visit re-mounts the forms: wait for it
        const published = page.getByTestId('feedback-moderation-post').filter({ hasText: title });
        await published.getByLabel('Status of #1').selectOption('planned');
        await published.getByLabel('Public note for #1').fill('Coming this quarter.');
        await published.getByRole('button', { name: 'Update status' }).click();
        await expect(published.getByTestId('feedback-status')).toHaveText('Planned');
        await expect(page.getByTestId('feedback-audit')).toContainText('changed the status of #1 → Planned');
        await expect(page.getByTestId('feedback-audit')).toContainText('approved #1');

        // ── guest 1: votes, sees it on the roadmap ──
        await ann.goto(`/feedback/${slug}`);
        await expect(voteButton(ann, title)).toHaveText('0');
        await voteButton(ann, title).click();
        await expect(voteButton(ann, title)).toHaveAttribute('aria-pressed', 'true');
        await expect(voteButton(ann, title)).toHaveText('1');
        await ann.getByRole('link', { name: 'Roadmap' }).click();
        await expect(ann.getByTestId('roadmap-planned')).toContainText(title);
        await expect(ann.getByTestId('roadmap-building')).toContainText('Nothing here yet.');

        // the author follows their idea: the status mail came, with a sign-in-free unfollow link
        const unfollow = await mailLink(annGuest.email, /\/unfollow\?/);

        // ── guest 2: votes; a repeated vote counts once ──
        const bob = await guestPage(browser);
        await portalSignIn(bob, newGuest('bob'), `/feedback/${slug}`);
        await voteButton(bob, title).click();
        await expect(voteButton(bob, title)).toHaveText('2');
        const xsrf = (await bob.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
        const again = await bob.request.post(`${postUrl}/vote`, { headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } });
        expect(again.ok()).toBeTruthy();
        await bob.goto(postUrl);
        await expect(voteButton(bob, title)).toHaveText('2');
        await expect(bob.getByTestId('feedback-status-note')).toHaveText('Coming this quarter.');

        // ── the unfollow link from the mail: confirm page, no sign-in needed ──
        await anon.goto(unfollow);
        await anon.getByRole('button', { name: 'Unfollow' }).click();
        await expect(anon.getByTestId('feedback-unfollowed')).toBeVisible();
        await ann.goto(postUrl);
        await expect(ann.getByRole('button', { name: 'Follow', exact: true })).toBeVisible();

        // ── admin switches the portal off: it is gone ──
        await page.getByLabel('Portal is public').uncheck();
        await page.getByRole('button', { name: 'Save settings' }).click();
        await expect(page.getByTestId('feedback-portal-state')).toHaveText('Off');
        expect((await anon.goto(`/feedback/${slug}`))?.status()).toBe(404);
        expect((await anon.goto(postUrl))?.status()).toBe(404);

        for (const p of [ann, bob, anon]) await p.context().close();
    });

    test('an unknown portal is 404 and the team page is for project admins only', async ({ browser, memberPage }) => {
        const anon = await guestPage(browser);
        expect((await anon.goto(`/feedback/${uid('nope').toLowerCase()}`))?.status()).toBe(404);
        await anon.context().close();

        // the seeded member is a project editor, not an admin
        const response = await memberPage.goto(`${urls.project()}/feedback`);
        expect(response?.status()).toBe(403);
        await memberPage.goto(urls.project());
        await expect(memberPage.getByRole('link', { name: 'Project releases' })).toBeVisible();
        await expect(memberPage.getByRole('link', { name: 'Feedback portal' })).toHaveCount(0);
    });
});
