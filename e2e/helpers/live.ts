import { spawn, type ChildProcess } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import type { Page } from '@playwright/test';
import { BASE_URL, COLLAB, LIVE, ROOT } from '../env';

/**
 * Live channels (BRAI-151) in e2e. global-setup starts one realtime server on LIVE.port for the run
 * (`startLiveServer`); specs opt a browser context in with `optInLive` and drive / read the tab's
 * live client through `window.__brainitiveLive` (exposed with APP_ENV=e2e only).
 */

const healthy = async () => {
    try {
        return (await fetch(`http://127.0.0.1:${LIVE.port}/`)).ok;
    } catch {
        return false;
    }
};

export const liveServerAvailable = () => fs.existsSync(path.join(ROOT, 'realtime', 'node_modules'));

/** Start the live server (unless one already answers on the port); returns the stop function. */
export async function startLiveServer(): Promise<() => Promise<void>> {
    if (!liveServerAvailable() || (await healthy())) return async () => {};
    const server: ChildProcess = spawn(process.execPath, [path.join(ROOT, 'realtime', 'src', 'index.js')], {
        cwd: ROOT,
        env: { ...process.env, COLLAB_PORT: String(LIVE.port), COLLAB_HOST: '127.0.0.1', COLLAB_SECRET: COLLAB.secret, COLLAB_APP_URL: BASE_URL },
        stdio: ['ignore', 'ignore', 'inherit'],
    });
    const end = Date.now() + 15_000;
    while (Date.now() < end && !(await healthy())) await new Promise((resolve) => setTimeout(resolve, 100));
    return async () => {
        if (server.exitCode === null) server.kill('SIGTERM');
    };
}

/** Live channels for this browser context (the app honours the header only with APP_ENV=e2e). */
export const optInLive = (page: Page) => page.context().setExtraHTTPHeaders({ 'X-E2E-Live': '1' });

interface LiveDebug {
    join(channel: string, presence?: boolean): void;
    leave(channel: string): void;
    status(channel: string): string;
    members(channel: string): string[];
    signal(channel: string, name: string, data: unknown): boolean;
    log: { events: { channel: string; event: string }[]; signals: { channel: string; name: string; data: unknown; from: { id: number; name: string } }[] };
}

type W = { __brainitiveLive: LiveDebug };

/**
 * The tab's live client, driven from a spec (no UI needed). Only works after a page load with
 * `optInLive` (the client exists once the bell subscribed).
 */
export const liveTab = (page: Page) => ({
    join: (channel: string, presence = false) => page.evaluate(([c, p]) => (window as unknown as W).__brainitiveLive.join(c, p), [channel, presence] as const),
    leave: (channel: string) => page.evaluate((c) => (window as unknown as W).__brainitiveLive.leave(c), channel),
    status: (channel: string) => page.evaluate((c) => (window as unknown as W).__brainitiveLive?.status(c) ?? 'missing', channel),
    members: (channel: string) => page.evaluate((c) => (window as unknown as W).__brainitiveLive.members(c), channel),
    signal: (channel: string, name: string, data: unknown) =>
        page.evaluate(([c, n, d]) => (window as unknown as W).__brainitiveLive.signal(c, n, d), [channel, name, data] as const),
    signals: () => page.evaluate(() => (window as unknown as W).__brainitiveLive.log.signals),
});
