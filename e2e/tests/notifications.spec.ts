import { expect, test, uid, urls } from '../fixtures';
import { createIssue, boardCard } from '../helpers/leads-plans-search';
import { currentUserId, mention, notificationRow, openBell, rowAction } from '../helpers/notifications';

/**
 * Notifications inbox (BRAI-102): the bell (filters, grouping, actions, same-tab open), the inbox
 * page (archive view, bulk, keyboard) and per-category preferences.
 */
test.describe('notifications inbox', () => {
    test('the bell filters by tab, groups by day and marks read / unread / archives', async ({ page, memberPage }) => {
        const max = await currentUserId(memberPage);
        const topic = uid('bell-mention');
        await mention(page, max, topic);

        const title = uid('bell-assign');
        const key = await createIssue(page, title);
        const card = boardCard(page, title);
        await card.getByTitle('Unassigned — click to assign').click();
        await page.getByRole('button', { name: 'Max Member' }).click();
        await expect(card.getByTitle(/Assignee: Max Member/)).toBeVisible();

        await memberPage.goto(urls.home);
        const panel = await openBell(memberPage);
        await expect(panel.getByRole('heading', { name: 'Today' })).toBeVisible();
        const mentioned = notificationRow(panel, topic);
        const assigned = notificationRow(panel, `assigned you to ${key}`);
        await expect(mentioned).toBeVisible();
        await expect(assigned).toBeVisible();
        await expect(mentioned).toContainText('Erin Owner mentioned you in');

        // tabs filter on the server
        await panel.getByRole('tab', { name: 'Mentions' }).click();
        await expect(mentioned).toBeVisible();
        await expect(assigned).toHaveCount(0);
        await panel.getByRole('tab', { name: 'Assigned' }).click();
        await expect(assigned).toBeVisible();
        await expect(mentioned).toHaveCount(0);
        await panel.getByRole('tab', { name: /^Unread/ }).click();
        await expect(assigned).toBeVisible();

        // read → unread → archive
        await rowAction(memberPage, assigned, 'Mark as read');
        await expect(assigned.getByRole('button', { name: 'Mark as unread' })).toBeAttached();
        await rowAction(memberPage, assigned, 'Mark as unread');
        await expect(assigned.getByRole('button', { name: 'Mark as read' })).toBeAttached();
        await rowAction(memberPage, assigned, 'Archive');
        await expect(assigned).toHaveCount(0);

        // archived: gone from the inbox, listed under "Show archived"
        await memberPage.goto('/notifications');
        await expect(notificationRow(memberPage.getByRole('main'), topic)).toBeVisible();
        await expect(notificationRow(memberPage.getByRole('main'), `assigned you to ${key}`)).toHaveCount(0);
        await memberPage.getByRole('button', { name: /show archived/i }).click();
        await expect(memberPage).toHaveURL(/archived=1/);
        const archivedRow = notificationRow(memberPage.getByRole('main'), `assigned you to ${key}`);
        await expect(archivedRow).toBeVisible();
        await rowAction(memberPage, archivedRow, 'Unarchive');
        await expect(archivedRow).toHaveCount(0);
    });

    test('a notification opens in the same tab and counts as read', async ({ page, memberPage }) => {
        const max = await currentUserId(memberPage);
        const topic = uid('same-tab');
        await mention(page, max, topic);

        await memberPage.goto(urls.home);
        const panel = await openBell(memberPage);
        const row = notificationRow(panel, topic);
        await expect(row).toBeVisible();
        const read = memberPage.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/notifications\/[0-9a-f-]+\/read$/.test(r.url()));
        await row.getByRole('link').click();
        expect((await read).status()).toBeLessThan(400);
        await expect(memberPage).toHaveURL(new RegExp(urls.board()));
        expect(memberPage.context().pages()).toHaveLength(1);
        await expect(panel).toBeHidden();

        await memberPage.goto('/notifications');
        const entry = notificationRow(memberPage.getByRole('main'), topic);
        await expect(entry.getByRole('button', { name: 'Mark as unread' })).toBeAttached();
    });

    test('the inbox page: opening it keeps things unread; keyboard and bulk actions', async ({ page, memberPage }) => {
        const max = await currentUserId(memberPage);
        const first = uid('kbd-a');
        const second = uid('kbd-b');
        await mention(page, max, first);
        await mention(page, max, second);

        await memberPage.goto('/notifications?filter=mentions');
        const main = memberPage.getByRole('main');
        await expect(main.getByRole('tab', { name: 'Mentions', selected: true })).toBeVisible();
        const rowA = notificationRow(main, first);
        const rowB = notificationRow(main, second);
        await expect(rowA).toBeVisible();
        await expect(rowB).toBeVisible();

        // opening the page did not mark anything read
        await memberPage.reload();
        await expect(rowB.getByRole('button', { name: 'Mark as read' })).toBeAttached();

        // j moves down the list (parallel tests may add newer rows above ours) to the second mention;
        // u marks it read, e archives it
        await main.getByRole('heading', { name: 'Notifications', level: 1 }).click();
        const linkB = rowB.getByRole('link');
        for (let i = 0; i < 40 && !(await linkB.evaluate((el) => el === document.activeElement)); i++) {
            await memberPage.keyboard.press('j');
        }
        await expect(linkB).toBeFocused();
        const read = memberPage.waitForResponse((r) => /\/api\/notifications\/[0-9a-f-]+\/read$/.test(r.url()));
        await memberPage.keyboard.press('u');
        expect((await read).status()).toBeLessThan(400);
        await expect(rowB.getByRole('button', { name: 'Mark as unread' })).toBeAttached();
        const archived = memberPage.waitForResponse((r) => /\/api\/notifications\/[0-9a-f-]+\/archive$/.test(r.url()));
        await memberPage.keyboard.press('e');
        expect((await archived).status()).toBeLessThan(400);
        await expect(rowB).toHaveCount(0);

        // bulk: select the other one, mark it read, then archive it
        await rowA.getByRole('checkbox').check();
        const toolbar = main.getByRole('toolbar', { name: 'Bulk actions' });
        await expect(toolbar).toContainText('1 selected');
        const bulk = memberPage.waitForResponse((r) => r.url().endsWith('/api/notifications/bulk') || /\/api\/notifications\/[0-9a-f-]+\/read$/.test(r.url()));
        await toolbar.getByRole('button', { name: 'Mark read' }).click();
        expect((await bulk).status()).toBeLessThan(400);
        await expect(rowA.getByRole('button', { name: 'Mark as unread' })).toBeAttached();

        // both are under "Show archived" after archiving the second
        await rowA.getByRole('checkbox').check();
        const archivedA = memberPage.waitForResponse((r) => /\/api\/notifications\/(bulk|[0-9a-f-]+\/archive)$/.test(r.url()));
        await main.getByRole('toolbar', { name: 'Bulk actions' }).getByRole('button', { name: 'Archive' }).click();
        expect((await archivedA).status()).toBeLessThan(400);
        await expect(rowA).toHaveCount(0);
        await main.getByRole('button', { name: /show archived/i }).click();
        await expect(notificationRow(main, first)).toBeVisible();
        await expect(notificationRow(main, second)).toBeVisible();
    });

    test('turning a category off in the profile stops those notifications', async ({ page, memberPage }) => {
        // the owner silences in-app mentions; Max mentions her
        const erin = await currentUserId(page);
        await page.goto('/profile#notifications');
        const mentionsInApp = page.getByRole('switch', { name: 'Mentions: In-app' });
        await expect(mentionsInApp).toHaveAttribute('aria-checked', 'true');
        const saved = page.waitForResponse((r) => r.url().endsWith('/api/notifications/preferences'));
        await mentionsInApp.click();
        expect((await saved).status()).toBe(200);
        await expect(mentionsInApp).toHaveAttribute('aria-checked', 'false');

        try {
            const silenced = uid('silenced');
            await mention(memberPage, erin, silenced);
            await page.goto('/notifications');
            await expect(page.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible();
            await expect(notificationRow(page.getByRole('main'), silenced)).toHaveCount(0);
        } finally {
            await page.goto('/profile');
            const toggle = page.getByRole('switch', { name: 'Mentions: In-app' });
            const restored = page.waitForResponse((r) => r.url().endsWith('/api/notifications/preferences'));
            await toggle.click();
            expect((await restored).status()).toBe(200);
            await expect(toggle).toHaveAttribute('aria-checked', 'true');
        }

        const heard = uid('heard');
        await mention(memberPage, erin, heard);
        await page.goto('/notifications');
        await expect(notificationRow(page.getByRole('main'), heard)).toBeVisible();
    });

    test('on a phone the bell opens full screen', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await page.goto(urls.home);
        await page.getByRole('button', { name: /^Notifications/ }).click();
        const sheet = page.getByRole('dialog', { name: 'Notifications' });
        await expect(sheet).toBeVisible();
        const box = await sheet.boundingBox();
        expect(box?.width).toBeGreaterThanOrEqual(389);
        await expect(sheet.getByRole('tab', { name: 'All' })).toBeVisible();
        await sheet.getByRole('button', { name: 'Close' }).click();
        await expect(sheet).toBeHidden();
    });
});
