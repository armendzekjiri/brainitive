import type { Page } from '@playwright/test';
import { expect, test, uid } from '../fixtures';
import { REMOTE } from '../env';
import { ok, seedAppToken } from '../helpers/app-api';
import { captureCard, openCaptures } from '../helpers/quick-capture';

/*
 * App API v1 offline sync (v1.49.0, BRAI-425), as the desktop app runs it after being offline: a full run of
 * the change feed, a batch from the outbox (applied once, a retry answers the stored result), the web inbox
 * showing the synced idea, and the next delta run carrying the change and, after a delete, its tombstone.
 * v2 (BRAI-441): results carry the feed's record, `captures=inbox` turns an archived capture into a delete.
 */

type Change = { type: string; id: string; op: string; data: { text?: string } | null };

/** A whole run of the feed (all pages) → its changes and the cursor to keep. */
async function run(page: Page, auth: Record<string, string>, query: string): Promise<{ changes: Change[]; cursor: string; reset: boolean }> {
    const changes: Change[] = [];
    let url = `/api/v1/sync/changes?${query}`;
    for (let pages = 0; pages < 20; pages++) {
        const body = await ok(await page.request.get(url, { headers: auth }));
        changes.push(...body.data);
        if (!body.has_more) return { changes, cursor: body.next_cursor, reset: body.reset };
        url = `/api/v1/sync/changes?cursor=${encodeURIComponent(body.next_cursor)}`;
    }
    throw new Error('the run did not end');
}

test.describe('app api: offline sync', () => {
    test.skip(REMOTE, 'the device seed is an e2e-only route (dev has no such route)');

    test('an outbox batch syncs once, shows on the web and comes back through the feed', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        const full = await run(page, auth, 'scopes=captures&limit=100');
        expect(full.reset).toBe(true);

        // the outbox: a capture made offline (+ one broken change that must not stop it)
        const idea = uid('Offline idea');
        const requestId = crypto.randomUUID();
        const batch = { changes: [{ request_id: requestId, type: 'capture.create', data: { text: idea } }, { type: 'capture.create', data: {} }] };
        const first = await ok(await page.request.post('/api/v1/sync', { headers: auth, data: batch }));
        expect(first.data.map((r: { status: string }) => r.status)).toEqual(['applied', 'invalid']);
        expect(first.data[0]).toMatchObject({ request_id: requestId, http_status: 200, replayed: false });

        // a retry after a lost answer: the stored result, nothing twice
        const retry = await ok(await page.request.post('/api/v1/sync', { headers: auth, data: { changes: [batch.changes[0]] } }));
        expect(retry.data[0]).toMatchObject({ status: 'applied', replayed: true });

        await openCaptures(page, idea);
        await expect(captureCard(page, idea)).toHaveCount(1);

        // the next delta run has it once
        const delta = await run(page, auth, `cursor=${encodeURIComponent(full.cursor)}`);
        expect(delta.reset).toBe(false);
        const synced = delta.changes.filter((c) => c.type === 'capture' && c.data?.text === idea);
        expect(synced).toHaveLength(1);
        const uuid = synced[0].id;

        // deleted (archive, then delete — as the web): the feed tells the device to drop it
        await ok(await page.request.post(`/api/v1/captures/${uuid}/archive`, { headers: auth }));
        expect((await page.request.delete(`/api/v1/captures/${uuid}`, { headers: auth })).status()).toBe(204);
        const after = await run(page, auth, `cursor=${encodeURIComponent(delta.cursor)}`);
        expect(after.changes).toContainEqual(expect.objectContaining({ type: 'capture', id: uuid, op: 'delete', data: null }));

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });

    // v2 (BRAI-441): results carry the feed's record, the inbox filter turns "left the inbox" into a delete
    test('results carry the feed record and the inbox filter drops archived captures', async ({ page }) => {
        const token = await seedAppToken(page);
        const auth = { Authorization: `Bearer ${token}` };

        const full = await ok(await page.request.get('/api/v1/sync/changes?scopes=captures,task_lists&captures=inbox&task_lists=mine&limit=100', { headers: auth }));
        expect(full.reset).toBe(true);
        expect(Array.isArray(full.task_lists)).toBe(true);
        let cursor: string = full.next_cursor;
        if (full.has_more) cursor = (await run(page, auth, `cursor=${encodeURIComponent(cursor)}`)).cursor;

        const idea = uid('Inbox idea');
        const result = await ok(await page.request.post('/api/v1/sync', { headers: auth, data: { changes: [{ request_id: crypto.randomUUID(), type: 'capture.create', data: { text: idea } }] } }));
        expect(result.data[0]).toMatchObject({ status: 'applied', record_type: 'capture', entity: { text: idea } });
        const uuid: string = result.data[0].entity.id;

        const delta = await run(page, auth, `cursor=${encodeURIComponent(cursor)}`);
        expect(delta.reset).toBe(false);
        expect(delta.changes).toContainEqual(expect.objectContaining({ type: 'capture', id: uuid, op: 'upsert' }));

        await ok(await page.request.post(`/api/v1/captures/${uuid}/archive`, { headers: auth }));
        const after = await run(page, auth, `cursor=${encodeURIComponent(delta.cursor)}`);
        expect(after.changes).toContainEqual(expect.objectContaining({ type: 'capture', id: uuid, op: 'delete', data: null }));

        await page.request.post('/api/v1/auth/logout', { headers: auth });
    });
});
