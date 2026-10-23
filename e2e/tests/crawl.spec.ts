import { cacheBuildAssets } from '../assets';
import { Crawler, summarize, type ClickResult } from '../crawler';
import { expect, test } from '@playwright/test';
import { urls } from '../fixtures';

/**
 * Every button (BRAI-96): the crawler clicks every control on every reachable page, every menu
 * item and dialog button behind them (form dialogs filled + submitted), and every command of the
 * command palette — and fails on any page error, 5xx or control that can't be clicked.
 * Runs as its own Playwright project after the journeys, on the data they created. (Plain
 * Playwright `test`: the crawler reports page errors itself, per control.)
 */
test.describe.configure({ mode: 'parallel' });

const failures = (results: ClickResult[]) =>
    results.filter((r) => r.outcome === 'failed').map((r) => `${r.page} › ${r.via ? `${r.via} › ` : ''}${r.control}: ${r.detail}`);

test.beforeEach(async ({ context }) => cacheBuildAssets(context));

test('every button in the app works (owner)', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(60 * 60_000);
    const crawler = new Crawler(page, new URL(baseURL!).origin);
    await crawler.install();
    const pages = await crawler.crawl([urls.home, urls.workspace(), urls.project(), urls.board(), '/my-work', '/calendar', '/notifications', '/profile', '/search'], testInfo);
    console.log(`owner crawl: ${summarize(crawler.results)}\n  pages: ${pages.join(', ')}`);
    expect(failures(crawler.results), 'controls that broke').toEqual([]);
});

test('every command in the command palette works', async ({ page, baseURL }, testInfo) => {
    test.setTimeout(30 * 60_000);
    const crawler = new Crawler(page, new URL(baseURL!).origin);
    await crawler.install();
    try {
        await crawler.commandPalette(new URL(urls.board(), baseURL).href, testInfo);
    } finally {
        console.log(`command palette: ${summarize(crawler.results)}`);
        if (process.env.E2E_CRAWL_DEBUG) console.log(JSON.stringify(crawler.results, null, 1));
    }
    expect(crawler.results.length, 'commands found').toBeGreaterThan(0);
    expect(failures(crawler.results), 'commands that broke').toEqual([]);
});

test('every button on the public pages works (guest)', async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(30 * 60_000);
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await cacheBuildAssets(context);
    const page = await context.newPage();
    const crawler = new Crawler(page, new URL(baseURL!).origin);
    await crawler.install();
    await crawler.crawl(['/', '/login', '/register', '/forgot-password', '/terms', '/privacy'], testInfo, 40);
    console.log(`guest crawl: ${summarize(crawler.results)}`);
    await context.close();
    expect(failures(crawler.results), 'controls that broke').toEqual([]);
});
