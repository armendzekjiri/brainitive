import type { Locator, Page, TestInfo } from '@playwright/test';

/**
 * The button crawler (BRAI-96): visits every page reachable through links and clicks every
 * button, tab, switch, checkbox and select on it — plus the items of every menu and the buttons
 * of every dialog those open. Form dialogs ("Create …") get their empty fields filled with test
 * values before the submit button is clicked, so create flows really run.
 * A click fails when the page throws, the server answers 5xx, or the control can't be clicked.
 * Data stays intact: requests that delete / sign out / revoke are answered with a harmless
 * reload instead of reaching the server (recorded as "blocked"), confirm() is cancelled, and
 * other origins are never contacted.
 */

export type ClickResult = {
    page: string;
    control: string;
    via?: string;
    outcome: 'ok' | 'navigated' | 'blocked' | 'failed' | 'skipped';
    detail?: string;
};

export const CONTROLS = [
    'button',
    '[role="button"]',
    '[role="tab"]',
    '[role="switch"]',
    '[role="checkbox"]',
    '[role="radio"]',
    '[role="combobox"]',
    '[role="menuitem"]',
    '[role="option"]',
    'select',
    'input[type="checkbox"]',
    'input[type="radio"]',
    'summary',
    'a[href="#"]',
].join(',');

/** Requests that would destroy shared data or end the session. */
const DESTRUCTIVE_PATH = /\/(logout|log-out|sign-out|sessions|leave|revoke|disconnect|destroy|delete|remove|wipe|reset|transfer)(\/|$|\?)/i;

/** Never followed: sign-out, third-party OAuth, downloads, non-page endpoints. */
const SKIP_LINK = /\/(logout|login\/(google|github)|calendar\/connections\/google|oauth|mcp|api|storage|broadcasting)(\/|$)|\.(pdf|zip|csv|png|jpe?g|svg|webp|ico|json|xml|txt)$|\/download|\/export/i;

/** Submit-like buttons inside a dialog: fill the form first. */
const SUBMIT = /^(create|save|add|submit|send|invite|publish|confirm|continue|next|done|apply|update|start|import|upload|post|reply)\b/i;

/** One visit per page type: ids, uuids, tokens and slugs (every workspace, project, board …) collapse. */
export const pattern = (url: URL) =>
    url.pathname
        .replace(/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=\/|$)/gi, '/:uuid')
        .replace(/\/\d+(?=\/|$)/g, '/:id')
        .replace(/\/(s|r|invite|changelog|f|support)\/[^/]+/, '/$1/:token')
        .replace(/\/(workspaces|projects|boards|task-lists|bookmarks|snippets|helpdesks|lead-forms|plans|releases|pages)\/(?!create\b|list\b|:)[^/]+/g, '/$1/:slug');

type Control = { index: number; name: string };
/** A control inside an open menu / dialog, found again by role + accessible name (positions shift). */
type InnerControl = { name: string; role: string; label: string };

export class Crawler {
    readonly results: ClickResult[] = [];
    private pageErrors: string[] = [];
    private serverErrors: string[] = [];
    private blocked: string[] = [];
    private fills = 0;
    private inflight = 0;

    constructor(private readonly page: Page, private readonly origin: string) {}

