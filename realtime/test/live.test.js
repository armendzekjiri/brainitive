import { after, before, beforeEach, test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import WebSocket from 'ws';
import { createCollabServer } from '../src/server.js';
import { deriveKeys, signToken } from '../src/token.js';
import { attachLive, isLiveRequest } from '../src/live/index.js';
import { createPublishHandler, isLoopback, isPrivate } from '../src/live/publish.js';
import { deriveLiveKeys, signLiveToken, verifyLiveToken } from '../src/live/token.js';

const SECRET = 'node-live-secret';
const keys = deriveLiveKeys(SECRET);
const internal = deriveKeys(SECRET).internal;

/** Brainitive's internal access endpoint, in memory: user id → channels they may still use. */
const laravel = { allowed: new Map(), calls: [], fail: false };
let api;
let collab;
let live;
let base;

before(async () => {
    api = createServer(async (request, response) => {
        let body = '';
        for await (const chunk of request) body += chunk;
        laravel.calls.push({ url: request.url, auth: request.headers.authorization, body: JSON.parse(body || '{}') });
        const failing = laravel.fail || JSON.parse(body || '{}').users?.some(({ id }) => laravel.failFor?.has(id));
        if (request.headers.authorization !== `Bearer ${internal}` || failing) return response.writeHead(failing ? 500 : 401).end();
        const users = Object.fromEntries(JSON.parse(body).users.map(({ id, channels }) => [String(id), channels.filter((c) => laravel.allowed.get(id)?.has(c))]));
        response.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ users }));
    });
    api.listen(0, '127.0.0.1');
    await once(api, 'listening');

    const appUrl = `http://127.0.0.1:${api.address().port}`;
    collab = createCollabServer({ port: 0, secret: SECRET, appUrl, accessIntervalMs: 3_600_000, stopOnSignals: false, logger: { error() {} } });
    live = attachLive(collab.server, {
        secret: SECRET,
        appUrl,
        accessIntervalMs: 3_600_000, // driven by hand below
        logger: { error() {}, debug() {} },
        limits: { authDeadlineMs: 300, presenceDelayMs: 10, signalBurst: 3, signalsPerSecond: 1, messageBurst: 30, messagesPerSecond: 5 },
    });
    await collab.listen();
    base = `127.0.0.1:${collab.server.address.port}`;
});

after(async () => {
    live.destroy();
    await collab.destroy();
    collab.server.httpServer.closeAllConnections();
    api.closeAllConnections();
    api.close();
});

beforeEach(() => {
    laravel.calls = [];
    laravel.fail = false;
});

const now = () => Math.floor(Date.now() / 1000);
const token = (sub, ch, extra = {}) => signLiveToken({ v: 1, typ: 'live', sub, name: `User ${sub}`, avatar: null, ch, iat: now(), exp: now() + 60, ...extra }, keys.token);

const until = async (check, ms = 3000) => {
    const end = Date.now() + ms;
    while (!check()) {
        if (Date.now() > end) throw new Error('timed out');
        await new Promise((r) => setTimeout(r, 10));
    }
};

/** A live socket that records what it receives. */
async function open(path = '/collab?live=1') {
    const socket = new WebSocket(`ws://${base}${path}`);
    const client = { socket, messages: [], closed: null };
    socket.on('message', (raw) => client.messages.push(JSON.parse(raw.toString())));
    socket.on('close', (code, reason) => { client.closed = { code, reason: reason.toString() }; });
    await once(socket, 'open');
    client.send = (message) => socket.send(JSON.stringify(message));
    client.next = async (type, match = () => true) => {
        let found;
        await until(() => (found = client.messages.find((m) => m.type === type && match(m))));
        client.messages.splice(client.messages.indexOf(found), 1);
        return found;
    };
    client.none = async (type, ms = 150) => {
        await new Promise((r) => setTimeout(r, ms));
        assert.equal(client.messages.filter((m) => m.type === type).length, 0, `no ${type} expected`);
    };
    client.close = () => socket.close();
    return client;
}

async function joined(sub, channels, { presence = false, path } = {}) {
    const client = await open(path);
    client.send({ type: 'auth', token: token(sub, channels) });
    await client.next('ready');
    for (const channel of channels) {
        client.send({ type: 'subscribe', channel, presence });
        await client.next('subscribed', (m) => m.channel === channel);
    }
    return client;
}

