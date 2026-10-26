// Connection tokens + keys of the Brainitive realtime server (BRAI-54).
// Mirrors app/Support/Collab/CollabToken.php and CollabConfig.php — keep them in sync.
import { createHmac, timingSafeEqual } from 'node:crypto';

/** The shared secret: COLLAB_SECRET, else derived from APP_KEY (same rule as config/collab.php). */
export function resolveSecret(env) {
    if (env.COLLAB_SECRET) return env.COLLAB_SECRET;
    if (env.APP_KEY) return createHmac('sha256', env.APP_KEY).update('brainitive-collab').digest('hex');
    return null;
}

/** Browser tokens and the internal API use different keys, both derived from the secret. */
export function deriveKeys(secret) {
    return {
        token: createHmac('sha256', secret).update('collab-token').digest(),
        internal: createHmac('sha256', secret).update('collab-internal').digest('hex'),
    };
}

const base64url = (buffer) => Buffer.from(buffer).toString('base64url');

/** `page.<uuid>.<epoch>` (documents) and `whiteboard.<uuid>.<epoch>` (BRAI-153) — the only documents this server opens. */
export const DOCUMENT_NAME = /^(?:page|whiteboard)\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.\d{1,9}$/;

/**
 * The payload of a valid, unexpired token, else null.
 * @returns {{v: 1, sub: number, name: string, doc: string, edit: boolean, exp: number} | null}
 */
export function verifyToken(token, key, now = Math.floor(Date.now() / 1000)) {
    if (typeof token !== 'string' || token.length > 4096) return null;
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
    if (!data || data.v !== 1 || !Number.isInteger(data.sub) || typeof data.doc !== 'string' || typeof data.edit !== 'boolean'
        || !Number.isInteger(data.exp) || data.exp <= now) {
        return null;
    }
    return data;
}

/** Test helper / reference implementation of CollabToken::issue(). */
export function signToken(payload, key) {
    const body = base64url(JSON.stringify(payload));
    return `${body}.${base64url(createHmac('sha256', key).update(body).digest())}`;
}
