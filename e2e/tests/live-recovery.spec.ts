import { spawn, type ChildProcess } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs';
import path from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { expect, test, uid, urls } from '../fixtures';
import { BASE_URL, COLLAB, REMOTE, ROOT } from '../env';
import { addProjectContent, docEditor, docReader, draftSaved } from '../helpers/content';

/**
 * "Live editing unavailable — editing alone" says why and recovers (BRAI-345): documents and issue
 * descriptions with the realtime server down, refusing, or the session request failing — the notice
 * names the cause, edits keep saving alone, and live editing comes back (by itself, or with
 * "Try again" / "Switch to live editing") without losing what was written alone.
 * Own realtime server on REC_PORT (`X-E2E-Collab-Port`); serial: the tests stop / start it.
 */
test.describe.configure({ mode: 'serial' });

const REC_PORT = COLLAB.port + 5;
let server: ChildProcess | null = null;

async function startRealtime(secret: string = COLLAB.secret) {
    server = spawn(process.execPath, [path.join(ROOT, 'realtime', 'src', 'index.js')], {
        cwd: ROOT,
        env: { ...process.env, COLLAB_PORT: String(REC_PORT), COLLAB_HOST: '127.0.0.1', COLLAB_SECRET: secret, COLLAB_APP_URL: BASE_URL },
        stdio: ['ignore', 'ignore', 'ignore'],
    });
    const end = Date.now() + 15_000;
    while (Date.now() < end) {
        try {
            if ((await fetch(`http://127.0.0.1:${REC_PORT}/`)).ok) return;
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

/** Live editing on REC_PORT for this browser context (honoured only with APP_ENV=e2e). */
const optIn = (page: Page) => page.context().setExtraHTTPHeaders({ 'X-E2E-Collab': '1', 'X-E2E-Collab-Port': String(REC_PORT) });

const notice = (page: Page) => page.getByTestId('live-unavailable');
/** a live save of a document (PUT …/collab/draft) */
const liveDraftSaved = (page: Page) =>
    page.waitForResponse((r) => r.request().method() === 'PUT' && /\/pages\/[^/]+\/collab\/draft$/.test(new URL(r.url()).pathname) && r.ok());

async function typeAtEnd(editor: Locator, text: string) {
    await editor.click();
    await editor.page().keyboard.press('ControlOrMeta+End');
    await editor.page().keyboard.type(text);
}

/** A new issue in the seeded board; returns its id. */
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
const liveDescription = (page: Page) => page.getByTestId('live-description').locator('[contenteditable]').first();
const aloneDescription = (page: Page) => page.locator('[contenteditable="true"][aria-placeholder="Add a description…"]');

test.describe('live editing unavailable: why, and getting it back', () => {
    test.skip(REMOTE, 'starts the realtime server on this machine');
    test.skip(!fs.existsSync(path.join(ROOT, 'realtime', 'node_modules')), 'run `npm --prefix realtime ci` first');

    test.afterAll(stopRealtime);

    test('a server that refuses the connection is named as the cause', async ({ page }) => {
        await startRealtime('not-the-apps-secret'); // tokens signed by the app don't verify there
        await optIn(page);
        await addProjectContent(page, 'Page', uid('Refused doc'));
        await expect(notice(page)).toContainText('Live editing unavailable — editing alone.', { timeout: 20_000 });
        await expect(notice(page)).toContainText('The live editing server refused the connection (sign-in token rejected).');
        await stopRealtime();
    });

    test('document: server down → reason, saving alone; server back → live again by itself, text kept', async ({ page }) => {
        await optIn(page);
        await addProjectContent(page, 'Page', uid('Recovering doc'));
        await expect(notice(page)).toContainText('The live editing server can’t be reached', { timeout: 20_000 });
        await expect(notice(page)).toContainText('Retrying automatically…');

        // the single-user autosave keeps working
        const saved = draftSaved(page);
        await typeAtEnd(docEditor(page), 'Written alone.');
        await saved;

        // the server comes back: the background probe notices, and after a pause in typing it switches
        await startRealtime();
        await expect(notice(page)).toBeHidden({ timeout: 45_000 });
        await expect(docEditor(page)).toContainText('Written alone.', { timeout: 15_000 });

        // live now: changes are saved through the live document
        const liveSaved = liveDraftSaved(page);
        await typeAtEnd(docEditor(page), ' Then live.');
        await liveSaved;
        await page.reload();
        await expect(docReader(page)).toContainText('Written alone. Then live.');
    });

    test('issue description: text typed alone is saved when switching back to live editing', async ({ page }) => {
        await stopRealtime();
        await optIn(page);
        const id = await newIssue(page, uid('recovering-description'));
        await page.goto(issueUrl(id));
        await page.getByText('Add a description…').dblclick();
        await expect(notice(page)).toContainText('The live editing server can’t be reached', { timeout: 20_000 });

        await aloneDescription(page).click();
        await page.keyboard.type('Typed alone.');

        // back: it doesn't switch under the typed text, it offers to
        await startRealtime();
        await expect(notice(page)).toContainText('Live editing is available again', { timeout: 45_000 });
        await notice(page).getByRole('button', { name: 'Switch to live editing' }).click();
        await expect(liveDescription(page)).toContainText('Typed alone.', { timeout: 20_000 });

        await typeAtEnd(liveDescription(page), ' And live.');
        await page.getByRole('button', { name: 'Done', exact: true }).click();
        await expect(page.getByTestId('live-description')).toHaveCount(0);
        await page.reload();
        await expect(page.getByText('Typed alone. And live.')).toBeVisible();
    });

    test('issue description: a failed session request says so, and "Try again" really reconnects', async ({ page }) => {
        if (!server) await startRealtime();
        await optIn(page);
        const id = await newIssue(page, uid('session-retry'));
        await page.goto(issueUrl(id));

        const sessionUrl = `**/items/${id}/collab/session`;
        await page.route(sessionUrl, (route) => route.fulfill({ status: 503, body: '{}', contentType: 'application/json' }));
        await page.getByText('Add a description…').dblclick();
        await expect(notice(page)).toContainText('Brainitive couldn’t start live editing (HTTP 503).', { timeout: 20_000 });

        await page.unroute(sessionUrl);
        // (the background retry may already have switched by itself — nothing was typed)
        await notice(page).getByRole('button', { name: /Try again|Switch to live editing/ }).click({ timeout: 5_000 }).catch(() => undefined);
        await expect(page.getByTestId('live-description')).toBeVisible({ timeout: 20_000 });
        await expect(notice(page)).toHaveCount(0);
    });
});
