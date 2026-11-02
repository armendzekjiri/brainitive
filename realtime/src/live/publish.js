// Brainitive → live channels (BRAI-151): POST /internal/live/publish on the realtime server's own
// HTTP port. Never reachable through a proxy: nginx / Caddy only forward /collab, a request carrying
// proxy headers is refused anyway, the peer must be loopback (or a private network with
// LIVE_PUBLISH_FROM=private — Docker), and the bearer is a key derived from the shared secret.
// Same guards for POST /internal/live/devices/revoked {devices: [ids]}: Brainitive signed these app devices
// out, their sockets are closed at once — live channels and live co-editing documents (BRAI-377); the minute
// re-checks catch what this misses.
// And for POST /internal/live/sessions/ended {sessions?: [sid], users?: [{id, generation}]}: browser sessions signed
// out / users signed out everywhere — their web sockets close with 4401 "Signed out" (Codex realtime review 1).
import { timingSafeEqual } from 'node:crypto';
import { SESSION_ID } from '../token.js';
import { CHANNEL } from './token.js';

export const PUBLISH_PATH = '/internal/live/publish';
export const REVOKED_DEVICES_PATH = '/internal/live/devices/revoked';
export const ENDED_SESSIONS_PATH = '/internal/live/sessions/ended';
const MAX_DEVICES = 1000;
const EVENT = /^[a-z][a-z0-9_.-]{0,63}$/;
const PROXY_HEADERS = ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'forwarded'];
const MAX_BODY = 64 * 1024;
const MAX_MESSAGES = 100;
const MAX_PAYLOAD = 2048;

function ipv4(address) {
    const plain = address.startsWith('::ffff:') ? address.slice(7) : address;
    const parts = plain.split('.').map(Number);
    return parts.length === 4 && parts.every((n) => Number.isInteger(n) && n >= 0 && n <= 255) ? parts : null;
}

export function isLoopback(address = '') {
    const v4 = ipv4(address);
    return v4 ? v4[0] === 127 : address === '::1';
}

export function isPrivate(address = '') {
    if (isLoopback(address)) return true;
    const v4 = ipv4(address);
    if (v4) return v4[0] === 10 || (v4[0] === 172 && v4[1] >= 16 && v4[1] <= 31) || (v4[0] === 192 && v4[1] === 168);
    return /^f[cd][0-9a-f]{2}:/i.test(address); // IPv6 unique local
}

function bearerMatches(header, key) {
    const given = Buffer.from(typeof header === 'string' && header.startsWith('Bearer ') ? header.slice(7) : '');
    const expected = Buffer.from(key);
    return given.length === expected.length && timingSafeEqual(given, expected);
}

const reply = (response, status, data) => {
    response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    response.end(JSON.stringify(data));
};

async function readBody(request) {
    const chunks = [];
    let size = 0;
    for await (const chunk of request) {
        size += chunk.length;
        if (size > MAX_BODY) return null;
        chunks.push(chunk);
    }
    return Buffer.concat(chunks).toString('utf8');
}

/** A valid message, else null. Payload rule: a flat object; Brainitive validates its keys. */
function message(entry) {
    if (!entry || typeof entry !== 'object') return null;
    const { channel, event, payload = {} } = entry;
    if (typeof channel !== 'string' || !CHANNEL.test(channel) || typeof event !== 'string' || !EVENT.test(event)) return null;
    if (!payload || typeof payload !== 'object' || Array.isArray(payload) || JSON.stringify(payload).length > MAX_PAYLOAD) return null;
    // flat: scalars or lists of scalars (Brainitive's Live::validate() enforces the key allow-list)
    const scalar = (v) => v === null || ['string', 'number', 'boolean'].includes(typeof v);
    if (!Object.values(payload).every((v) => scalar(v) || (Array.isArray(v) && v.every(scalar)))) return null;
    return { channel, event, payload };
}

/**
 * @returns {(request, response) => Promise<boolean>} true when the request was this endpoint's
 */
export function createPublishHandler({ key, hub, allowFrom = 'loopback', logger = console }) {
    const peerAllowed = allowFrom === 'private' ? isPrivate : isLoopback;

    return async function handle(request, response) {
        const path = (request.url ?? '').split('?')[0];
        if (!path.startsWith('/internal/')) return false;
        if (path !== PUBLISH_PATH && path !== REVOKED_DEVICES_PATH && path !== ENDED_SESSIONS_PATH) {
            reply(response, 404, { message: 'Not found.' });
            return true;
        }
        if (PROXY_HEADERS.some((header) => request.headers[header] !== undefined) || !peerAllowed(request.socket.remoteAddress)) {
            reply(response, 403, { message: 'Forbidden.' });
            return true;
        }
        if (!bearerMatches(request.headers.authorization, key)) {
            reply(response, 401, { message: 'Unauthenticated.' });
            return true;
        }
        if (request.method !== 'POST') {
            reply(response, 405, { message: 'Method not allowed.' });
            return true;
        }
        const body = await readBody(request);
        if (body === null) {
            reply(response, 413, { message: 'Too large.' });
            return true;
        }
        let data;
        try {
            data = JSON.parse(body);
        } catch {
            data = null;
        }
        if (path === REVOKED_DEVICES_PATH) {
            const devices = Array.isArray(data?.devices) && data.devices.length <= MAX_DEVICES ? data.devices : null;
            if (!devices || !devices.every((id) => Number.isInteger(id) && id > 0)) {
                reply(response, 422, { message: 'Invalid devices.' });
                return true;
            }
            const closed = hub.revokeDevices(devices);
            reply(response, 202, { closed });
            if (closed > 0) logger.debug?.(`[live] closed ${closed} socket(s) of revoked device(s)`);
            return true;
        }
        if (path === ENDED_SESSIONS_PATH) {
            const sessions = data?.sessions ?? [];
            const users = data?.users ?? [];
            const valid = Array.isArray(sessions) && sessions.length <= MAX_DEVICES && sessions.every((sid) => typeof sid === 'string' && SESSION_ID.test(sid))
                && Array.isArray(users) && users.length <= MAX_DEVICES
                && users.every((u) => u && Number.isInteger(u.id) && u.id > 0 && Number.isInteger(u.generation) && u.generation >= 0)
                && sessions.length + users.length > 0;
            if (!valid) {
                reply(response, 422, { message: 'Invalid sessions.' });
                return true;
            }
            const closed = hub.endSessions({ sessions, users: users.map(({ id, generation }) => ({ id, generation })) });
            reply(response, 202, { closed });
            if (closed > 0) logger.debug?.(`[live] closed ${closed} socket(s) of signed-out session(s)`);
            return true;
        }
        const entries = Array.isArray(data?.messages) ? data.messages : null;
        const messages = entries && entries.length <= MAX_MESSAGES ? entries.map(message) : null;
        if (!messages || messages.some((m) => m === null)) {
            reply(response, 422, { message: 'Invalid messages.' });
            return true;
        }
        let delivered = 0;
        for (const m of messages) delivered += hub.publish(m.channel, m.event, m.payload);
        reply(response, 202, { delivered });
        if (delivered > 0) logger.debug?.(`[live] published ${messages.length} message(s) to ${delivered} socket(s)`);
        return true;
    };
}
