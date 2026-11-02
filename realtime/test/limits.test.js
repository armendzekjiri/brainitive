// Codex realtime review 4 + 5: live co-editing has limits like the live channels — sockets per user, messages per
// socket, message size — and a document never grows past what Brainitive stores (checked before peers see it).
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import WebSocket from 'ws';
import { HocuspocusProvider, HocuspocusProviderWebsocket } from '@hocuspocus/provider';
import { createCollabServer } from '../src/server.js';
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