    async install() {
        const { page } = this;
        page.on('pageerror', (e) => this.pageErrors.push(e.message.split('\n')[0]));
        // own request bookkeeping: waitForLoadState('networkidle') returns at once on an already
        // loaded page, so it can't see an Inertia visit or a fetch a click started
        page.on('request', () => this.inflight++);
        page.on('requestfinished', () => (this.inflight = Math.max(0, this.inflight - 1)));
        page.on('requestfailed', () => (this.inflight = Math.max(0, this.inflight - 1)));
        page.on('framenavigated', () => (this.inflight = 0));
        page.on('dialog', (d) => d.dismiss().catch(() => {}));
        page.on('popup', (p) => p.close().catch(() => {}));
        page.on('response', (r) => {
            if (r.status() >= 500 && r.url().startsWith(this.origin)) this.serverErrors.push(`${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`);
        });
        await page.route('**/*', async (route) => {
            const request = route.request();
            if (/^(data|blob):/.test(request.url())) return route.fallback();
            const url = new URL(request.url());
            if (url.origin !== this.origin) return route.abort();
            const method = request.method();
            const spoofed = method === 'POST' && /(^|&|"|\b)_method"?\s*[=:]\s*"?delete/i.test(request.postData() ?? '');
            if (method === 'DELETE' || spoofed || (method !== 'GET' && DESTRUCTIVE_PATH.test(url.pathname))) {
                this.blocked.push(`${method} ${url.pathname}`);
                // Inertia: 409 + X-Inertia-Location = "reload this page"; plain fetches just see a 409
                return route.fulfill({ status: 409, headers: { 'X-Inertia-Location': page.url() }, body: '' });
            }
            // fallback, not continue: the context's asset cache (assets.ts) gets its turn
            return route.fallback();
        });
    }

    /** Breadth-first over same-origin links, one page per route pattern. */
    async crawl(start: string[], testInfo: TestInfo, maxPages = 200) {
        const queue = start.map((s) => new URL(s, this.origin).href);
        const seen = new Set<string>();
        let visited = 0;
        while (queue.length && visited < maxPages) {
            const href = queue.shift()!;
            const key = pattern(new URL(href));
            if (seen.has(key)) continue;
            seen.add(key);
            if (!(await this.open(href))) continue;
            visited++;
            for (const link of await this.links()) {
                if (!seen.has(pattern(new URL(link)))) queue.push(link);
            }
            await this.clickAll(href);
        }
        await testInfo.attach(`button-crawl-${testInfo.title.replace(/\W+/g, '-')}.json`, { body: JSON.stringify(this.results, null, 2), contentType: 'application/json' });
        return [...seen];
    }

    /** Every command of the command palette (⌘K, then ">"), each run once on `href`. */
    async commandPalette(href: string, testInfo: TestInfo) {
        const box = this.page.getByRole('combobox', { name: /search or type a command/i });
        const openPalette = async () => {
            await this.open(href);
            await this.page.locator('body').click({ position: { x: 1, y: 1 } }).catch(() => {});
            await this.page.keyboard.press(process.platform === 'darwin' ? 'Meta+k' : 'Control+k');
            if (!(await box.isVisible().catch(() => false))) {
                // the header search field opens the same palette
                await this.page.getByRole('searchbox', { name: /search/i }).first().click({ timeout: 3_000 }).catch(() => {});
            }
            await box.waitFor({ timeout: 5_000 });
            await box.fill('>');
            await this.page.getByRole('option').first().waitFor({ timeout: 5_000 }).catch(() => {});
        };
        await openPalette();
        const commands = await this.page.getByRole('option').allInnerTexts();
        const otherDialog = '[role="dialog"]:visible:not(:has([role="combobox"][aria-autocomplete])), [role="alertdialog"]:visible';
        const run = async (i: number) => {
            await openPalette();
            await this.page.getByRole('option').nth(i).click({ timeout: 3_000 });
            // the palette runs the highlighted command on Enter (a click only highlights it in some builds)
            if (await box.isVisible().catch(() => false)) await this.page.keyboard.press('Enter');
            await this.settle();
        };
        for (let i = 0; i < commands.length; i++) {
            const name = `command "${commands[i].replace(/\s+/g, ' ').trim().slice(0, 60)}"`;
            if (process.env.E2E_CRAWL_DEBUG) console.log(`palette ${i}: ${name}`);
            const before = this.page.url();
            this.reset();
            try {
                await run(i);
            } catch (e) {
                this.results.push({ page: href, control: name, via: 'command palette', outcome: 'failed', detail: `not runnable: ${(e as Error).message.split('\n')[0]}` });
                continue;
            }
            if (!this.record(href, name, 'command palette', before)) continue;
            // a command that opens a create dialog: fill + submit it too
            if (await this.page.locator(otherDialog).count()) await this.dialogPass(href, () => run(i), name);
            await this.restore(href);
        }
        await testInfo.attach('command-palette.json', { body: JSON.stringify(this.results.filter((r) => r.via === 'command palette' || r.via?.startsWith('command')), null, 2), contentType: 'application/json' });
    }

    async open(href: string): Promise<boolean> {
        this.reset();
        const response = await this.page.goto(href, { waitUntil: 'domcontentloaded' }).catch((e: Error) => {
            this.results.push({ page: href, control: '(page load)', outcome: 'failed', detail: e.message.split('\n')[0] });
            return null;
        });
        if (!response) return false;
        if (response.status() >= 400) {
            if (response.status() >= 500) this.results.push({ page: href, control: '(page load)', outcome: 'failed', detail: `HTTP ${response.status()}` });
            return false;
        }
        await this.settle();
        return this.check(href, '(page load)');
    }

    private async links(): Promise<string[]> {
        const hrefs = await this.page.$$eval('a[href]', (as) => as.map((a) => (a as HTMLAnchorElement).href));
        return [...new Set(hrefs)]
            .filter((h) => {
                try {
                    const u = new URL(h);
                    return u.origin === this.origin && !SKIP_LINK.test(u.pathname);
                } catch {
                    return false;
                }
            })
            .map((h) => h.split('#')[0]);
    }

    /** Every control on the page; menus and dialogs a control opens get one level of their own. */
    private async clickAll(href: string) {
        const controls = await this.tag();
        for (const control of controls) {
            const target = await this.find(href, control);
            if (!target) {
                this.results.push({ page: href, control: control.name, outcome: 'skipped', detail: 'gone after reload' });
                continue;
            }
            const opened = await this.click(href, target, control.name);
            const reopen = async () => {
                await this.open(href);
                const again = await this.find(href, control);
                if (again) await this.click(href, again, control.name, true);
            };
            if (opened === 'menu') await this.menuPass(href, reopen, control.name);
            if (opened === 'dialog') await this.dialogPass(href, reopen, control.name);
            await this.restore(href);
        }
    }

    /** Click every item of the menu `reopen` opens. */
    private async menuPass(href: string, reopen: () => Promise<void>, via: string) {
        const scope = '[role="menu"]:visible';
        const items = await this.inner(scope);
        for (const item of items) {
            await reopen();
            if ((await this.openState()) !== 'menu') return;
            const opened = await this.click(href, await this.innerLocator(scope, item), item.name, false, via);
            // a menu item that opens a dialog (Rename…, Create…): fill + submit it as well
            if (opened === 'dialog') await this.dialogPass(href, async () => {
                await reopen();
                await (await this.innerLocator(scope, item)).click({ timeout: 3_000 }).catch(() => {});
                await this.settle();
            }, `${via} › ${item.name}`);
        }
    }

    /** Click every button of the dialog `reopen` opens; submit-like ones after filling the form. */
    private async dialogPass(href: string, reopen: () => Promise<void>, via: string) {
        const scope = '[role="dialog"]:visible, [role="alertdialog"]:visible';
        const items = await this.inner(scope);
        for (const item of items) {
            await reopen();
            if ((await this.openState()) !== 'dialog') return;
            if (SUBMIT.test(item.label)) await this.fillForm(scope);
            await this.click(href, await this.innerLocator(scope, item), item.name, false, via);
        }
    }

    /** Fill every empty text-like field in `scope` with a valid test value. */
    private async fillForm(scope: string) {
        const fields = this.page.locator(scope).locator('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]):not([readonly]), textarea');
        const n = await fields.count();
        for (let i = 0; i < n; i++) {
            const field = fields.nth(i);
            if (!(await field.isVisible().catch(() => false)) || !(await field.isEditable().catch(() => false))) continue;
            if ((await field.inputValue().catch(() => 'x')) !== '') continue;
            const type = ((await field.getAttribute('type')) ?? 'text').toLowerCase();
            const name = `${(await field.getAttribute('name')) ?? ''} ${(await field.getAttribute('placeholder')) ?? ''}`.toLowerCase();
            const n = ++this.fills;
            const value =
                type === 'email' || name.includes('email') ? `crawl-${n}@example.test`
                : type === 'url' || name.includes('url') || name.includes('link') ? 'https://example.com/'
                : type === 'number' ? '1'
                : type === 'date' ? '2026-12-01'
                : type === 'time' ? '09:00'
                : type === 'color' ? '#3366ff'
                : type === 'password' ? 'crawl-Password-1!'
                : /\bkey\b/.test(name) ? `CR${String.fromCharCode(65 + (n % 26))}${String.fromCharCode(65 + ((n * 7) % 26))}`
                : `Crawl ${n}`;
            await field.fill(value).catch(() => {});
        }
    }

    private async tag(): Promise<Control[]> {
        return this.page.$$eval(CONTROLS, (els) =>
            els
                .filter((e) => (e as HTMLElement).checkVisibility?.() !== false && !(e as HTMLButtonElement).disabled && e.getAttribute('aria-disabled') !== 'true')
                .map((e, index) => {
                    const label = e.getAttribute('aria-label') || (e as HTMLElement).innerText || e.getAttribute('title') || e.getAttribute('name') || e.getAttribute('placeholder') || e.tagName.toLowerCase();
                    e.setAttribute('data-crawl', String(index));
                    return { index, name: `${e.getAttribute('role') || e.tagName.toLowerCase()} "${label.replace(/\s+/g, ' ').trim().slice(0, 60)}"` };
                }),
        );
    }

    /** The visible, enabled, named controls of an open menu / dialog (the newest one when nested). */
    private async inner(scope: string): Promise<InnerControl[]> {
        const list = await this.page.locator(scope).last().locator(CONTROLS).evaluateAll((els) =>
            els
                .filter((e) => (e as HTMLElement).checkVisibility?.() !== false && !(e as HTMLButtonElement).disabled && e.getAttribute('aria-disabled') !== 'true')
                .map((e) => {
                    const tag = e.tagName.toLowerCase();
                    const type = (e.getAttribute('type') ?? '').toLowerCase();
                    const role = e.getAttribute('role') || (tag === 'select' ? 'combobox' : tag === 'input' ? (type === 'radio' ? 'radio' : 'checkbox') : tag === 'a' ? 'link' : 'button');
                    const label = (e.getAttribute('aria-label') || (e as HTMLElement).innerText || e.getAttribute('title') || '').replace(/\s+/g, ' ').trim();
                    return { role, label, name: `${role} "${label.slice(0, 60)}"` };
                })
                .filter((c) => c.label !== ''),
        );
        // one entry per role + label (lists repeat e.g. "Remove" per row; the first one stands for them)
        return list.filter((c, i) => list.findIndex((d) => d.role === c.role && d.label === c.label) === i);
    }

    /** Find the item again in the (re)opened menu / dialog with the same label rule as inner(), and mark it. */
    private async innerLocator(scope: string, item: InnerControl): Promise<Locator> {
        await this.page.locator(scope).last().locator(CONTROLS).evaluateAll((els, wanted) => {
            document.querySelectorAll('[data-crawl-target]').forEach((e) => e.removeAttribute('data-crawl-target'));
            const match = els.find((e) => {
                const label = (e.getAttribute('aria-label') || (e as HTMLElement).innerText || e.getAttribute('title') || '').replace(/\s+/g, ' ').trim();
                return (e as HTMLElement).checkVisibility?.() !== false && label === wanted;
            });
            match?.setAttribute('data-crawl-target', '1');
        }, item.label);
        return this.page.locator('[data-crawl-target="1"]').first();
    }

    /**
     * The tagged control again. After a reload the positions can shift (a menu item that switched
     * the workspace changes the sidebar), so it's found by its name; the position only breaks ties.
     */
    private async find(href: string, control: Control): Promise<Locator | null> {
        const same = async () => {
            const tagged = await this.tag();
            const byName = tagged.filter((c) => c.name === control.name);
            if (!byName.length) return null;
            const best = byName.reduce((a, b) => (Math.abs(b.index - control.index) < Math.abs(a.index - control.index) ? b : a));
            return this.page.locator(`[data-crawl="${best.index}"]`).first();
        };
        const here = this.page.locator(`[data-crawl="${control.index}"]`);
        if ((await here.count()) && (await this.tagName(here)) === control.name) return here.first();
        const found = await same();
        if (found) return found;
        await this.open(href);
        return same();
    }

    private async tagName(loc: Locator): Promise<string> {
        return loc.first().evaluate((e) => {
            const label = e.getAttribute('aria-label') || (e as HTMLElement).innerText || e.getAttribute('title') || e.getAttribute('name') || e.getAttribute('placeholder') || e.tagName.toLowerCase();
            return `${e.getAttribute('role') || e.tagName.toLowerCase()} "${label.replace(/\s+/g, ' ').trim().slice(0, 60)}"`;
        }).catch(() => '');
    }

    /** Click, then classify: did it open a menu / dialog, navigate, get blocked, or break? */
    private async click(href: string, target: Locator, name: string, silent = false, via?: string): Promise<'menu' | 'dialog' | 'other' | 'failed'> {
        const before = this.page.url();
        this.reset();
        try {
            await target.click({ timeout: 4_000 });
        } catch (e) {
            if (!silent) this.results.push({ page: href, control: name, via, outcome: 'failed', detail: `not clickable: ${(e as Error).message.split('\n')[0]}` });
            return 'failed';
        }
        await this.settle();
        if (silent) return this.openState();
        if (!this.record(href, name, via, before)) return 'failed';
        return this.openState();
    }

    /** Record what the last action did; false when it broke something. */
    private record(href: string, name: string, via: string | undefined, before: string): boolean {
        if (!this.check(href, name, via)) return false;
        const navigated = new URL(this.page.url()).pathname !== new URL(before).pathname;
        const outcome = this.blocked.length ? 'blocked' : navigated ? 'navigated' : 'ok';
        this.results.push({ page: href, control: name, via, outcome, detail: this.blocked[0] ?? (navigated ? new URL(this.page.url()).pathname : undefined) });
        return true;
    }

    private async openState(): Promise<'menu' | 'dialog' | 'other'> {
        if (await this.page.locator('[role="menu"]:visible').count()) return 'menu';
        if (await this.page.locator('[role="dialog"]:visible, [role="alertdialog"]:visible').count()) return 'dialog';
        return 'other';
    }

    /** Back to a clean page: close what's open, or reload when the click changed too much. */
    private async restore(href: string) {
        await this.page.keyboard.press('Escape').catch(() => {});
        await this.page.keyboard.press('Escape').catch(() => {});
        const moved = new URL(this.page.url()).pathname !== new URL(href).pathname;
        if (moved || (await this.openState()) !== 'other') {
            await this.open(href);
            await this.tag();
        }
    }

    private check(href: string, name: string, via?: string): boolean {
        const problems = [...this.pageErrors.map((e) => `page error: ${e}`), ...this.serverErrors.map((e) => `server error: ${e}`)];
        if (!problems.length) return true;
        this.results.push({ page: href, control: name, via, outcome: 'failed', detail: problems.join(' | ') });
        return false;
    }

    private reset() {
        this.pageErrors = [];
        this.serverErrors = [];
        this.blocked = [];
    }

    /** Wait until the click's requests are done: no request in flight for 300 ms (max 6 s). */
    private async settle() {
        const deadline = Date.now() + 6_000;
        let quietSince = 0;
        await this.page.waitForTimeout(100);
        while (Date.now() < deadline) {
            if (this.inflight === 0) {
                quietSince ||= Date.now();
                if (Date.now() - quietSince >= 300) break;
            } else {
                quietSince = 0;
            }
            await this.page.waitForTimeout(50);
        }
        await this.page.waitForLoadState('domcontentloaded').catch(() => {});
    }
}

/** Human summary for the test output. */
export function summarize(results: ClickResult[]) {
    const count = (o: ClickResult['outcome']) => results.filter((r) => r.outcome === o).length;
    const pages = new Set(results.map((r) => r.page)).size;
    return `${pages} pages · ${results.length} controls · ok ${count('ok')} · navigated ${count('navigated')} · blocked (destructive) ${count('blocked')} · skipped ${count('skipped')} · failed ${count('failed')}`;
}