async function publish(messages, { headers = {}, key = keys.publish } = {}) {
    return fetch(`http://${base}/internal/live/publish`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${key}`, ...headers },
        body: JSON.stringify({ messages }),
    });
}

// ── tokens ────────────────────────────────────────────────────────────────────────────────────────

// Produced by App\Support\Live\LiveToken::issue() with COLLAB_SECRET=test-collab-secret (exp 4000000000)
const PHP_TOKEN = 'eyJ2IjoxLCJ0eXAiOiJsaXZlIiwic3ViIjo3LCJuYW1lIjoiQWRhIExvdmVsYWNlIiwiYXZhdGFyIjpudWxsLCJjaCI6WyJ1c2VyLjciLCJpdGVtLjQyIl0sImlhdCI6Mzk5OTk5OTg4MCwiZXhwIjo0MDAwMDAwMDAwfQ.k_OHpcgTUdSAwrST8HY7TcEzzFcf10bfgaLaLL3MMdE';

test('live tokens: accepts Brainitive’s, refuses expired, forged, document tokens and bad channel names', () => {
    const php = deriveLiveKeys('test-collab-secret');
    assert.deepEqual(verifyLiveToken(PHP_TOKEN, php.token, 3_999_999_000), { v: 1, typ: 'live', sub: 7, name: 'Ada Lovelace', avatar: null, ch: ['user.7', 'item.42'], iat: 3_999_999_880, exp: 4_000_000_000 });
    assert.equal(php.publish, 'ae80e72b9aaa87b37d9a66228ad301222ffbc8733459262eb3b3ad123d060d6b', 'same publish key as LiveConfig::publishKey()');
    assert.equal(verifyLiveToken(PHP_TOKEN, php.token, 4_000_000_000), null, 'expired');
    assert.equal(verifyLiveToken(PHP_TOKEN, keys.token, 0), null, 'other secret');
    // a co-editing token (other key label, other shape) is never a live token
    const doc = signToken({ v: 1, sub: 7, name: 'x', doc: 'page.x.0', edit: true, exp: 4_000_000_000 }, deriveKeys('test-collab-secret').token);
    assert.equal(verifyLiveToken(doc, php.token, 0), null);
    assert.equal(verifyLiveToken(token(1, ['../etc']), keys.token), null, 'channel names are checked');
    assert.equal(verifyLiveToken(token(1, ['user.1'], { typ: 'doc' }), keys.token), null);
    assert.equal(verifyLiveToken(token(1, ['user.1'], { iat: undefined }), keys.token), null, 'iat is required');
    assert.equal(verifyLiveToken('abc', keys.token), null);
    assert.equal(verifyLiveToken(undefined, keys.token), null);
    assert.notEqual(keys.publish, internal, 'the publish key is not the internal API key');
});

test('only ?live=1 or /live upgrades are live sockets', () => {
    assert.equal(isLiveRequest({ url: '/collab?live=1' }), true);
    assert.equal(isLiveRequest({ url: '/live' }), true);
    assert.equal(isLiveRequest({ url: '/collab' }), false);
    assert.equal(isLiveRequest({ url: '/collab?live=0' }), false);
});

// ── joining ───────────────────────────────────────────────────────────────────────────────────────

test('a socket must authenticate first, with a valid token, in time', async () => {
    const early = await open();
    early.send({ type: 'subscribe', channel: 'user.1' });
    await until(() => early.closed);
    assert.equal(early.closed.code, 4401);

    const forged = await open();
    forged.send({ type: 'auth', token: token(1, ['user.1']).replace(/.$/, 'x') });
    await until(() => forged.closed);
    assert.equal(forged.closed.code, 4401);

    const silent = await open('/live');
    await until(() => silent.closed, 2000);
    assert.equal(silent.closed.code, 4401, 'closed after the auth deadline');
});

test('channels outside the token are refused; a second token adds channels but can’t switch users', async () => {
    const client = await joined(1, ['user.1']);
    client.send({ type: 'subscribe', channel: 'item.9' });
    assert.deepEqual(await client.next('error'), { type: 'error', code: 'forbidden', channel: 'item.9' });

    client.send({ type: 'auth', token: token(1, ['item.9']) });
    assert.deepEqual((await client.next('ready')).channels.sort(), ['item.9', 'user.1']);
    client.send({ type: 'subscribe', channel: 'item.9' });
    await client.next('subscribed', (m) => m.channel === 'item.9');

    client.send({ type: 'auth', token: token(2, ['user.2']) });
    await until(() => client.closed);
    assert.equal(client.closed.code, 4401);
});

// ── presence + signals ───────────────────────────────────────────────────────────────────────────

test('presence: members join and leave; several tabs of one user are one member', async () => {
    const ada = await joined(1, ['item.1'], { presence: true });
    const bob = await joined(2, ['item.1'], { presence: true });
    const bobTab = await joined(2, ['item.1'], { presence: true });
    const watcher = await joined(3, ['item.1']); // subscribed without announcing itself

    const list = await ada.next('presence', (m) => m.members.length === 2);
    assert.deepEqual(list.members, [{ id: 1, name: 'User 1', avatar: null }, { id: 2, name: 'User 2', avatar: null }]);

    bob.close();
    await new Promise((r) => setTimeout(r, 60));
    assert.deepEqual(live.members('item.1').map((m) => m.id), [1, 2], 'Bob still has a tab open');
    bobTab.close();
    const after = await ada.next('presence', (m) => m.members.length === 1);
    assert.deepEqual(after.members.map((m) => m.id), [1]);
    // a subscriber that doesn't announce itself still sees who is here
    await watcher.next('presence', (m) => m.members.length === 1);
    ada.close();
    watcher.close();
});

test('signals reach the channel’s other members only, with the sender from the token', async () => {
    const ada = await joined(1, ['item.2', 'item.3']);
    const bob = await joined(2, ['item.2']);
    const eve = await joined(3, ['item.3']);

    ada.send({ type: 'signal', channel: 'item.2', name: 'typing', data: { field: 'comment' }, from: { id: 3, name: 'Spoofed' } });
    const got = await bob.next('signal');
    assert.deepEqual(got, { type: 'signal', channel: 'item.2', name: 'typing', data: { field: 'comment' }, from: { id: 1, name: 'User 1' } });
    await eve.none('signal');
    await ada.none('signal', 0);

    // not subscribed / bad names / too large: refused
    eve.send({ type: 'signal', channel: 'item.2', name: 'typing' });
    assert.equal((await eve.next('error')).code, 'not_subscribed');
    ada.send({ type: 'signal', channel: 'item.2', name: 'Bad Name' });
    assert.equal((await ada.next('error')).code, 'invalid_signal');
    ada.send({ type: 'signal', channel: 'item.2', name: 'typing', data: 'x'.repeat(2000) });
    assert.equal((await ada.next('error')).code, 'invalid_signal');
    [ada, bob, eve].forEach((c) => c.close());
});

test('rate limits: signals beyond the burst are dropped, a message flood closes the socket', async () => {
    const ada = await joined(1, ['item.4']);
    const bob = await joined(2, ['item.4']);
    for (let i = 0; i < 5; i++) ada.send({ type: 'signal', channel: 'item.4', name: 'typing', data: i });
    assert.equal((await ada.next('error')).code, 'rate_limited');
    await new Promise((r) => setTimeout(r, 100));
    assert.equal(bob.messages.filter((m) => m.type === 'signal').length, 3);

    for (let i = 0; i < 60; i++) ada.send({ type: 'ping' });
    await until(() => ada.closed);
    assert.equal(ada.closed.code, 4429);
    bob.close();
});

// ── publish ──────────────────────────────────────────────────────────────────────────────────────

test('publish: delivered to the channel’s subscribers only', async () => {
    const ada = await joined(1, ['user.1']);
    const bob = await joined(2, ['user.2']);
    const response = await publish([{ channel: 'user.1', event: 'notification.created', payload: { id: 'abc' } }]);
    assert.equal(response.status, 202);
    assert.deepEqual(await response.json(), { delivered: 1 });
    assert.deepEqual(await ada.next('event'), { type: 'event', channel: 'user.1', event: 'notification.created', payload: { id: 'abc' } });
    await bob.none('event');
    ada.close();
    bob.close();
});

test('publish: needs the publish key, no proxy headers, valid messages', async () => {
    const message = [{ channel: 'user.1', event: 'x', payload: {} }];
    assert.equal((await publish(message, { key: 'wrong' })).status, 401);
    assert.equal((await publish(message, { key: internal })).status, 401, 'the internal API key is not the publish key');
    assert.equal((await publish(message, { headers: { 'X-Forwarded-For': '203.0.113.9' } })).status, 403);
    assert.equal((await publish(message, { headers: { 'X-Real-IP': '203.0.113.9' } })).status, 403);
    assert.equal((await publish([{ channel: '../x', event: 'x' }])).status, 422);
    assert.equal((await publish([{ channel: 'user.1', event: 'x', payload: ['list'] }])).status, 422);
    assert.equal((await publish([{ channel: 'user.1', event: 'x', payload: { big: 'x'.repeat(3000) } }])).status, 422);
    assert.equal((await publish([{ channel: 'user.1', event: 'x', payload: { nested: { title: 'x' } } }])).status, 422, 'flat payloads only');
    assert.equal((await publish([{ channel: 'user.1', event: 'x', payload: { ids: [1, 2], at: null } }])).status, 202);
    assert.equal((await fetch(`http://${base}/internal/live/publish`, { headers: { Authorization: `Bearer ${keys.publish}` } })).status, 405);
    assert.equal((await fetch(`http://${base}/internal/other`)).status, 404);
    // Hocuspocus still answers everything else (health check)
    assert.equal((await fetch(`http://${base}/`)).status, 200);
});

