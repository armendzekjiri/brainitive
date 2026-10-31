// BRAI-345: why a connection is refused reaches the browser and the log; the app can be called on
// loopback under its public name (COLLAB_APP_HOST).
import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import WebSocket from 'ws';
import { HocuspocusProvider, HocuspocusProviderWebsocket } from '@hocuspocus/provider';
import { createCollabServer } from '../src/server.js';
import { createLaravelClient } from '../src/laravel.js';
import { deriveKeys, inspectToken, signToken } from '../src/token.js';

const SECRET = 'reasons-test-secret';
const keys = deriveKeys(SECRET);
const DOC = 'page.123e4567-e89b-42d3-a456-426614174000.0';

/** the internal API: `mode` decides how /access answers; records the Host header of each call */
const app = { mode: 'ok', hosts: [] };
let api;
let apiPort;
const servers = [];
const logs = [];
const logger = { error: (m) => logs.push(String(m)), warn: (m) => logs.push(String(m)) };

before(async () => {
    api = createServer(async (request, response) => {
        for await (const chunk of request) void chunk;
        app.hosts.push(request.headers.host);
        const json = (status, data) => response.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(data));
        if (app.mode === 'redirect') return response.writeHead(301, { Location: 'https://example.test/api' }).end();
        if (app.mode === 'error') return json(500, { message: 'boom' });
        if (request.url.endsWith('/access')) {
            if (app.mode === 'stale') return json(200, { current: false, users: { 1: 'edit' } });
            if (app.mode === 'denied') return json(200, { current: true, users: { 1: null } });
            return json(200, { current: true, users: { 1: 'edit' } });
        }
        return json(200, { state: null });
    });
    api.listen(0, '127.0.0.1');
    await once(api, 'listening');
    apiPort = api.address().port;
});

after(async () => {
    for (const server of servers) {
        await server.destroy();
        server.server.httpServer.closeAllConnections();
    }
    api.closeAllConnections();
    api.close();
});

async function start(appUrl, extra = {}) {
    const collab = createCollabServer({ port: 0, secret: SECRET, appUrl, accessIntervalMs: 3_600_000, stopOnSignals: false, logger, ...extra });
    await collab.listen();
    servers.push(collab);
    return `ws://127.0.0.1:${collab.server.address.port}`;
}

const token = (doc = DOC, exp = Math.floor(Date.now() / 1000) + 60, key = keys.token) => signToken({ v: 1, sub: 1, name: 'User 1', doc, edit: true, exp }, key);

/** connect once; resolves with the refusal reason, the socket's close reason, or 'synced' */
async function outcome(url, tokenValue) {
    const websocketProvider = new HocuspocusProviderWebsocket({ url, WebSocketPolyfill: WebSocket, maxAttempts: 1 });
    const provider = new HocuspocusProvider({ name: DOC, token: tokenValue, websocketProvider, awareness: null });
    provider.attach();
    const result = { failed: null, closeReason: null, synced: false };
    provider.on('authenticationFailed', ({ reason }) => { result.failed = reason; });
    provider.on('synced', () => { result.synced = true; });
    websocketProvider.on('disconnect', ({ event }) => { result.closeReason ??= event?.reason ?? null; });
    const end = Date.now() + 3_000;
    while (!result.synced && !(result.failed && result.closeReason) && Date.now() < end) await new Promise((r) => setTimeout(r, 20));
    provider.destroy();
    websocketProvider.destroy();
    return result;
}

test('inspectToken tells an expired token from a forged one', () => {
    assert.equal(inspectToken(token(DOC, 1), keys.token).problem, 'expired-token');
    assert.equal(inspectToken(token(DOC, 9e9, 'other-key'), keys.token).problem, 'invalid-token');
    assert.equal(inspectToken('nonsense', keys.token).problem, 'invalid-token');
    assert.equal(inspectToken(token(), keys.token).problem, null);
});

test('every refusal names its cause to the browser (auth message + close reason) and in the log', async () => {
    const url = await start(`http://127.0.0.1:${apiPort}`);
    const cases = [
        [token(DOC, 9e9, 'other-key'), 'ok', 'invalid-token'],
        [token(DOC, 1), 'ok', 'expired-token'],
        [token('page.123e4567-e89b-42d3-a456-426614174999.0'), 'ok', 'wrong-document'],
        [token(), 'stale', 'document-replaced'],
        [token(), 'denied', 'access-denied'],
        [token(), 'error', 'app-error-500'],
        [token(), 'redirect', 'app-error-301'],
    ];
    for (const [value, mode, reason] of cases) {
        app.mode = mode;
        logs.length = 0;
        const result = await outcome(url, value);
        assert.equal(result.synced, false, reason);
        assert.equal(result.failed, reason);
        assert.equal(result.closeReason, `Unauthorized: ${reason}`);
        assert.ok(logs.some((line) => line.includes(`refused ${DOC}`) && line.includes(reason)), `logged: ${reason} in ${logs}`);
    }
    assert.ok(logs.some((line) => line.includes('redirects to https://example.test/api')), 'the redirect target is logged');
    app.mode = 'ok';
    assert.equal((await outcome(url, token())).synced, true);
});

test('the app unreachable from the realtime server: app-unreachable, with the URL it tried in the log', async () => {
    const closed = createServer();
    closed.listen(0, '127.0.0.1');
    await once(closed, 'listening');
    const deadPort = closed.address().port;
    closed.close();
    const url = await start(`http://127.0.0.1:${deadPort}`);
    logs.length = 0;
    const result = await outcome(url, token());
    assert.equal(result.failed, 'app-unreachable');
    assert.equal(result.closeReason, 'Unauthorized: app-unreachable');
    assert.ok(logs.some((line) => line.includes(`POST http://127.0.0.1:${deadPort}/api/internal/collab/access`) && line.includes('ECONNREFUSED')), String(logs));
});

test('COLLAB_APP_HOST: the app is called on loopback with its public Host header', async () => {
    app.mode = 'ok';
    app.hosts.length = 0;
    const url = await start(`http://127.0.0.1:${apiPort}`, { appHost: 'www.brainitive.test' });
    assert.equal((await outcome(url, token())).synced, true);
    assert.ok(app.hosts.length >= 2 && app.hosts.every((host) => host === 'www.brainitive.test'), String(app.hosts));

    // without it: the URL's own host
    app.hosts.length = 0;
    const client = createLaravelClient({ appUrl: `http://127.0.0.1:${apiPort}`, internalKey: keys.internal });
    await client.access(DOC, [1]);
    assert.deepEqual(app.hosts, [`127.0.0.1:${apiPort}`]);
});
