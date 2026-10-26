// Live channel tokens + keys (BRAI-151). Mirrors app/Support/Live/LiveToken.php and LiveConfig.php —
// keep them in sync. Same scheme as the co-editing token (src/token.js), other key labels, so a
// document token is never a channel token and neither is ever the publish key.
import { createHmac, timingSafeEqual } from 'node:crypto';

/** Keys derived from the shared secret (COLLAB_SECRET or derived from APP_KEY). */
export function deriveLiveKeys(secret) {
    return {
        token: createHmac('sha256', secret).update('live-token').digest(),
        publish: createHmac('sha256', secret).update('live-publish').digest('hex'),
    };
}

/** Channel names are opaque here (Brainitive authorizes them): `<type>.<id>`. */
export const CHANNEL = /^[a-z][a-z0-9_]{0,31}\.[A-Za-z0-9_-]{1,64}$/;

/** Most channels one token may grant. */
export const MAX_TOKEN_CHANNELS = 50;

const base64url = (buffer) => Buffer.from(buffer).toString('base64url');

/**
 * The payload of a valid, unexpired live token, else null.
 * @returns {{v: 1, typ: 'live', sub: number, name: string, avatar: string | null, ch: string[], iat: number, exp: number} | null}
 */
export function verifyLiveToken(token, key, now = Math.floor(Date.now() / 1000)) {
    if (typeof token !== 'string' || token.length > 8192) return null;
    const parts = token.split('.');
    if (parts.length !== 2) return null;
    const [payload, signature] = parts;

    const expected = Buffer.from(base64url(createHmac('sha256', key).update(payload).digest()));
    const given = Buffer.from(signature);
    if (expected.length !== given.length || !timingSafeEqual(expected, given)) return null;

    let data;
    try {
        data = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
    } catch {
        return null;
    }
    if (!data || data.v !== 1 || data.typ !== 'live' || !Number.isInteger(data.sub) || data.sub < 1
        || typeof data.name !== 'string' || !(data.avatar === null || typeof data.avatar === 'string')
        || !Array.isArray(data.ch) || data.ch.length > MAX_TOKEN_CHANNELS
        || !data.ch.every((channel) => typeof channel === 'string' && CHANNEL.test(channel))
        || !Number.isInteger(data.iat) || !Number.isInteger(data.exp) || data.exp <= now) {
        return null;
    }
    return data;
}

/** Test helper / reference implementation of LiveToken::issue(). */
export function signLiveToken(payload, key) {
    const body = base64url(JSON.stringify(payload));
    return `${body}.${base64url(createHmac('sha256', key).update(body).digest())}`;
}
