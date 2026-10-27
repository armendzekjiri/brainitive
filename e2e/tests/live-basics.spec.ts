import { spawn, type ChildProcess } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs';
import path from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { BASE_URL, COLLAB, REMOTE, ROOT, USERS } from '../env';
import { createHelpdesk, ticketCard, ticketDialog } from '../helpers/helpdesk';
import { liveServerAvailable, liveTab } from '../helpers/live';

/**
 * Live basics (BRAI-151 part 2): comments appear on an open issue without a reload, "who's here"
 * avatars on issues and boards, the typing indicator, and co-editing an issue description.
 * Live channels run on the server global-setup started (`X-E2E-Live: 1`); the description test runs
 * its own realtime server on DESC_PORT (`X-E2E-Collab-Port`) — live-docs owns COLLAB.port and
 * live-whiteboards COLLAB.port + 3. Serial: one server, one test runs while it is down.
 */
test.describe.configure({ mode: 'serial' });

const DESC_PORT = COLLAB.port + 4;
let server: ChildProcess | null = null;

async function startRealtime() {
    server = spawn(process.execPath, [path.join(ROOT, 'realtime', 'src', 'index.js')], {
        cwd: ROOT,
        env: { ...process.env, COLLAB_PORT: String(DESC_PORT), COLLAB_HOST: '127.0.0.1', COLLAB_SECRET: COLLAB.secret, COLLAB_APP_URL: BASE_URL },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    const end = Date.now() + 15_000;
    while (Date.now() < end) {
        try {
            if ((await fetch(`http://127.0.0.1:${DESC_PORT}/`)).ok) return;
        } catch {
            /* not up yet */
        }
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error('the realtime server did not start');
}

async function stopRealtime() {
    if (!server || server.exitCode !== null) return;
    const exited = once(server, 'exit');
    server.kill('SIGKILL');
    await exited;
    server = null;
}

/** Live channels (+ live descriptions on DESC_PORT) for this browser context. */
const optIn = (page: Page, { collab = false } = {}) =>
    page.context().setExtraHTTPHeaders({
        'X-E2E-Live': '1',
        ...(collab ? { 'X-E2E-Collab': '1', 'X-E2E-Collab-Port': String(DESC_PORT) } : {}),
    });

/** A new issue in the seeded board (retried: two issues created at once in one project can collide). */
async function newIssue(page: Page, title: string): Promise<number> {
    await page.goto(urls.board());
    const columnId = await page.locator('#app').evaluate((el) => JSON.parse((el as HTMLElement).dataset.page ?? '{}').props.board.columns[0].id as number);
    await expect(async () => {
        const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '';
        const response = await page.request.post(`${urls.board()}/items`, {
            headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
            data: { title, column_id: columnId },
            maxRedirects: 0,
        });
        expect(response.status()).toBeLessThan(400);
    }).toPass({ timeout: 15_000 });
    await page.goto(urls.board());
    const id = await page.locator('#app').evaluate((el, t) => {
        const props = JSON.parse((el as HTMLElement).dataset.page ?? '{}').props;
        return props.board.columns.flatMap((c: { items: { id: number; title: string }[] }) => c.items).find((i: { title: string }) => i.title === t)?.id as number;
    }, title);
    expect(id, 'the new issue on the board').toBeTruthy();
    return id;
}

const issueUrl = (id: number) => `${urls.board()}/items/${id}`;
const commentBox = (page: Page) => page.locator('[contenteditable="true"][aria-placeholder="Add a comment…"]');
const typingIndicator = (page: Page) => page.getByTestId('typing-indicator');
const avatars = (scope: Page | Locator) => scope.getByTestId('live-avatars');
const liveDescription = (page: Page) => page.getByTestId('live-description').locator('[contenteditable]').first();

async function typeAtEnd(editor: Locator, text: string) {
    await editor.click();
    await editor.page().keyboard.press('ControlOrMeta+End');
    await editor.page().keyboard.type(text);
}

test.describe('live basics', () => {
    test.skip(REMOTE, 'needs the local realtime servers');
    test.skip(!liveServerAvailable(), 'run `npm run collab:install` first');

    test.afterAll(stopRealtime);

    test('a colleague’s comment appears on the open issue; who’s here and who’s typing', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);
        const id = await newIssue(page, uid('live-comments'));

        await page.goto(issueUrl(id));
        await memberPage.goto(issueUrl(id));
        await expect.poll(() => liveTab(page).status(`item.${id}`), { message: 'the issue is live' }).toBe('live');

        // who's here: each sees the other in the header, never themselves
        await expect(avatars(page)).toHaveAttribute('aria-label', `Also here: ${USERS.member.name}`);
        await expect(avatars(memberPage)).toHaveAttribute('aria-label', `Also here: ${USERS.owner.name}`);

        // Erin has a draft going: it must survive Max's comment arriving
        await commentBox(page).click();
        await page.keyboard.type('Draft from Erin');

        // Max types: Erin sees it under her comment box (and Max never sees himself typing)
        await commentBox(memberPage).click();
        await memberPage.keyboard.type('Ready for review');
        await expect(typingIndicator(page)).toHaveText(`${USERS.member.name} is typing…`);
        await expect(typingIndicator(memberPage)).not.toContainText(USERS.member.name);

        await memberPage.getByRole('button', { name: 'Save', exact: true }).click();
        // no reload on Erin's side: the comment shows up, the indicator goes away, her draft stays
        await expect(page.getByText('Ready for review', { exact: true })).toBeVisible({ timeout: 10_000 });
        await expect(typingIndicator(page)).toHaveText('');
        await expect(commentBox(page)).toHaveText('Draft from Erin');

        // on a phone the header still fits, avatars included
        await memberPage.setViewportSize({ width: 390, height: 844 });
        await memberPage.reload();
        await expect(avatars(memberPage)).toBeVisible();
        expect(await memberPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no horizontal scroll').toBe(true);

        // Max leaves: the avatar goes
        await memberPage.goto(urls.home);
        await expect(avatars(page)).toBeHidden();
    });

    test('who’s here on a board, and on an issue opened in the board’s dialog', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);
        const title = uid('live-board');
        const id = await newIssue(page, title);

        await memberPage.goto(urls.board());
        // the board header shows the other person (Erin is already on the board after newIssue)
        await expect(avatars(page.locator('main'))).toHaveAttribute('aria-label', `Also here: ${USERS.member.name}`);
        await expect(avatars(memberPage.locator('main'))).toHaveAttribute('aria-label', `Also here: ${USERS.owner.name}`);

        // a tap lists the names (phones have no hover)
        await avatars(page.locator('main')).click();
        await expect(page.getByRole('dialog').getByText(USERS.member.name, { exact: true })).toBeVisible();
        await page.keyboard.press('Escape');

        // Max opens the issue in the dialog, Erin on its own page: both see each other there
        await memberPage.goto(`${urls.board()}#item-${id}`);
        const dialog = memberPage.getByRole('dialog', { name: /^Edit [A-Z]+-\d+:/ });
        await expect(dialog).toBeVisible();
        await page.goto(issueUrl(id));
        await expect(avatars(dialog)).toHaveAttribute('aria-label', `Also here: ${USERS.owner.name}`);
        await expect(avatars(page)).toHaveAttribute('aria-label', `Also here: ${USERS.member.name}`);
    });

    test('a colleague’s ticket reply appears in the open ticket panel', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);
        const desk = await createHelpdesk(page, uid('Live desk'));
        const subject = uid('Printer jam');
        await page.getByRole('button', { name: 'New ticket', exact: true }).click();
        const form = page.getByRole('dialog', { name: 'New ticket' });
        await form.getByPlaceholder('customer@example.com').fill(`${uid('caller')}@example.test`);
        const block = (label: string) => form.locator('div.space-y-1\\.5').filter({ has: page.locator('label', { hasText: new RegExp(`^${label}`) }) });
        await block('Subject').getByRole('textbox').fill(subject);
        await block('Message').getByRole('textbox').fill('Paper everywhere.');
        await form.getByRole('button', { name: 'Create ticket' }).click();
        await expect(form).toBeHidden();

        await ticketCard(page, subject).click();
        const erinPanel = ticketDialog(page);
        await expect(erinPanel.getByRole('heading', { level: 1, name: subject })).toBeVisible();

        await memberPage.goto(desk.url);
        await ticketCard(memberPage, subject).click();
        const maxPanel = ticketDialog(memberPage);
        await maxPanel.locator('[contenteditable="true"][aria-placeholder="Add a reply…"]').click();
        await memberPage.keyboard.type('Sent a technician.');
        await maxPanel.getByRole('button', { name: 'Reply', exact: true }).click();

        await expect(erinPanel.getByText('Sent a technician.', { exact: true })).toBeVisible({ timeout: 10_000 });
    });

    test('without live channels or live editing everything works as before', async ({ page }) => {
        // live channels opted in, live descriptions configured on DESC_PORT — but no server runs there yet
        await optIn(page, { collab: true });
        const id = await newIssue(page, uid('live-fallback'));
        await page.goto(issueUrl(id));

        await page.getByText('Add a description…').dblclick();
        await expect(page.getByText('Live editing unavailable — editing alone.')).toBeVisible({ timeout: 15_000 });
        const editor = page.locator('[contenteditable="true"][aria-placeholder="Add a description…"]');
        await editor.click();
        await page.keyboard.type('Written alone.');
        const saved = page.waitForResponse((r) => r.request().method() === 'PATCH' && r.url().endsWith(`/items/${id}`));
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await saved;

        // no live at all (no opt-in): no avatars, the single-user editor at once, the text persisted
        await page.context().setExtraHTTPHeaders({});
        await page.goto(issueUrl(id));
        await expect(page.getByText('Written alone.')).toBeVisible();
        await expect(avatars(page)).toHaveCount(0);
        await page.getByText('Written alone.').dblclick();
        await expect(page.getByRole('button', { name: 'Discard', exact: true })).toBeVisible();
        await expect(page.getByTestId('live-description')).toHaveCount(0);
    });

    test('two people co-edit an issue description and see each other’s text and caret', async ({ page, memberPage }) => {
        await startRealtime();
        await optIn(page, { collab: true });
        await optIn(memberPage, { collab: true });
        const id = await newIssue(page, uid('live-description'));

        await page.goto(issueUrl(id));
        await page.getByText('Add a description…').dblclick();
        await expect(liveDescription(page)).toBeVisible({ timeout: 15_000 });
        await typeAtEnd(liveDescription(page), 'Steps to reproduce.');

        await memberPage.goto(issueUrl(id));
        await memberPage.getByText('Add a description…').or(memberPage.getByText('Steps to reproduce.')).first().dblclick();
        await expect(liveDescription(memberPage)).toContainText('Steps to reproduce.', { timeout: 15_000 });

        // both directions, at once
        await typeAtEnd(liveDescription(memberPage), ' Max: on Safari only.');
        await expect(liveDescription(page)).toContainText('Max: on Safari only.');
        await typeAtEnd(liveDescription(page), ' Erin: confirmed.');
        await expect(liveDescription(memberPage)).toContainText('Erin: confirmed.');

        // carets carry names, and the editors list each other
        await expect(page.locator('.collab-cursors').getByText(USERS.member.name, { exact: true })).toBeVisible();
        await liveDescription(memberPage).click();
        await expect(memberPage.locator('.collab-cursors').getByText(USERS.owner.name, { exact: true })).toBeVisible();
        await expect(page.getByTestId('live-description').getByTestId('live-avatars')).toHaveAttribute('aria-label', `Editing with you: ${USERS.member.name}`);

        // Done: saved through the normal item update — the history has it, a reload shows the text
        await page.getByRole('button', { name: 'Done', exact: true }).click();
        await memberPage.getByRole('button', { name: 'Done', exact: true }).click();
        await expect(page.getByText('Add a description…')).toHaveCount(0);
        await page.reload();
        await expect(page.getByText(/Steps to reproduce\. Max: on Safari only\. Erin: confirmed\./)).toBeVisible();
        await page.getByRole('tab', { name: 'History' }).or(page.getByRole('button', { name: 'History', exact: true })).first().click();
        await expect(page.getByText(/description/i).first()).toBeVisible();
    });
});