test('publish: loopback peers only, private networks only when allowed', async () => {
    assert.ok(isLoopback('127.0.0.1') && isLoopback('::1') && isLoopback('::ffff:127.0.0.1'));
    assert.ok(!isLoopback('10.0.0.2') && !isLoopback('203.0.113.9'));
    assert.ok(isPrivate('172.18.0.3') && isPrivate('192.168.1.2') && isPrivate('10.1.2.3') && isPrivate('fd00::1'));
    assert.ok(!isPrivate('172.32.0.1') && !isPrivate('203.0.113.9') && !isPrivate('2001:db8::1'));

    const hub = { publish: () => 1 };
    const request = (remoteAddress) => Object.assign(
        (async function* () { yield Buffer.from(JSON.stringify({ messages: [{ channel: 'user.1', event: 'x' }] })); })(),
        { url: '/internal/live/publish', method: 'POST', headers: { authorization: `Bearer ${keys.publish}` }, socket: { remoteAddress } },
    );
    const response = () => ({ status: 0, writeHead(status) { this.status = status; }, end() {} });
    const loopbackOnly = createPublishHandler({ key: keys.publish, hub });
    const docker = createPublishHandler({ key: keys.publish, hub, allowFrom: 'private' });
    let res = response();
    await loopbackOnly(request('172.18.0.3'), res);
    assert.equal(res.status, 403);
    res = response();
    await docker(request('172.18.0.3'), res);
    assert.equal(res.status, 202);
    res = response();
    await docker(request('203.0.113.9'), res);
    assert.equal(res.status, 403);
});

