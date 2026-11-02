// Codex realtime review 4 + 5: live co-editing has limits like the live channels — sockets per user, messages per
// socket, message size — and a document never grows past what Brainitive stores (checked before peers see it).
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { readFileSync } from 'node:fs';
import WebSocket from 'ws';
import { HocuspocusProvider, HocuspocusProviderWebsocket } from '@hocuspocus/provider';
import { DEFAULT_MAX_STATE_BYTES, createCollabServer, updateOf } from '../src/server.js';
import { deriveKeys, signToken } from '../src/token.js';

const SECRET = 'limits-test-secret';
const keys = deriveKeys(SECRET);
const DOC = 'page.123e4567-e89b-42d3-a456-426614174000.0';

/** Brainitive's internal API: everyone may edit; `stored` = the states it was asked to store */
const app = { stored: [], maxStateBytes: null };
let api;
const servers = [];

before(async () => {
    api = createServer(async (request, response) => {
        let body = '';
        for await (const chunk of request) body += chunk;
        const json = (status, data) => response.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(data));
        if (request.url.endsWith('/access')) {
            const { user_ids: ids } = JSON.parse(body);
            return json(200, {
                current: true,
                users: Object.fromEntries(ids.map((id) => [String(id), 'edit'])),
                revoked_devices: [],
                ...(app.maxStateBytes ? { max_state_bytes: app.maxStateBytes } : {}),
            });
        }
        if (request.method === 'GET') return json(200, { state: null });
        app.stored.push(JSON.parse(body).state);
        return response.writeHead(204).end();
    });
    api.listen(0, '127.0.0.1');
    await once(api, 'listening');
});

after(async () => {
    for (const server of servers) {
        await server.destroy();
        server.server.httpServer.closeAllConnections();
    }
    api.closeAllConnections();
    api.close();
});

async function start(extra = {}) {
    const collab = createCollabServer({
        port: 0,
        secret: SECRET,
        appUrl: `http://127.0.0.1:${api.address().port}`,
        accessIntervalMs: 3_600_000,
        debounce: 50,
        maxDebounce: 200,
        stopOnSignals: false,
        logger: { error() {}, warn() {} },
        ...extra,
    });
    await collab.listen();
    servers.push(collab);
    return `ws://127.0.0.1:${collab.server.address.port}`;
}

const token = (sub, doc = DOC) => signToken({ v: 1, sub, name: `User ${sub}`, doc, edit: true, exp: Math.floor(Date.now() / 1000) + 60 }, keys.token);

function connect(url, sub, name = DOC) {
    const websocketProvider = new HocuspocusProviderWebsocket({ url, WebSocketPolyfill: WebSocket, maxAttempts: 1 });
    const provider = new HocuspocusProvider({ name, token: token(sub, name), websocketProvider });
    provider.attach();
    const events = { failed: null, synced: false, closed: [], socketCodes: [] };
    websocketProvider.on('close', ({ event }) => events.socketCodes.push(event?.code));
    provider.on('authenticationFailed', ({ reason }) => { events.failed = reason; });
    provider.on('synced', () => { events.synced = true; });
    provider.on('close', ({ event }) => events.closed.push(event?.reason));
    return { provider, doc: provider.document, events, close: () => { provider.destroy(); websocketProvider.destroy(); } };
}

const until = async (check, ms = 3000) => {
    const end = Date.now() + ms;
    while (!check()) {
        if (Date.now() > end) throw new Error('timed out');
        await new Promise((r) => setTimeout(r, 20));
    }
};

// ── finding 4: sockets per user, messages per socket, message size ──────────────────────────────────

test('one user holds at most COLLAB_MAX_SOCKETS_PER_USER document sockets; a refused one closes with 4429', async () => {
    const url = await start({ limits: { maxSocketsPerUser: 2 } });
    const first = connect(url, 1);
    const second = connect(url, 1);
    const other = connect(url, 2);
    await until(() => first.events.synced && second.events.synced && other.events.synced);

    const third = connect(url, 1);
    await until(() => third.events.failed !== null);
    assert.equal(third.events.failed, 'too-many-connections');
    await until(() => third.events.socketCodes.includes(4429));
    assert.equal(third.events.synced, false);

    // a closed socket frees its place
    first.close();
    await new Promise((r) => setTimeout(r, 100));
    const fourth = connect(url, 1);
    await until(() => fourth.events.synced);
    [second, other, third, fourth].forEach((c) => c.close());
});

