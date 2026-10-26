import type { Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { PASSWORD, SEED, USERS } from '../env';
import { guestContext, openUserMenu } from '../helpers/auth-account';
import { newIssue, projectPath, request } from '../helpers/workload';

/**
 * Offline mode (BRAI-82): opt-in per device. Task lists, quick capture, My Day and issue edits keep
 * working without internet, sync when the connection is back, and a conflicting edit is never
 * overwritten silently — the user decides in the Sync conflicts panel.
 */

const SHELL_CACHE = 'brainitive-offline-shell-v1';

/** Turn on "Work offline on this device" and wait until the app is cached. */
async function enableOffline(page: Page) {
    await page.goto('/profile');
    const toggle = page.getByRole('switch', { name: 'Work offline on this device' });
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await toggle.click();
    await expect(page.getByText('Ready to work offline on this device')).toBeVisible({ timeout: 30_000 });
    await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
}

/** Keys stored in this device's offline database (never creates one). */
async function snapshotKeys(page: Page): Promise<string[]> {
    return page.evaluate(async () => {
        const name = (await indexedDB.databases()).map((d) => d.name ?? '').find((n) => n.startsWith('brainitive-offline-'));
        if (!name) return [];
        return new Promise<string[]>((resolve) => {
            const req = indexedDB.open(name);
            req.onsuccess = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains('snapshots')) {
                    db.close();
                    return resolve([]);
                }
                const keys = db.transaction('snapshots').objectStore('snapshots').getAllKeys();
                keys.onsuccess = () => {
                    db.close();
                    resolve(keys.result.map(String));
                };
            };
            req.onerror = () => resolve([]);
        });
    });
}

async function offlineDatabases(page: Page): Promise<string[]> {
    return page.evaluate(async () => (await indexedDB.databases()).map((d) => d.name ?? '').filter((n) => n.startsWith('brainitive-offline-')));
}

/** A new task list in the seeded project; returns its path. */
async function newTaskList(page: Page, name: string): Promise<string> {
    const response = await request(page, 'POST', `${projectPath()}/task-lists`, { name });
    expect(response.status(), await response.text()).toBe(302);
    return new URL(response.headers().location ?? '', 'http://x').pathname;
}

const issueUrl = (id: number) => `${projectPath()}/boards/${SEED.board}/items/${id}`;
/** The online / offline indicator in the top bar (its name is its state). */
const indicator = (page: Page) => page.getByRole('button', { name: /^(Offline|All changes synced|\d+ changes? waiting to sync|\d+ sync conflicts?)/ });

