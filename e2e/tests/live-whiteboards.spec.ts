import { spawn, type ChildProcess } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs';
import path from 'node:path';
import type { Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { BASE_URL, COLLAB, REMOTE, ROOT, USERS } from '../env';
import { addProjectContent } from '../helpers/content';

/** Its own realtime server (live-docs.spec.ts owns COLLAB.port and stops / restarts it in parallel). */
const WB_PORT = COLLAB.port + 3;

/**
 * Live whiteboards (BRAI-153): two people draw on the same Excalidraw scene through the realtime
 * server (realtime/, started here on WB_PORT like live-docs.spec.ts) and see each other's shapes
 * and named cursors. Contexts opt in with `X-E2E-Collab: 1`; every other whiteboard journey keeps the
 * single-user editor. Serial: one server, and two tests stop / restart it.
 */
test.describe.configure({ mode: 'serial' });

let server: ChildProcess | null = null;

async function startRealtime() {
    server = spawn(process.execPath, [path.join(ROOT, 'realtime', 'src', 'index.js')], {
        cwd: ROOT,
        env: {
            ...process.env,
            COLLAB_PORT: String(WB_PORT),
            COLLAB_HOST: '127.0.0.1',
            COLLAB_SECRET: COLLAB.secret,
            COLLAB_APP_URL: BASE_URL,
        },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    const end = Date.now() + 15_000;
    while (Date.now() < end) {
        try {
            if ((await fetch(`http://127.0.0.1:${WB_PORT}/`)).ok) return;
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

const optIn = (page: Page) => page.context().setExtraHTTPHeaders({ 'X-E2E-Collab': '1', 'X-E2E-Collab-Port': String(WB_PORT) });

type Probe = { types: string[]; texts: string[]; cards: number; collaborators: { username: string; pointer: boolean }[]; mode: string | null };

/** What this tab's canvas holds (shape types), whom it shows (cursors) and the live mode. */
const probe = (page: Page) => page.evaluate((): Probe => {
    const board = (window as unknown as { BrainitiveWhiteboard?: { canvas: any; live: () => any } }).BrainitiveWhiteboard;
    if (!board) return { types: [], texts: [], cards: 0, collaborators: [], mode: null };
    const collaborators = board.canvas.raw()?.getAppState().collaborators ?? new Map();
    const elements = board.canvas.getSceneElements() as { type: string; text?: string; originalText?: string; customData?: { brainitive?: string } }[];
    return {
        types: elements.map((element) => element.type),
        texts: elements.filter((element) => element.type === 'text').map((element) => element.originalText ?? element.text ?? ''),
        cards: elements.filter((element) => element.customData?.brainitive === 'issue').length,
        collaborators: [...collaborators.values()].map((c: { username?: string; pointer?: unknown }) => ({ username: c.username ?? '', pointer: !!c.pointer })),
        mode: board.live()?.getState().mode ?? null,
    };
});

/** Draw a shape with a keyboard tool (r = rectangle, o = ellipse, d = diamond) at an offset from the centre. */
async function draw(page: Page, tool: string, dx: number, dy: number) {
    const canvas = page.locator('canvas.excalidraw__canvas.interactive');
    const box = (await canvas.boundingBox())!;
    const x = box.x + box.width / 2 + dx;
    const y = box.y + box.height / 2 + dy;
    await page.mouse.click(x, y); // focus the canvas (an empty spot: nothing gets selected)
    await page.keyboard.press(tool);
    await page.mouse.move(x - 60, y - 40);
    await page.mouse.down();
    await page.mouse.move(x + 60, y + 40, { steps: 8 });
    await page.mouse.up();
}

/** Create a whiteboard (opens its editor), publish it so the project's other editor can open it. */
async function newWhiteboard(page: Page): Promise<string> {
    await addProjectContent(page, 'Whiteboard', uid('Live board'));
    await expect(page).toHaveURL(/\/excalidraw/);
    const templates = page.locator('#templatesModal');
    await expect(templates.getByRole('heading', { name: 'Start from a template' })).toBeVisible();
    await templates.getByRole('button', { name: 'Close' }).click();
    const editorUrl = page.url().replace(/\?.*$/, '');
    const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
    const published = await page.request.post(editorUrl.replace(/\/excalidraw$/, '/publish'), { headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' } });
    expect(published.ok()).toBeTruthy();
    return editorUrl;
}

/** The stored whiteboard (what everybody else, the history and search read) as the editor config. */
const storedScene = async (page: Page, editorUrl: string) => (await page.request.get(editorUrl)).text();

test.describe('live whiteboards', () => {
    test.skip(REMOTE, 'starts the realtime server on this machine');
    test.skip(!fs.existsSync(path.join(ROOT, 'realtime', 'node_modules')), 'run `npm --prefix realtime ci` first');

    test.beforeAll(startRealtime);
    test.afterAll(stopRealtime);

    test('two people draw together and see each other’s shapes and named cursors', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);

        const editorUrl = await newWhiteboard(page);
        await expect.poll(async () => (await probe(page)).mode).toBe('live');
        await memberPage.goto(editorUrl);
        await expect.poll(async () => (await probe(memberPage)).mode).toBe('live');

        // both see who's drawing with them
        await expect(page.getByRole('list', { name: 'Drawing live with you' }).getByRole('listitem', { name: USERS.member.name })).toBeVisible();
        await expect(memberPage.getByRole('list', { name: 'Drawing live with you' }).getByRole('listitem', { name: USERS.owner.name })).toBeVisible();

        // Erin draws a rectangle: it appears on Max's canvas
        await draw(page, 'r', -150, -60);
        await expect.poll(async () => (await probe(memberPage)).types, { timeout: 15_000 }).toContain('rectangle');

        // Max draws an ellipse: it appears on Erin's
        await draw(memberPage, 'o', 150, 60);
        await expect.poll(async () => (await probe(page)).types, { timeout: 15_000 }).toContain('ellipse');

        // Erin's cursor, with her name, on Max's canvas (and Excalidraw's avatar list)
        const canvas = (await page.locator('canvas.excalidraw__canvas.interactive').boundingBox())!;
        await page.mouse.move(canvas.x + 40, canvas.y + 200, { steps: 4 });
        await expect.poll(async () => (await probe(memberPage)).collaborators, { timeout: 10_000 })
            .toContainEqual({ username: USERS.owner.name, pointer: true });
        await expect(memberPage.locator('.excalidraw .UserList__collaborator').first()).toBeVisible();

        // the scene is stored in Brainitive (both shapes), status settles on "All changes saved"
        await expect.poll(async () => {
            const stored = await storedScene(page, editorUrl);
            return stored.includes('"type":"rectangle"') && stored.includes('"type":"ellipse"');
        }, { timeout: 20_000 }).toBe(true);
        await expect(page.locator('#saveStatus')).toHaveText('All changes saved');

        // someone reading the page watches the drawing live in the preview
        await memberPage.goto(editorUrl.replace(/\/excalidraw$/, ''));
        await expect(memberPage.getByRole('status').filter({ hasText: /^Live$/ })).toBeVisible({ timeout: 15_000 });
        await expect(memberPage.getByRole('list', { name: 'Drawing live now' }).getByRole('listitem', { name: `${USERS.owner.name}, drawing` })).toBeVisible();
    });

    test('a shape turned into an issue card becomes the card on the other canvas too', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);

        const editorUrl = await newWhiteboard(page);
        await expect.poll(async () => (await probe(page)).mode).toBe('live');
        await memberPage.goto(editorUrl);
        await expect.poll(async () => (await probe(memberPage)).mode).toBe('live');

        // Erin writes a sticky (a rectangle with a label), moves it, so it's past version 1 everywhere
        const title = uid('Live card');
        await draw(page, 'r', 0, 0);
        await page.keyboard.press('Enter');
        await page.keyboard.type(title);
        await page.keyboard.press('Escape');
        await page.keyboard.press('ArrowRight');
        await expect.poll(async () => (await probe(memberPage)).texts, { timeout: 15_000 }).toContain(title);

        // … and turns it into an issue
        await page.getByRole('button', { name: 'Issues' }).click();
        const dialog = page.locator('#issuesModal');
        await dialog.getByRole('button', { name: 'Create issue' }).click();
        await expect(dialog).toBeHidden();

        // Max's canvas shows the card (same element, newer version) and not the old label
        await expect.poll(async () => (await probe(memberPage)).cards, { timeout: 15_000 }).toBe(1);
        await expect.poll(async () => (await probe(page)).cards).toBe(1);
        await expect.poll(async () => (await probe(memberPage)).texts.filter((text) => text === title)).toEqual([]);
    });

    test('drawings made while the server is down are kept and merged when it comes back', async ({ page, memberPage }) => {
        await optIn(page);
        await optIn(memberPage);

        const editorUrl = await newWhiteboard(page);
        await memberPage.goto(editorUrl);
        await expect.poll(async () => (await probe(page)).mode).toBe('live');
        await expect.poll(async () => (await probe(memberPage)).mode).toBe('live');

        // the realtime server dies: both keep drawing on their own device
        await stopRealtime();
        await expect(page.locator('#saveStatus')).toHaveText('Offline — changes kept on this device', { timeout: 15_000 });
        await draw(page, 'r', -150, -60);
        await draw(memberPage, 'o', 150, 60);
        await expect.poll(async () => (await probe(page)).types).toContain('rectangle');
        expect((await probe(page)).types).not.toContain('ellipse');

        // Erin even reloads while it's down: her rectangle comes back from this browser's IndexedDB
        await page.reload();
        await expect.poll(async () => (await probe(page)).types, { timeout: 15_000 }).toContain('rectangle');

        // back up: both drawings merge on both canvases and in the stored whiteboard
        await startRealtime();
        await expect.poll(async () => (await probe(page)).types, { timeout: 30_000 }).toContain('ellipse');
        await expect.poll(async () => (await probe(memberPage)).types, { timeout: 30_000 }).toContain('rectangle');
        await expect.poll(async () => {
            const stored = await storedScene(page, editorUrl);
            return stored.includes('"type":"rectangle"') && stored.includes('"type":"ellipse"');
        }, { timeout: 30_000 }).toBe(true);
    });

    test('without the realtime server the whiteboard falls back to drawing alone', async ({ page }) => {
        await stopRealtime();
        await optIn(page);

        const editorUrl = await newWhiteboard(page);
        await expect(page.getByText('Live drawing unavailable — drawing alone.')).toBeVisible({ timeout: 15_000 });

        // today's autosave (POST …/excalidraw/save with the revision check) keeps working
        const saved = page.waitForResponse((r) => r.url().endsWith('/excalidraw/save') && r.ok());
        await draw(page, 'r', 0, 0);
        await saved;
        await expect(page.locator('#saveStatus')).toHaveText('All changes saved');
        expect(await storedScene(page, editorUrl)).toContain('"type":"rectangle"');
    });
});
