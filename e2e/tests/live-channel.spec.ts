import { expect, test, uid, urls } from '../fixtures';
import { PASSWORD, REMOTE, USERS } from '../env';
import { liveServerAvailable, liveTab, optInLive } from '../helpers/live';
import { currentUserId, mention, notificationRow, openBell } from '../helpers/notifications';

/**
 * Live channels (BRAI-151): the realtime server global-setup started; both contexts opt in with
 * `X-E2E-Live: 1`. The bell is the reference subscriber; presence and signals are checked through
 * the tab's live client (exposed in e2e only), before any UI uses them.
 */
test.describe('live channels', () => {
    test.skip(REMOTE, 'needs the local realtime server');
    test.skip(!liveServerAvailable(), 'run `npm --prefix realtime ci` first');

    test('a notification for Max shows up in his open bell without a reload', async ({ page, memberPage }) => {
        await optInLive(memberPage);
        const max = await currentUserId(memberPage);
        await memberPage.goto(urls.home);
        await expect.poll(() => liveTab(memberPage).status(`user.${max}`), { message: 'the bell is live' }).toBe('live');

        // the panel is open before anything happens: its feed never polls, only the live event reloads it
        const panel = await openBell(memberPage);
        const topic = uid('live-bell');
        await expect(notificationRow(panel, topic)).toHaveCount(0);

        const before = await memberPage.getByRole('button', { name: /^Notifications/ }).getAttribute('aria-label');
        await mention(page, max, topic);

        await expect(notificationRow(panel, topic)).toBeVisible({ timeout: 10_000 });
        await expect(notificationRow(panel, topic)).toContainText(`${USERS.owner.name} mentioned you in`);
        await expect(memberPage.getByRole('button', { name: /^Notifications \(\d+ unread\)/ })).not.toHaveAttribute('aria-label', before ?? '');
    });

    test('two people on the same issue see each other and the typing signal — only there', async ({ page, memberPage }) => {
        await optInLive(page);
        await optInLive(memberPage);
        const erin = await currentUserId(page);
        const max = await currentUserId(memberPage);

        // an issue both can open, and one channel Max may not join (Erin's own)
        const title = uid('live-presence');
        await mention(page, max, title);
        await page.goto(urls.board());
        const itemId = await page.locator('#app').evaluate((el, t) => {
            const props = JSON.parse((el as HTMLElement).dataset.page ?? '{}').props;
            return props.board.columns.flatMap((c: { items: { id: number; title: string }[] }) => c.items).find((i: { title: string }) => i.title === t)?.id as number;
        }, title);
        expect(itemId, 'the new issue on the board').toBeTruthy();
        const channel = `item.${itemId}`;

        await memberPage.goto(urls.board());
        for (const tab of [liveTab(page), liveTab(memberPage)]) await tab.join(channel, true);
        const both = [USERS.owner.name, USERS.member.name].sort();
        await expect.poll(async () => (await liveTab(page).members(channel)).sort()).toEqual(both);
        await expect.poll(async () => (await liveTab(memberPage).members(channel)).sort()).toEqual(both);

        // Max types: Erin gets the signal with Max as the sender (set by the server)
        await expect.poll(() => liveTab(memberPage).signal(channel, 'typing', { field: 'comment' })).toBe(true);
        await expect.poll(async () => (await liveTab(page).signals()).filter((s) => s.channel === channel)).toEqual([
            { channel, name: 'typing', data: { field: 'comment' }, from: { id: max, name: USERS.member.name } },
        ]);

        // someone else's user channel is refused
        await liveTab(memberPage).join(`user.${erin}`);
        await expect.poll(() => liveTab(memberPage).status(`user.${erin}`)).toBe('forbidden');

        // Max leaves the issue: Erin sees her alone
        await liveTab(memberPage).leave(channel);
        await expect.poll(() => liveTab(page).members(channel)).toEqual([USERS.owner.name]);
    });

    test('signing out closes that browser’s live socket at once; Max’s other browser stays live (Codex realtime review 1)', async ({ browser }) => {
        // two browsers of Max, each with its own fresh session (the worker's shared session stays untouched)
        const signedIn = async () => {
            const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
            await context.request.get('/login');
            const xsrf = (await context.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
            const login = await context.request.post('/login', {
                form: { email: USERS.member.email, password: PASSWORD },
                headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
                maxRedirects: 0,
            });
            expect(login.status()).toBe(302);
            const page = await context.newPage();
            await optInLive(page);
            return { context, page };
        };
        const leaving = await signedIn();
        const staying = await signedIn();
        const max = await currentUserId(leaving.page);
        for (const { page } of [leaving, staying]) {
            await page.goto(urls.home);
            await expect.poll(() => liveTab(page).status(`user.${max}`), { message: 'the bell is live' }).toBe('live');
        }

        const xsrf = (await leaving.context.storageState()).cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
        const logout = await leaving.context.request.post('/logout', { headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' }, maxRedirects: 0 });
        expect(logout.status()).toBeLessThan(400);

        // pushed by the logout (4401 "Signed out"): the tab's socket is gone and no new token comes without a sign-in
        await expect.poll(() => liveTab(leaving.page).status(`user.${max}`), { timeout: 10_000 }).not.toBe('live');
        await leaving.page.waitForTimeout(1_500);
        expect(await liveTab(leaving.page).status(`user.${max}`)).not.toBe('live');
        expect(await liveTab(staying.page).status(`user.${max}`), 'the other browser session is untouched').toBe('live');
        await leaving.context.close();
        await staying.context.close();
    });
});