test.describe('offline mode', () => {
    test('a task, a capture and an issue edit made offline sync when the connection is back', async ({ page, context }) => {
        test.slow(); // a list, an issue, the app cache and three round trips
        const listName = uid('Offline list');
        const issueTitle = uid('Offline issue');
        const task = uid('Buy batteries');
        const idea = uid('Idea on the train');
        const renamed = `${issueTitle} (edited offline)`;

        const listUrl = await newTaskList(page, listName);
        await page.goto(urls.board());
        const issueId = await newIssue(page, SEED.project, issueTitle);

        await enableOffline(page);
        // open them once while online: that's what the device keeps
        await page.goto(listUrl);
        await expect(page.getByRole('heading', { level: 1, name: listName })).toBeVisible();
        await page.goto(issueUrl(issueId));
        await expect(page.getByRole('heading', { level: 1, name: issueTitle })).toBeVisible();
        await expect.poll(async () => (await snapshotKeys(page)).filter((k) => k.startsWith('list:') || k === `issue:${issueId}`)).toHaveLength(2);

        await context.setOffline(true);

        // the task list, from this device
        await page.goto(listUrl);
        await expect(page.getByRole('heading', { level: 1, name: listName })).toBeVisible();
        await page.getByRole('textbox', { name: 'Add a task' }).fill(task);
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        const taskRow = page.getByRole('listitem').filter({ hasText: task });
        await expect(taskRow.getByText('Not synced yet')).toBeVisible();

        // quick capture
        await page.goto('/captures');
        await page.getByLabel('Idea').fill(idea);
        await page.getByRole('button', { name: 'Save capture' }).click();
        await expect(page.getByRole('region', { name: 'Captures waiting to sync' }).getByText(idea)).toBeVisible();

        // the issue: title + status
        await page.goto(issueUrl(issueId));
        const title = page.getByLabel('Title');
        await expect(title).toHaveValue(issueTitle);
        await title.fill(renamed);
        await title.press('Enter');
        await page.getByLabel('Status').selectOption({ label: 'In Progress' });
        await expect(page.getByText('Not synced yet').first()).toBeVisible();
        await expect(indicator(page)).toHaveAccessibleName('Offline · 3 changes waiting to sync');

        // back online: everything goes out, in order, once
        await context.setOffline(false);
        await expect(page.getByText('3 changes synced')).toBeVisible({ timeout: 20_000 });
        await expect(indicator(page)).toHaveAccessibleName('All changes synced');

        await page.goto(listUrl);
        await expect(page.getByText(task, { exact: true })).toBeVisible();
        await page.goto(issueUrl(issueId));
        await expect(page.getByRole('heading', { level: 1, name: renamed })).toBeVisible();
        await page.goto('/captures');
        await expect(page.getByText(idea).first()).toBeVisible();
    });

    test('a conflicting edit from someone else is shown, then kept mine or theirs — never overwritten silently', async ({ page, context, memberPage }) => {
        test.slow();
        const original = uid('Conflict issue');
        await page.goto(urls.board());
        const issueId = await newIssue(page, SEED.project, original);
        await enableOffline(page);
        await page.goto(issueUrl(issueId));
        await expect(page.getByRole('heading', { level: 1, name: original })).toBeVisible();
        await expect.poll(() => snapshotKeys(page)).toContain(`issue:${issueId}`);

        const editOffline = async (from: string, to: string) => {
            await context.setOffline(true);
            await page.goto(issueUrl(issueId));
            const title = page.getByLabel('Title');
            await expect(title).toHaveValue(from);
            await title.fill(to);
            await title.press('Enter');
            await expect(indicator(page)).toHaveAccessibleName('Offline · 1 change waiting to sync');
        };
        const theyRename = async (to: string) => {
            const response = await request(memberPage, 'PATCH', issueUrl(issueId), { title: to });
            expect(response.status(), await response.text()).toBeLessThan(400);
        };
        const expectServerTitle = async (title: string) => {
            await page.goto(issueUrl(issueId));
            await expect(page.getByRole('heading', { level: 1, name: title, exact: true })).toBeVisible();
        };
        const openConflict = async () => {
            await context.setOffline(false);
            await expect(indicator(page)).toHaveAccessibleName('1 sync conflict', { timeout: 20_000 });
            await indicator(page).click();
            await page.getByRole('button', { name: 'Review conflicts' }).click();
            const panel = page.getByRole('dialog', { name: 'Sync conflicts' });
            await expect(panel).toBeVisible();
            return panel;
        };

        // 1) keep mine
        const mine = `${original} mine`;
        const theirs = `${original} theirs`;
        await editOffline(original, mine);
        await theyRename(theirs);
        let panel = await openConflict();
        const row = panel.getByRole('row', { name: /Title/ });
        await expect(row).toContainText(mine);
        await expect(row).toContainText(theirs);
        await panel.getByRole('button', { name: 'Keep mine' }).click();
        await expect(panel.getByText('No conflicts — everything is in sync.')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(indicator(page)).toHaveAccessibleName('All changes synced', { timeout: 20_000 });
        await expectServerTitle(mine);

        // 2) keep theirs
        const mine2 = `${original} mine again`;
        const theirs2 = `${original} theirs again`;
        await editOffline(mine, mine2);
        await theyRename(theirs2);
        panel = await openConflict();
        await expect(panel.getByRole('row', { name: /Title/ })).toContainText(mine2);
        await panel.getByRole('button', { name: 'Keep theirs' }).click();
        await expect(panel.getByText('No conflicts — everything is in sync.')).toBeVisible();
        await page.keyboard.press('Escape');
        await expectServerTitle(theirs2);

        // the device follows: offline it now shows their title
        await context.setOffline(true);
        await page.goto(issueUrl(issueId));
        await expect(page.getByLabel('Title')).toHaveValue(theirs2);
        await context.setOffline(false);
    });

    test('My Day works offline: completing a planned issue syncs it to the done column', async ({ page, context }) => {
        test.slow();
        const title = uid('Plan offline');
        await page.goto(urls.board());
        const issueId = await newIssue(page, SEED.project, title);
        const planned = await request(page, 'POST', '/my-day', { work_type: 'board', work_id: issueId });
        expect(planned.status(), await planned.text()).toBeLessThan(400);

        await enableOffline(page);
        await page.goto('/my-work');
        await expect(page.getByRole('region', { name: 'My Day' }).getByText(title)).toBeVisible();
        await expect.poll(() => snapshotKeys(page)).toContain('myday:today');

        await context.setOffline(true);
        await page.goto('/my-work');
        const myDay = page.getByRole('region', { name: 'My Day' });
        await myDay.getByRole('checkbox', { name: `Mark “${title}” as done` }).click();
        await expect(myDay.getByRole('checkbox', { name: `Reopen “${title}”` })).toHaveAttribute('aria-checked', 'true');
        await expect(myDay.getByText('Not synced yet')).toBeVisible();

        await context.setOffline(false);
        await expect(page.getByText('1 change synced')).toBeVisible({ timeout: 20_000 });
        await page.goto('/my-work');
        await expect(page.getByRole('region', { name: 'My Day' }).getByRole('checkbox', { name: `Reopen “${title}”` })).toHaveAttribute('aria-checked', 'true');
    });

    test('pages that are not on the device show what works offline', async ({ page, context }) => {
        await enableOffline(page);
        await context.setOffline(true);
        await page.goto(projectPath()); // the project overview isn't kept offline
        await expect(page.getByRole('heading', { level: 1, name: 'This page isn’t available offline' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'My Day' }).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'Quick capture' })).toBeVisible();
        await page.getByRole('link', { name: 'Quick capture' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Quick capture' })).toBeVisible();
        await context.setOffline(false);
    });

    test('signing out deletes the offline data from the device', async ({ browser }) => {
        // a fresh session of its own: signing out must not end the saved member session
        const context = await guestContext(browser);
        const page = await context.newPage();
        await page.goto('/login');
        await page.getByLabel('Email', { exact: true }).fill(USERS.member.email);
        await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).not.toHaveURL(/\/login$/);

        await enableOffline(page);
        await page.goto('/my-work');
        await expect.poll(() => snapshotKeys(page)).toContain('myday:today');
        expect(await offlineDatabases(page)).toHaveLength(1);
        expect(await page.evaluate((name) => caches.has(name), SHELL_CACHE)).toBe(true);

        await openUserMenu(page, USERS.member.name);
        await page.getByRole('menuitem', { name: 'Sign out' }).click();
        await expect(page).toHaveURL(/\/$/);

        await expect.poll(() => offlineDatabases(page)).toEqual([]);
        await expect.poll(() => page.evaluate((name) => caches.has(name), SHELL_CACHE)).toBe(false);
        await context.close();
    });
});
