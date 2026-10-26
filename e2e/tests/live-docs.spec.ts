import { spawn, type ChildProcess } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs';
import path from 'node:path';
import type { Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { BASE_URL, COLLAB, REMOTE, ROOT, USERS } from '../env';
import { addProjectContent, docEditor, docReader, draftSaved, publish } from '../helpers/content';

/**
 * Live co-editing of documents (BRAI-54). This spec runs its own realtime server (realtime/) on
 * COLLAB.port and opts its browser contexts in with `X-E2E-Collab: 1`; every other journey keeps the
 * single-user editor. Serial: one server, and two tests stop / restart it.
 */
test.describe.configure({ mode: 'serial' });

let server: ChildProcess | null = null;

async function startRealtime() {
    server = spawn(process.execPath, [path.join(ROOT, 'realtime', 'src', 'index.js')], {
        cwd: ROOT,
        env: {
            ...process.env,
            COLLAB_PORT: String(COLLAB.port),
            COLLAB_HOST: '127.0.0.1',
            COLLAB_SECRET: COLLAB.secret,
            COLLAB_APP_URL: BASE_URL,
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    const end = Date.now() + 15_000;
    while (Date.now() < end) {
        try {
            if ((await fetch(`http://127.0.0.1:${COLLAB.port}/`)).ok) return;
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
    server.kill('SIGKILL'); // like a crash: nothing gets a chance to save on the way out
    await exited;
    server = null;
}

/** Live editing for this browser context (the app honours the header only with APP_ENV=e2e). */
const optIn = (page: Page) => page.context().setExtraHTTPHeaders({ 'X-E2E-Collab': '1' });

/** The "Saved" / "Draft saved" status of the live document. */
const liveSaved = (page: Page) => page.getByRole('status').filter({ hasText: /^(Draft saved|Saved)$/ });

async function typeAtEnd(page: Page, text: string) {
    const editor = docEditor(page);
    await editor.click();
    await page.keyboard.press('ControlOrMeta+End');
    await page.keyboard.type(text);
}

test.describe('live co-editing of documents', () => {
    test.skip(REMOTE, 'starts the realtime server on this machine');
    test.skip(!fs.existsSync(path.join(ROOT, 'realtime', 'node_modules')), 'run `npm --prefix realtime ci` first');

    test.beforeAll(startRealtime);
    test.afterAll(stopRealtime);

    test('two people edit the same document and see each other’s text and caret', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);

        // Erin writes a doc and publishes it, so Max (project editor) can open it
        const name = uid('Live doc');
        await addProjectContent(page, 'Page', name);
        await typeAtEnd(page, 'Agenda for Monday.');
        await expect(liveSaved(page)).toBeVisible();
        await publish(page);
        const url = page.url();

        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        await expect(docEditor(page)).toContainText('Agenda for Monday.');

        await memberPage.goto(url);
        await memberPage.getByRole('button', { name: 'Edit', exact: true }).click();
        await expect(docEditor(memberPage)).toContainText('Agenda for Monday.');

        // both are in the header of the other one
        await expect(page.getByRole('list', { name: 'Editing live with you' }).getByRole('listitem', { name: USERS.member.name })).toBeVisible();
        await expect(memberPage.getByRole('list', { name: 'Editing live with you' }).getByRole('listitem', { name: USERS.owner.name })).toBeVisible();

        // changes appear instantly on the other side, in both directions
        await typeAtEnd(page, ' Erin: budget review.');
        await expect(docEditor(memberPage)).toContainText('Erin: budget review.');
        await typeAtEnd(memberPage, ' Max: hiring update.');
        await expect(docEditor(page)).toContainText('Max: hiring update.');
        await expect(docEditor(page)).toContainText('Agenda for Monday. Erin: budget review. Max: hiring update.');

        // Max's caret, with his name, in Erin's editor (and the other way round)
        await expect(page.locator('.collab-cursors').getByText(USERS.member.name, { exact: true })).toBeVisible();
        await docEditor(page).click();
        await expect(memberPage.locator('.collab-cursors').getByText(USERS.owner.name, { exact: true })).toBeVisible();

        // a live title change shows up on the other side
        const title = `${name} (live)`;
        await page.getByLabel('Page title').fill(title);
        await page.getByLabel('Page title').press('Enter');
        await expect(memberPage.getByLabel('Page title')).toHaveValue(title);

        // Max publishes: everybody's text goes live (draft → publish → version as before)
        await expect(liveSaved(memberPage)).toBeVisible();
        const published = memberPage.waitForResponse((r) => r.request().method() === 'PUT' && r.url().endsWith('/publish-changes'));
        await memberPage.getByRole('button', { name: 'Update', exact: true }).click();
        await published;
        await expect(memberPage.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
        await expect(docReader(memberPage)).toContainText('Agenda for Monday. Erin: budget review. Max: hiring update.');
        await expect(memberPage.getByRole('heading', { level: 1, name: title })).toBeVisible();
    });

    test('edits made while the server is down are kept and merged when it comes back', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);

        const name = uid('Offline doc');
        await addProjectContent(page, 'Page', name);
        await typeAtEnd(page, 'Shared start.');
        await expect(liveSaved(page)).toBeVisible();
        await publish(page);
        const url = page.url();
        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        await memberPage.goto(url);
        await memberPage.getByRole('button', { name: 'Edit', exact: true }).click();
        await expect(docEditor(memberPage)).toContainText('Shared start.');

        // the realtime server dies: both keep writing, on their own device
        await stopRealtime();
        await expect(page.getByText('Offline — changes kept on this device')).toBeVisible();
        await typeAtEnd(page, ' Erin offline.');
        await typeAtEnd(memberPage, ' Max offline.');
        await expect(docEditor(page)).not.toContainText('Max offline.');

        // Erin even reloads while it's down: her text comes back from this browser's IndexedDB
        await page.reload();
        await page.getByRole('button', { name: /^(Continue editing|Edit)$/ }).first().click();
        await expect(docEditor(page)).toContainText('Erin offline.');

        // back up: both versions merge, nothing is lost
        await startRealtime();
        await expect(docEditor(page)).toContainText('Max offline.', { timeout: 20_000 });
        await expect(docEditor(memberPage)).toContainText('Erin offline.', { timeout: 20_000 });
        await expect(liveSaved(page)).toBeVisible();

        // and the draft (what Update publishes) has both
        const closed = page.waitForResponse((r) => r.url().includes('/pages/') && r.request().method() === 'GET');
        await page.getByRole('button', { name: 'Close', exact: true }).click();
        await closed;
        await page.getByRole('button', { name: 'Continue editing' }).first().click();
        await expect(docEditor(page)).toContainText('Erin offline.');
        await expect(docEditor(page)).toContainText('Max offline.');
    });

    test('mentions, smart links, polls and sticky notes survive live editing and publishing', async ({ page, browser, ownerState }) => {
        // a draft written with the single-user editor (no opt-in yet), stored as HTML
        await addProjectContent(page, 'Page', uid('Blocks doc'));
        const url = page.url();
        const uuid = /\/pages\/([0-9a-f-]{36})/.exec(url)![1];
        const caption = { root: { children: [{ children: [{ detail: 0, format: 0, mode: 'normal', style: '', text: 'Remember the milk', type: 'text', version: 1 }], direction: null, format: '', indent: 0, type: 'paragraph', version: 1, textFormat: 0, textStyle: '' }], direction: null, format: '', indent: 0, type: 'root', version: 1 } };
        const poll = { question: 'Lunch?', options: [{ uid: 'a', text: 'Pizza', votes: [] }, { uid: 'b', text: 'Sushi', votes: [] }], pollId: 'poll-live' };
        const attr = (value: unknown) => JSON.stringify(value).replace(/&/g, '&amp;').replace(/'/g, '&#39;');
        const html = `<p>Intro <span data-mention-user-id="1" data-mention-name="${USERS.member.name}">@${USERS.member.name}</span> see <a href="${url}" data-smart-link="page:${uuid}" data-smart-link-view="chip">this doc</a></p>`
            + `<div data-lexical-poll="true" data-poll-state='${attr(poll)}'><p><strong>Lunch?</strong></p></div>`
            + `<div data-lexical-sticky='${attr({ color: 'yellow', xOffset: 40, yOffset: 40, width: 220, fontSize: 20, caption: { editorState: caption } })}' style="display:none"></div>`
            + '<p>Last line</p>';
        const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
        const stored = await page.request.put(`${url}/draft`, { data: { content: JSON.stringify({ type: 'lexical', html }) }, headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' } });
        expect(stored.ok()).toBeTruthy();

        // opened live: the draft is loaded into the shared document once, a second window gets it through Yjs
        await optIn(page);
        await page.reload();
        const second = await browser.newContext({ storageState: ownerState, extraHTTPHeaders: { 'X-E2E-Collab': '1' } });
        const other = await second.newPage();
        await other.goto(url);
        for (const view of [page, other]) {
            const editor = docEditor(view);
            await expect(editor).toContainText(`@${USERS.member.name}`);
            await expect(editor.locator(`[data-smart-link-chip="page:${uuid}"]`)).toBeVisible();
            await expect(editor.getByText('Lunch?')).toBeVisible();
            await expect(view.locator('.sticky-note-container')).toContainText('Remember the milk');
        }

        // a live edit, then publish: the published HTML still has every block
        const lastLine = docEditor(page).locator('p', { hasText: 'Last line' });
        const box = (await lastLine.boundingBox())!;
        await lastLine.click({ position: { x: box.width - 2, y: box.height / 2 } }); // the end of that line
        await page.keyboard.type(' (edited live)');
        await expect(docEditor(other)).toContainText('Last line (edited live)');
        await second.close();
        await publish(page);

        const version = await (await page.request.get(`${url}/versions/1`, { headers: { Accept: 'application/json' } })).json();
        const published: string = version.content.html;
        expect(published).toContain(`data-mention-user-id="1"`);
        expect(published).toContain(`data-smart-link="page:${uuid}"`);
        expect(published).toContain('data-poll-state=');
        expect(published).toContain('Pizza');
        expect(published).toContain('data-lexical-sticky=');
        expect(published).toContain('Remember the milk');
        expect(published).toContain('Last line (edited live)');
    });

    test('without the realtime server documents fall back to single-user editing', async ({ page }) => {
        await stopRealtime();
        await optIn(page);

        await addProjectContent(page, 'Page', uid('Fallback doc'));
        await expect(page.getByText('Live editing unavailable — editing alone.')).toBeVisible({ timeout: 15_000 });

        // today's autosave (PUT …/draft with the revision check) keeps working
        const saved = draftSaved(page);
        await typeAtEnd(page, 'Written alone.');
        await saved;
        await page.reload();
        await expect(docReader(page)).toContainText('Written alone.');
    });
});
