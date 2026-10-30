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

const collab = createCollabServer({ port, address, secret, appUrl, appHost, accessIntervalMs });

// Live channels (BRAI-151) on the same port: `?live=1` sockets + Brainitive's publish endpoint.
// LIVE_PUBLISH_FROM=private (Docker): the app container publishes over the private network.
attachLive(collab.server, { secret, appUrl, appHost, accessIntervalMs, publishFrom: env.LIVE_PUBLISH_FROM === 'private' ? 'private' : 'loopback' });

await collab.listen();
console.log(`[realtime] Brainitive live editing + live channels on ${address}:${port} → app API ${appUrl}${appHost ? ` (Host ${appHost})` : ''}`);