test('a message flood on one socket closes it with 4429', async () => {
    const url = await start({ limits: { messageBurst: 5, messagesPerSecond: 1 } });
    const socket = new WebSocket(url);
    await once(socket, 'open');
    for (let i = 0; i < 20; i++) socket.send(Buffer.from([1, 2, 3]));
    const [code] = await once(socket, 'close');
    assert.equal(code, 4429);
});

test('a message larger than COLLAB_MAX_MESSAGE_BYTES closes the socket (1009)', async () => {
    const url = await start({ limits: { maxMessageBytes: 4096 } });
    const socket = new WebSocket(url);
    await once(socket, 'open');
    socket.send(Buffer.alloc(8192));
    const [code] = await once(socket, 'close');
    assert.equal(code, 1009);
});

test('normal editing stays well inside the limits', async () => {
    const url = await start();
    const a = connect(url, 1);
    const b = connect(url, 2);
    await until(() => a.events.synced && b.events.synced);
    for (let i = 0; i < 60; i++) a.doc.getText('t').insert(i, 'x');
    await until(() => b.doc.getText('t').length === 60);
    assert.deepEqual(a.events.socketCodes, []);
    a.close();
    b.close();
});

// ── finding 5: the document never outgrows what Brainitive stores — checked before peers see the update ──

async function oversized(url) {
    const a = connect(url, 1);
    const b = connect(url, 2);
    await until(() => a.events.synced && b.events.synced);
    a.doc.getText('t').insert(0, 'small ');
    await until(() => b.doc.getText('t').toString() === 'small ');

    a.doc.getText('t').insert(6, 'x'.repeat(3000));
    await until(() => a.events.closed.includes('Document too large'));
    await new Promise((r) => setTimeout(r, 300));
    assert.equal(b.doc.getText('t').toString(), 'small ', 'the peer never got it');
    assert.deepEqual(b.events.closed, [], 'only the sender is refused');

    // the others keep working on the document
    b.doc.getText('t').insert(6, 'still fine');
    const c = connect(url, 3);
    await until(() => c.events.synced && c.doc.getText('t').toString() === 'small still fine');
    [a, b, c].forEach((client) => client.close());
}

test('an update that would grow the document past COLLAB_MAX_STATE_BYTES is refused for its sender only, before peers see it', async () => {
    app.stored = [];
    const url = await start({ maxStateBytes: 2048 });
    await oversized(url);
    await new Promise((r) => setTimeout(r, 300));
    assert.ok(app.stored.every((state) => Buffer.from(state, 'base64').length <= 2048), 'nothing over the limit was stored');
});

test('the default state limit is the app’s (config/collab.php COLLAB_MAX_STATE_BYTES)', () => {
    const config = readFileSync(new URL('../../config/collab.php', import.meta.url), 'utf8');
    const match = config.match(/env\('COLLAB_MAX_STATE_BYTES',\s*([\d\s*_]+)\)/);
    assert.ok(match, 'config/collab.php still reads COLLAB_MAX_STATE_BYTES');
    assert.equal(match[1].replaceAll('_', '').split('*').reduce((product, n) => product * Number(n.trim()), 1), DEFAULT_MAX_STATE_BYTES);
});

test('updateOf finds the Yjs update in sync step 2 / update messages, nothing in others', () => {
    // varString "d" · type · syncType · varUint8Array
    assert.deepEqual([...updateOf(new Uint8Array([1, 100, 0, 2, 3, 7, 8, 9]))], [7, 8, 9], 'Sync · Update');
    assert.deepEqual([...updateOf(new Uint8Array([1, 100, 0, 1, 2, 7, 8]))], [7, 8], 'Sync · SyncStep2');
    assert.deepEqual([...updateOf(new Uint8Array([1, 100, 4, 2, 1, 7]))], [7], 'SyncReply · Update');
    assert.equal(updateOf(new Uint8Array([1, 100, 0, 0, 1, 7])), null, 'SyncStep1 is a state vector');
    assert.equal(updateOf(new Uint8Array([1, 100, 1, 2, 1, 7])), null, 'awareness');
    assert.equal(updateOf(new Uint8Array([1, 100, 0, 2, 9, 7])), null, 'cut short');
    assert.equal(updateOf(new Uint8Array([])), null);
});

test('Brainitive’s own limit (max_state_bytes in the access answer) wins over the default', async () => {
    app.maxStateBytes = 2048;
    try {
        await oversized(await start());
    } finally {
        app.maxStateBytes = null;
    }
});
