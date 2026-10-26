import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import WebSocket from 'ws';
import * as Y from 'yjs';
import { HocuspocusProvider, HocuspocusProviderWebsocket } from '@hocuspocus/provider';
import { createCollabServer } from '../src/server.js';
import { deriveKeys, signToken } from '../src/token.js';

const SECRET = 'node-test-secret';
const keys = deriveKeys(SECRET);
const DOC = 'page.123e4567-e89b-42d3-a456-426614174000.0';
const OTHER = 'page.123e4567-e89b-42d3-a456-426614174999.0';

/** Brainitive's internal API, in memory. */
const laravel = { states: new Map(), stale: new Set(), access: {}, calls: [], bearer: [] };
let api;
let collab;
let url;

before(async () => {
    api = createServer(async (request, response) => {
        let body = '';
        for await (const chunk of request) body += chunk;
        laravel.calls.push(`${request.method} ${request.url}`);
        laravel.bearer.push(request.headers.authorization);
        if (request.headers.authorization !== `Bearer ${keys.internal}`) {
            response.writeHead(401).end();
            return;
        }
        const name = decodeURIComponent(request.url.split('/documents/')[1] ?? '');
        const json = (status, data) => response.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(data));
        if (request.url.endsWith('/access')) {
            const { document, user_ids: ids } = JSON.parse(body);
            return json(200, { current: !laravel.stale.has(document), users: Object.fromEntries(ids.map((id) => [String(id), laravel.access[id] ?? null])) });
        }
        if (laravel.stale.has(name)) return json(409, { message: 'Stale document.' });
        if (request.method === 'GET') return json(200, { state: laravel.states.get(name) ?? null });
        laravel.states.set(name, JSON.parse(body).state);
        response.writeHead(204).end();
    });
    api.listen(0, '127.0.0.1');
    await once(api, 'listening');

    collab = createCollabServer({
        port: 0,
        secret: SECRET,
        appUrl: `http://127.0.0.1:${api.address().port}`,
        accessIntervalMs: 3_600_000, // driven by hand below
        debounce: 50,
        maxDebounce: 200,
        stopOnSignals: false,
        logger: { error() {} },
    });
    await collab.listen();
    url = `ws://127.0.0.1:${collab.server.address.port}`;
});

after(async () => {
    await collab.destroy();
    collab.server.httpServer.closeAllConnections();
    api.closeAllConnections();
    api.close();
});

const token = (doc, edit, sub = 1, exp = Math.floor(Date.now() / 1000) + 60) => signToken({ v: 1, sub, name: `User ${sub}`, doc, edit, exp }, keys.token);

function connect(name, tokenValue) {
    const websocketProvider = new HocuspocusProviderWebsocket({ url, WebSocketPolyfill: WebSocket, maxAttempts: 1 });
    const provider = new HocuspocusProvider({ name, token: tokenValue, websocketProvider });
    provider.attach();
    const events = { failed: null, synced: false, closed: [] };
    provider.on('authenticationFailed', ({ reason }) => { events.failed = reason; });
    provider.on('synced', () => { events.synced = true; });
    // the server closes one document of a shared socket with a CLOSE message: code 1000 + reason
    provider.on('close', ({ event }) => events.closed.push(event?.reason));
    const close = () => { provider.destroy(); websocketProvider.destroy(); };
    return { provider, doc: provider.document, events, close };
}

const until = async (check, ms = 3000) => {
    const end = Date.now() + ms;
    while (!check()) {
        if (Date.now() > end) throw new Error('timed out');
        await new Promise((r) => setTimeout(r, 20));
    }
};

test('listens on COLLAB_HOST only (127.0.0.1 by default), not on every interface', () => {
    assert.equal(collab.server.httpServer.address().address, '127.0.0.1');
});

test('refuses connections without a valid token for exactly this document', async () => {
    for (const bad of ['nonsense', token(OTHER, true), token(DOC, true, 1, 1), signToken({ v: 1, sub: 1, name: 'x', doc: DOC, edit: true, exp: 9e9 }, 'wrong-key')]) {
        const client = connect(DOC, bad);
        await until(() => client.events.failed !== null);
        assert.equal(client.events.synced, false);
        client.close();
    }
});

test('loads the stored state, shares edits live and stores them through Brainitive', async () => {
    const seed = new Y.Doc();
    seed.getText('t').insert(0, 'stored ');
    laravel.states.set(DOC, Buffer.from(Y.encodeStateAsUpdate(seed)).toString('base64'));

    const a = connect(DOC, token(DOC, true, 1));
    const b = connect(DOC, token(DOC, true, 2));
    await until(() => a.events.synced && b.events.synced);
    assert.equal(a.doc.getText('t').toString(), 'stored ');

    a.doc.getText('t').insert(7, 'by A');
    await until(() => b.doc.getText('t').toString() === 'stored by A');

    await until(() => {
        const stored = new Y.Doc();
        Y.applyUpdate(stored, Buffer.from(laravel.states.get(DOC), 'base64'));
        return stored.getText('t').toString() === 'stored by A';
    });
    assert.ok(laravel.bearer.every((h) => h === `Bearer ${keys.internal}`), 'internal key on every call, never a browser token');
    a.close();
    b.close();
});

test('view-only connections see changes but cannot change the document', async () => {
    const editor = connect(DOC, token(DOC, true, 1));
    const viewer = connect(DOC, token(DOC, false, 3));
    await until(() => editor.events.synced && viewer.events.synced);

    viewer.doc.getText('t').insert(0, 'HACK ');
    editor.doc.getText('t').insert(editor.doc.getText('t').length, '!');
    await until(() => viewer.doc.getText('t').toString().endsWith('!'));
    await new Promise((r) => setTimeout(r, 300));
    assert.equal(editor.doc.getText('t').toString().includes('HACK'), false);
    editor.close();
    viewer.close();
});

test('the access re-check disconnects people who lost access and closes replaced documents', async () => {
    laravel.access = { 1: 'edit', 4: null };
    const kept = connect(DOC, token(DOC, true, 1));
    const removed = connect(DOC, token(DOC, true, 4));
    await until(() => kept.events.synced && removed.events.synced);

    await collab.recheckAccess();
    await until(() => removed.events.closed.includes('Forbidden'));
    assert.equal(kept.events.closed.length, 0);

    // the draft was replaced in Brainitive (discard / restore): everyone on the old document leaves
    laravel.stale.add(DOC);
    await collab.recheckAccess();
    await until(() => kept.events.closed.includes('Document replaced'));
    kept.close();
    removed.close();
    laravel.stale.delete(DOC);
});

test('a stale document is not loaded', async () => {
    laravel.stale.add(OTHER);
    const client = connect(OTHER, token(OTHER, true, 1));
    await new Promise((r) => setTimeout(r, 400));
    assert.equal(client.events.synced, false);
    client.close();
    laravel.stale.delete(OTHER);
});
