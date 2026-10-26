// Live issue descriptions (BRAI-151): `item.<id>.<epoch>` documents go through the same server as
// documents — tokens, read-only viewers and persistence are kind-agnostic.
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import WebSocket from 'ws';
import { HocuspocusProvider, HocuspocusProviderWebsocket } from '@hocuspocus/provider';
import { createCollabServer } from '../src/server.js';
import { DOCUMENT_NAME, deriveKeys, signToken } from '../src/token.js';

const SECRET = 'node-item-secret';
const keys = deriveKeys(SECRET);
const ITEM = 'item.42.3';

const laravel = { states: new Map(), access: { 1: 'edit', 2: 'edit', 3: 'view' } };
let api;
let collab;
let url;

before(async () => {
    api = createServer(async (request, response) => {
        let body = '';
        for await (const chunk of request) body += chunk;
        if (request.headers.authorization !== `Bearer ${keys.internal}`) {
            response.writeHead(401).end();
            return;
        }
        const json = (status, data) => response.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(data));
        if (request.url.endsWith('/access')) {
            const { user_ids: ids } = JSON.parse(body);
            return json(200, { current: true, users: Object.fromEntries(ids.map((id) => [String(id), laravel.access[id] ?? null])) });
        }
        const name = decodeURIComponent(request.url.split('/documents/')[1] ?? '');
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
        accessIntervalMs: 3_600_000,
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

const token = (doc, edit, sub) => signToken({ v: 1, sub, name: `User ${sub}`, doc, edit, exp: Math.floor(Date.now() / 1000) + 60 }, keys.token);

function connect(name, tokenValue) {
    const websocketProvider = new HocuspocusProviderWebsocket({ url, WebSocketPolyfill: WebSocket, maxAttempts: 1 });
    const provider = new HocuspocusProvider({ name, token: tokenValue, websocketProvider });
    provider.attach();
    const events = { failed: null, synced: false };
    provider.on('authenticationFailed', ({ reason }) => { events.failed = reason; });
    provider.on('synced', () => { events.synced = true; });
    return { provider, doc: provider.document, events, close: () => { provider.destroy(); websocketProvider.destroy(); } };
}

const until = async (check, ms = 3000) => {
    const end = Date.now() + ms;
    while (!check()) {
        if (Date.now() > end) throw new Error('timed out');
        await new Promise((r) => setTimeout(r, 20));
    }
};

test('issue descriptions are a known kind; malformed names are refused', () => {
    assert.ok(DOCUMENT_NAME.test(ITEM));
    assert.ok(DOCUMENT_NAME.test('item.123456789012345678.0'));
    for (const bad of ['item.0.1', 'item.042.1', 'item.1234567890123456789.0', 'item.42', 'item.42.1x', 'item.abc.1', 'items.42.1']) {
        assert.equal(DOCUMENT_NAME.test(bad), false, bad);
    }
});

test('two editors share the description; a viewer only watches; the state is stored', async () => {
    const a = connect(ITEM, token(ITEM, true, 1));
    const b = connect(ITEM, token(ITEM, true, 2));
    const viewer = connect(ITEM, token(ITEM, false, 3));
    try {
        await until(() => a.events.synced && b.events.synced && viewer.events.synced);

        a.doc.getText('t').insert(0, 'Steps to reproduce');
        await until(() => b.doc.getText('t').toString() === 'Steps to reproduce' && viewer.doc.getText('t').toString() === 'Steps to reproduce');

        viewer.doc.getText('t').insert(0, 'x');
        await new Promise((r) => setTimeout(r, 300));
        assert.equal(a.doc.getText('t').toString(), 'Steps to reproduce');

        await until(() => laravel.states.has(ITEM));
    } finally {
        a.close();
        b.close();
        viewer.close();
    }
});

test('a token for another issue does not open this one', async () => {
    const other = connect(ITEM, token('item.43.3', true, 1));
    try {
        await until(() => other.events.failed !== null);
        assert.equal(other.events.synced, false);
    } finally {
        other.close();
    }
});
