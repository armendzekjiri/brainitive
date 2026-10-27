// Live whiteboards (BRAI-153): `whiteboard.<uuid>.<epoch>` documents go through the same server as
// documents — tokens, read-only viewers, awareness checks and persistence are kind-agnostic.
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import WebSocket from 'ws';
import { HocuspocusProvider, HocuspocusProviderWebsocket } from '@hocuspocus/provider';
import { createCollabServer } from '../src/server.js';
import { DOCUMENT_NAME, deriveKeys, signToken } from '../src/token.js';

const SECRET = 'node-whiteboard-secret';
const keys = deriveKeys(SECRET);
const BOARD = 'whiteboard.123e4567-e89b-42d3-a456-426614174000.2';

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

test('whiteboard documents are a known kind; anything else is refused', () => {
    assert.ok(DOCUMENT_NAME.test(BOARD));
    assert.ok(DOCUMENT_NAME.test('page.123e4567-e89b-42d3-a456-426614174000.0'));
    for (const bad of ['board.123e4567-e89b-42d3-a456-426614174000.0', 'whiteboard.123e4567.0', 'whiteboard.123e4567-e89b-42d3-a456-426614174000', `${BOARD}x`]) {
        assert.equal(DOCUMENT_NAME.test(bad), false, bad);
    }
});

test('editors share elements and cursors live; the scene is stored; viewers only watch', async () => {
    const a = connect(BOARD, token(BOARD, true, 1));
    const b = connect(BOARD, token(BOARD, true, 2));
    const viewer = connect(BOARD, token(BOARD, false, 3));
    try {
        await until(() => a.events.synced && b.events.synced && viewer.events.synced);

        a.doc.getMap('elements').set('rect-1', { id: 'rect-1', type: 'rectangle', version: 3, versionNonce: 7, isDeleted: false });
        await until(() => b.doc.getMap('elements').has('rect-1') && viewer.doc.getMap('elements').has('rect-1'));
        assert.equal(b.doc.getMap('elements').get('rect-1').version, 3);

        // named cursors: the pointer rides along in the awareness state the server validates
        a.provider.awareness.setLocalState({ name: 'User 1', color: '#2563eb', awarenessData: { userId: 1, edit: true }, pointer: { x: 10, y: 20, tool: 'pointer' }, button: 'up' });
        await until(() => [...b.provider.awareness.getStates().values()].some((s) => s.pointer?.x === 10));

        // a viewer's writes never reach anybody
        viewer.doc.getMap('elements').set('rect-2', { id: 'rect-2', type: 'rectangle', version: 1, versionNonce: 1 });
        await new Promise((r) => setTimeout(r, 300));
        assert.equal(a.doc.getMap('elements').has('rect-2'), false);

        await until(() => laravel.states.has(BOARD));
    } finally {
        a.close();
        b.close();
        viewer.close();
    }
});
