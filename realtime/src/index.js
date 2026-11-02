// Entry point: `npm run collab` (dev, reads .env), `node realtime/src/index.js` (systemd / Docker).
import fs from 'node:fs';
import { attachLive } from './live/index.js';
import { createCollabServer } from './server.js';
import { resolveSecret } from './token.js';

const env = process.env;

/**
 * COLLAB_SECRET_FILE (Docker): the app container writes the shared secret there, so this process never
 * needs the app's .env (APP_KEY, database, mail …). Wait for it on a fresh install.
 */
async function secretFromFile(file) {
    for (let attempt = 0; attempt < 90; attempt++) {
        try {
            const value = fs.readFileSync(file, 'utf8').trim();
            if (value) return value;
        } catch {
            /* not written yet */
        }
        await new Promise((resolve) => setTimeout(resolve, 2_000));
    }
    throw new Error(`no secret in ${file} — is the app container running?`);
}
const port = Number(env.COLLAB_PORT ?? 1234);
const address = env.COLLAB_HOST ?? '127.0.0.1';

const secret = env.COLLAB_SECRET_FILE ? await secretFromFile(env.COLLAB_SECRET_FILE) : resolveSecret(env);
// where the realtime server reaches Brainitive's internal API (not the browser URL)
const appUrl = env.COLLAB_APP_URL || env.APP_URL;
// BRAI-345: call the app on loopback (COLLAB_APP_URL=https://127.0.0.1) under its public name — for servers
// that can't reach their own public IP (firewall, no hairpin NAT). See realtime/src/appFetch.js.
const appHost = env.COLLAB_APP_HOST ?? '';
const accessIntervalMs = Number(env.COLLAB_ACCESS_INTERVAL_MS ?? 60_000);
/** a positive integer from the environment, else the default */
const positive = (value, fallback) => (Number.isInteger(Number(value)) && Number(value) > 0 ? Number(value) : fallback);
// Codex realtime review 2: re-checks of a document that may fail in a row before its connections close (fail closed)
const maxAccessFailures = positive(env.COLLAB_ACCESS_MAX_FAILURES, 2);

// Codex realtime review 4: document sockets per user, messages per socket, message size (DEFAULT_COLLAB_LIMITS)
const limits = Object.fromEntries(Object.entries({
    maxSocketsPerUser: positive(env.COLLAB_MAX_SOCKETS_PER_USER, null),
    messageBurst: positive(env.COLLAB_MESSAGE_BURST, null),
    messagesPerSecond: positive(env.COLLAB_MESSAGES_PER_SECOND, null),
    maxMessageBytes: positive(env.COLLAB_MAX_MESSAGE_BYTES, null),
}).filter(([, value]) => value !== null));

// Codex realtime review 5: the largest document Brainitive stores — the app's COLLAB_MAX_STATE_BYTES (same name, same
// default); the app's access answers carry its value (max_state_bytes), so the two can't drift for long
const maxStateBytes = positive(env.COLLAB_MAX_STATE_BYTES, undefined);

const collab = createCollabServer({ port, address, secret, appUrl, appHost, accessIntervalMs, maxAccessFailures, limits, maxStateBytes });

// Live channels (BRAI-151) on the same port: `?live=1` sockets + Brainitive's publish endpoint.
// LIVE_PUBLISH_FROM=private (Docker): the app container publishes over the private network.
// A revoked app device (POST /internal/live/devices/revoked) loses its document sockets too (BRAI-377).
attachLive(collab.server, {
    secret,
    appUrl,
    appHost,
    accessIntervalMs,
    publishFrom: env.LIVE_PUBLISH_FROM === 'private' ? 'private' : 'loopback',
    onDevicesRevoked: (ids) => collab.revokeDevices(ids),
    // a signed-out browser session (POST /internal/live/sessions/ended) loses its document sockets too
    onSessionsEnded: (ended) => collab.endSessions(ended),
});

await collab.listen();
console.log(`[realtime] Brainitive live editing + live channels on ${address}:${port} → app API ${appUrl}${appHost ? ` (Host ${appHost})` : ''}`);