// ── re-validation ────────────────────────────────────────────────────────────────────────────────

test('access re-check: channels a user lost are dropped; Brainitive down keeps everyone', async () => {
    laravel.allowed.set(1, new Set(['user.1', 'item.5']));
    laravel.allowed.set(2, new Set(['user.2', 'item.5']));
    const ada = await joined(1, ['user.1', 'item.5'], { presence: true });
    const bob = await joined(2, ['item.5'], { presence: true });
    await ada.next('presence', (m) => m.members.length === 2);

    laravel.fail = true;
    await live.recheckAccess();
    await ada.none('revoked');

    laravel.fail = false;
    laravel.allowed.set(1, new Set(['user.1'])); // Ada lost the issue
    await live.recheckAccess();
    assert.deepEqual(await ada.next('revoked'), { type: 'revoked', channel: 'item.5' });
    const call = laravel.calls.at(-1);
    assert.equal(call.url, '/api/internal/collab/live/access');
    assert.equal(call.auth, `Bearer ${internal}`);
    assert.deepEqual(call.body.users.find((u) => u.id === 1).channels.sort(), ['item.5', 'user.1']);

    // out of the channel: no events, gone from presence, can't rejoin with the old grant
    await bob.next('presence', (m) => m.members.length === 1);
    await publish([{ channel: 'item.5', event: 'item.updated', payload: { id: 5 } }]);
    await bob.next('event');
    await ada.none('event');
    ada.send({ type: 'subscribe', channel: 'item.5' });
    assert.equal((await ada.next('error')).code, 'forbidden');
    // a token issued before the revocation can't bring it back; a newer one (Brainitive asked again) can
    ada.send({ type: 'auth', token: token(1, ['item.5'], { iat: now() - 30 }) });
    assert.ok(!(await ada.next('ready')).channels.includes('item.5'));
    ada.send({ type: 'auth', token: token(1, ['item.5'], { iat: now() + 1 }) });
    assert.ok((await ada.next('ready')).channels.includes('item.5'));
    ada.close();
    bob.close();
});

test('access re-check: a failing entry doesn’t shield the others; a user whose check keeps failing is disconnected', async () => {
    laravel.allowed.set(6, new Set(['item.6']));
    laravel.allowed.set(7, new Set());
    laravel.failFor = new Set([6]);
    const six = await joined(6, ['item.6']);
    const seven = await joined(7, ['item.7']);

    await live.recheckAccess();
    assert.deepEqual(await seven.next('revoked'), { type: 'revoked', channel: 'item.7' }, 'checked one by one after the batch failed');
    await live.recheckAccess();
    assert.equal(six.closed, null);
    await live.recheckAccess();
    await until(() => six.closed);
    assert.equal(six.closed.code, 4403);
    laravel.failFor = new Set();
    seven.close();
});

test('signal limits are per person, not per socket', async () => {
    const tabA = await joined(8, ['item.8']);
    const tabB = await joined(8, ['item.8']);
    const bob = await joined(9, ['item.8']);
    for (let i = 0; i < 3; i++) tabA.send({ type: 'signal', channel: 'item.8', name: 'typing', data: i });
    tabB.send({ type: 'signal', channel: 'item.8', name: 'typing', data: 'b' });
    assert.equal((await tabB.next('error')).code, 'rate_limited');
    [tabA, tabB, bob].forEach((c) => c.close());
});
