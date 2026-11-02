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
const laravel = { states: new Map(), stale: new Set(), tooLarge: new Set(), access: { 1: 'edit', 2: 'edit', 3: 'view', 4: 'edit' }, calls: [], bearer: [], revokedDevices: new Set(), accessBodies: [] };
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
            const { document, user_ids: ids, devices = [] } = JSON.parse(body);
            laravel.accessBodies.push(JSON.parse(body));
            return json(200, {
                current: !laravel.stale.has(document),
                users: Object.fromEntries(ids.map((id) => [String(id), laravel.access[id] ?? null])),
                revoked_devices: devices.map(({ id }) => id).filter((id) => laravel.revokedDevices.has(id)),
            });
        }
        if (laravel.stale.has(name)) return json(409, { message: 'Stale document.' });
        if (request.method === 'PUT' && laravel.tooLarge.has(name)) return json(413, { message: 'Document too large.' });
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
        authDeadlineMs: 300,
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

const token = (doc, edit, sub = 1, exp = Math.floor(Date.now() / 1000) + 60, extra = {}) => signToken({ v: 1, sub, name: `User ${sub}`, doc, edit, exp, ...extra }, keys.token);
const deviceToken = (doc, sub, dev) => token(doc, true, sub, undefined, { dev });

function connect(name, tokenValue) {
    const websocketProvider = new HocuspocusProviderWebsocket({ url, WebSocketPolyfill: WebSocket, maxAttempts: 1 });
    const provider = new HocuspocusProvider({ name, token: tokenValue, websocketProvider });
    provider.attach();
    const events = { failed: null, synced: false, closed: [], socketCodes: [] };
    websocketProvider.on('close', ({ event }) => events.socketCodes.push(event?.code));
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
    const kept = connect(DOC, token(DOC, true, 1));
    const removed = connect(DOC, token(DOC, true, 4));
    await until(() => kept.events.synced && removed.events.synced);
    laravel.access[4] = null;

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
    laravel.access[4] = 'edit';
});

test('access is asked from Brainitive on connect: a still-valid token of someone removed gets nothing', async () => {
    laravel.access[5] = null;
    const removed = connect(DOC, token(DOC, true, 5));
    await until(() => removed.events.failed !== null);
    assert.equal(removed.events.synced, false);
    removed.close();

    // an edit token of someone who is now only allowed to view: read-only
    laravel.access[6] = 'view';
    const editor = connect(DOC, token(DOC, true, 1));
    const demoted = connect(DOC, token(DOC, true, 6));
    await until(() => editor.events.synced && demoted.events.synced);
    demoted.doc.getText('t').insert(0, 'DEMOTED ');
    await new Promise((r) => setTimeout(r, 300));
    assert.equal(editor.doc.getText('t').toString().includes('DEMOTED'), false);
    editor.close();
    demoted.close();
});

test('sockets that never authenticate are closed', async () => {
    const socket = new WebSocket(url);
    const [code] = await once(socket, 'close');
    assert.equal(code, 4401);
});

test('pretending to be someone else in awareness gets you disconnected', async () => {
    const honest = connect(DOC, token(DOC, true, 1));
    const faker = connect(DOC, token(DOC, true, 2));
    await until(() => honest.events.synced && faker.events.synced);
    faker.provider.awareness.setLocalState({ name: 'User 1', color: 'red;background:url(https://evil)', awarenessData: { userId: 1, edit: true } });
    await until(() => faker.events.closed.includes('Forbidden'));
    honest.provider.awareness.setLocalState({ name: 'User 1', color: '#2563eb', awarenessData: { userId: 1, edit: true } });
    await new Promise((r) => setTimeout(r, 200));
    assert.equal(honest.events.closed.length, 0);
    honest.close();
    faker.close();
});

test('a document Brainitive refuses as too large is closed instead of retried forever', async () => {
    const BIG = 'page.123e4567-e89b-42d3-a456-426614174777.0';
    laravel.tooLarge.add(BIG);
    const client = connect(BIG, token(BIG, true, 1));
    await until(() => client.events.synced);
    client.doc.getText('t').insert(0, 'too much');
    await until(() => client.events.closed.includes('Document too large'));
    client.close();
});

