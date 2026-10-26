// Entry point: `npm run collab` (dev, reads .env), `node realtime/src/index.js` (systemd / Docker).
import { createCollabServer } from './server.js';
import { resolveSecret } from './token.js';

const env = process.env;
const port = Number(env.COLLAB_PORT ?? 1234);
const address = env.COLLAB_HOST ?? '127.0.0.1';

const collab = createCollabServer({
    port,
    address,
    secret: resolveSecret(env),
    // where the realtime server reaches Brainitive's internal API (not the browser URL)
    appUrl: env.COLLAB_APP_URL || env.APP_URL,
    accessIntervalMs: Number(env.COLLAB_ACCESS_INTERVAL_MS ?? 60_000),
});

await collab.listen();
console.log(`[realtime] Brainitive live editing on ${address}:${port} → ${env.COLLAB_APP_URL || env.APP_URL}`);