test('a stale document is not loaded', async () => {
    laravel.stale.add(OTHER);
    const client = connect(OTHER, token(OTHER, true, 1));
    await new Promise((r) => setTimeout(r, 400));
    assert.equal(client.events.synced, false);
    client.close();
    laravel.stale.delete(OTHER);
});

// ── revoked app devices (BRAI-377) ────────────────────────────────────────────────────────────────

test('collab tokens: `dev` must be a positive integer', async () => {
    const { verifyToken } = await import('../src/token.js');
    assert.equal(verifyToken(deviceToken(DOC, 1, 7), keys.token).dev, 7);
    assert.equal(verifyToken(deviceToken(DOC, 1, '7'), keys.token), null);
    assert.equal(verifyToken(deviceToken(DOC, 1, 0), keys.token), null);
    assert.equal(verifyToken(token(DOC, true), keys.token).dev, undefined, 'web tokens have no device');
});

test('revoked device (re-check): its document socket closes with 4410; the web and other devices stay', async () => {
    laravel.accessBodies = [];
    const desktop = connect(DOC, deviceToken(DOC, 1, 201));
    const phone = connect(DOC, deviceToken(DOC, 1, 202));
    const web = connect(DOC, token(DOC, true, 2));
    await until(() => desktop.events.synced && phone.events.synced && web.events.synced);
    assert.deepEqual(laravel.accessBodies.find((b) => b.devices?.[0]?.id === 201).devices, [{ id: 201, user_id: 1 }], 'asked on connect');

    await collab.recheckAccess();
    assert.deepEqual(laravel.accessBodies.at(-1).devices.sort((a, b) => a.id - b.id), [{ id: 201, user_id: 1 }, { id: 202, user_id: 1 }]);
    assert.equal(desktop.events.socketCodes.length, 0, 'nothing revoked yet');

    laravel.revokedDevices.add(201);
    await collab.recheckAccess();
    await until(() => desktop.events.socketCodes.includes(4410));
    await new Promise((r) => setTimeout(r, 150));
    assert.equal(phone.events.socketCodes.length, 0);
    assert.equal(web.events.socketCodes.length, 0);
    assert.equal(phone.events.closed.length, 0);

    // a still-valid token of the revoked device can't come back
    const again = connect(DOC, deviceToken(DOC, 1, 201));
    await until(() => again.events.failed !== null);
    assert.equal(again.events.failed, 'device-revoked');
    assert.equal(again.events.synced, false);
    [desktop, phone, web, again].forEach((c) => c.close());
    laravel.revokedDevices.clear();
});

test('revoked device (pushed): revokeDevices closes its connections at once and refuses its tokens', async () => {
    const desktop = connect(DOC, deviceToken(DOC, 1, 211));
    const other = connect(OTHER, deviceToken(OTHER, 2, 212));
    await until(() => desktop.events.synced && other.events.synced);

    assert.equal(collab.revokeDevices([211, 'x']), 1);
    await until(() => desktop.events.socketCodes.includes(4410));
    assert.equal(other.events.socketCodes.length, 0);

    // known as revoked without asking Brainitive (which still thinks it's active here)
    const again = connect(DOC, deviceToken(DOC, 1, 211));
    await until(() => again.events.failed !== null);
    assert.equal(again.events.failed, 'device-revoked');
    [desktop, other, again].forEach((c) => c.close());
});

test('a device Brainitive answers as revoked on connect is refused with 4410', async () => {
    laravel.revokedDevices.add(221);
    const revoked = connect(DOC, deviceToken(DOC, 1, 221));
    await until(() => revoked.events.failed !== null);
    assert.equal(revoked.events.failed, 'device-revoked');
    await until(() => revoked.events.socketCodes.includes(4410));
    revoked.close();
    laravel.revokedDevices.clear();
});
